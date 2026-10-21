<?php

namespace App\Domain\Payments;

use App\Domain\Notifications\NotificationService;
use App\Domain\Wallet\WalletService;
use App\Models\AuditLog;
use App\Models\DepositOrder;
use App\Models\PaymentWebhookEvent;
use App\Models\RiskAlert;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates a deposit end to end: start an order with the gateway,
 * apply a webhook delivery exactly once, and reconcile via check-status
 * as the secure server-side fallback — the provider's own docs warn that
 * the browser callback redirect can be forged and must never be trusted
 * alone, which both handleWebhook() and reconcile() honor by always
 * confirming server-side before crediting anything.
 */
class DepositService
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly WalletService $wallet,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @throws ValidationException
     */
    public function initiate(User $user, string $amount): DepositOrder
    {
        if (! is_numeric($amount) || bccomp($amount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Deposit amount must be greater than zero.',
            ]);
        }

        $orderId = 'MM'.now()->format('Ymd').Str::upper(Str::random(8));

        $order = DepositOrder::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'our_order_id' => $orderId,
            'provider' => $this->gateway->providerName(),
            'status' => 'pending',
        ]);

        try {
            $result = $this->gateway->createOrder($orderId, $amount, $user->username, $user->mobile);
        } catch (PaymentGatewayException $exception) {
            $order->status = 'failed';
            $order->save();

            throw ValidationException::withMessages([
                'amount' => "Could not start the deposit right now: {$exception->getMessage()}",
            ]);
        }

        $order->payment_url = $result['payment_url'];
        $order->provider_token = $result['token'];
        $order->expires_at = $result['expires_at'];
        $order->raw_create_response = $result['raw'];
        $order->save();

        AuditLog::record('deposit.initiated', $user, $order, ['amount' => $amount]);

        return $order;
    }

    /**
     * @throws PaymentGatewayException
     */
    public function handleWebhook(string $rawPayload, string $signature): void
    {
        if (! $this->gateway->verifyWebhookSignature($rawPayload, $signature)) {
            throw new PaymentGatewayException('Webhook signature verification failed.');
        }

        $payload = json_decode($rawPayload, true);

        if (! is_array($payload) || ! isset($payload['order_id'])) {
            throw new PaymentGatewayException('Webhook payload is missing order_id.');
        }

        $orderId = (string) $payload['order_id'];
        $eventType = (string) ($payload['event'] ?? 'unknown');

        try {
            PaymentWebhookEvent::create([
                'provider' => $this->gateway->providerName(),
                'order_id' => $orderId,
                'event_type' => $eventType,
                'payload' => $payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            return; // Already processed this exact event — duplicate delivery.
        }

        $order = DepositOrder::where('our_order_id', $orderId)->first();

        if ($order === null) {
            return; // Webhook for an order we don't recognize.
        }

        if ($eventType === 'payment.success') {
            $this->markPaid(
                $order,
                isset($payload['utr']) ? (string) $payload['utr'] : null,
                null,
                isset($payload['amount']) ? number_format((float) $payload['amount'], 2, '.', '') : null,
            );
        }
    }

    public function reconcile(DepositOrder $order): DepositOrder
    {
        if ($order->status !== 'pending') {
            return $order;
        }

        $status = $this->gateway->checkStatus($order->our_order_id);

        if ($status['payment_status'] === 'success') {
            $this->markPaid($order, $status['utr'], $status['gateway_txn_id'], $status['amount']);
        } elseif (in_array($status['payment_status'], ['expired', 'failed'], true)) {
            $locked = DepositOrder::query()->lockForUpdate()->find($order->id);

            if ($locked !== null && $locked->status === 'pending') {
                $locked->status = $status['payment_status'];
                $locked->save();
            }
        }

        return $order->fresh();
    }

    private function markPaid(DepositOrder $order, ?string $utr, ?string $gatewayTxnId, ?string $reportedAmount): void
    {
        $credited = DB::transaction(function () use ($order, $utr, $gatewayTxnId, $reportedAmount) {
            $locked = DepositOrder::query()->lockForUpdate()->find($order->id);

            if ($locked === null || $locked->status === 'success') {
                return false;
            }

            if ($reportedAmount !== null && bccomp($reportedAmount, (string) $locked->amount, 2) !== 0) {
                RiskAlert::create([
                    'type' => 'deposit_amount_mismatch',
                    'risk_level' => 'high',
                    'target_user_id' => $locked->user_id,
                    'details' => [
                        'deposit_order_id' => $locked->id,
                        'expected_amount' => (string) $locked->amount,
                        'reported_amount' => $reportedAmount,
                    ],
                ]);
            }

            $locked->status = 'success';
            $locked->utr = $utr;
            $locked->gateway_txn_id = $gatewayTxnId;
            $locked->paid_at = now();
            $locked->save();

            // Always credit the amount WE recorded when the order was
            // created — never whatever the provider's webhook/status
            // response reports — see the mismatch alert above.
            $this->wallet->creditDeposit(
                $locked->user,
                (string) $locked->amount,
                "deposit.credit:{$locked->our_order_id}",
                $locked,
                'UPI deposit',
            );

            AuditLog::record('deposit.paid', null, $locked, ['utr' => $utr]);

            return true;
        });

        if ($credited) {
            $order->refresh();

            $this->notifications->notify(
                recipients: [$order->user],
                type: 'deposit.success',
                title: 'Deposit successful',
                body: "Your deposit of {$order->amount} coins is now in your wallet.",
                data: ['deposit_order_id' => (string) $order->id],
                idempotencyKey: "deposit.notify:{$order->id}",
            );
        }
    }
}
