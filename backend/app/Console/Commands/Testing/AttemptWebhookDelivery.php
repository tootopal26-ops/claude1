<?php

namespace App\Console\Commands\Testing;

use App\Domain\Payments\DepositService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Simulates one gateway webhook delivery for a payment.success event.
 * Several of these fired concurrently, with identical arguments, is
 * exactly what a gateway's retried/duplicated delivery looks like — see
 * the concurrency test proving DepositService::handleWebhook() credits
 * the wallet exactly once no matter how many of these land at once.
 */
#[Signature('app:attempt-webhook-delivery {orderId} {amount}')]
#[Description('Deliver a payment.success webhook for an order')]
class AttemptWebhookDelivery extends Command
{
    public function handle(DepositService $deposits): int
    {
        $payload = json_encode([
            'order_id' => $this->argument('orderId'),
            'event' => 'payment.success',
            'utr' => 'SIMULATEDUTR',
            'amount' => $this->argument('amount'),
        ]);

        $signature = hash_hmac('sha256', $payload, (string) config('services.alpha_upi.webhook_secret'));

        $deposits->handleWebhook($payload, $signature);

        $this->line('DELIVERED');

        return self::SUCCESS;
    }
}
