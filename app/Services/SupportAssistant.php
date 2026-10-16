<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Support\Clock;
use App\Support\Status;

/**
 * A rules-based customer support assistant (master prompt §7) that works with no AI provider at all, and — only where it cannot
 * possibly be wrong — can ask AI to phrase its answer more warmly.
 *
 * The one rule everything else follows: **a message that carries a specific order's facts is never touched by AI.** Payment
 * status, order status and what an expired session means are decided entirely by App\Support\Status and App\Services\Orders
 * (exactly the same code and the same words as the order tracking page — they can never disagree), built only from an order the
 * customer proved they own by supplying its 128-bit access token (the same capability the order page itself requires; a public
 * order id alone proves nothing and is never accepted). Only the generic, order-free answers (where to find an order id, the
 * refund policy, delivery times in general, payment methods, a greeting) are ever candidates for AI rephrasing, and even then the
 * AI is given the already-decided answer and told only to phrase it more warmly — never to add a fact — and its reply is
 * discarded in favour of the plain answer if it is too long or invents a link.
 */
final class SupportAssistant
{
    private const MAX_MESSAGE_LEN = 1000;
    private const MAX_MESSAGES = 200;
    private const AI_SYSTEM = <<<'TXT'
        You are a calm, concise customer-support assistant for a small social-media growth-services shop. You will be given an
        answer that has ALREADY been decided and approved. Rephrase it in your own warm, brief words (at most 60 words), without
        changing what it means, and without adding any fact, price, policy, promise, refund, guarantee or detail that is not
        already in the given answer. If you are not sure how to rephrase it safely, repeat it closely instead. Reply with plain
        text only (no markdown, no JSON, no quotation marks around the whole reply).
        TXT;

    /** Whether the widget should be offered to customers at all (the owner can switch the whole thing off). */
    public static function enabled(): bool
    {
        return (bool) Settings::get('support.enabled', true);
    }

    private static function retentionDays(): int
    {
        return max(1, min(365, (int) Settings::get('support.retention_days', 30)));
    }

    /** @return array{key:string,retention_days:int} */
    public static function start(Request $req): array
    {
        if (!self::enabled()) {
            throw new HttpException(404, 'Support chat is not available right now.');
        }
        RateLimiter::enforce('support-start', $req->ip(), 20, 3600, 'Too many chat sessions started. Please wait a while and try again.');
        $visitor = Tracking::context($req)['visitor_id'] ?? null;
        $now = Clock::now();
        $days = self::retentionDays();
        $key = bin2hex(random_bytes(16));
        Db::insert('support_conversations', [
            'public_key' => $key, 'visitor_id' => $visitor, 'status' => 'open', 'message_count' => 0, 'ai_messages' => 0,
            'created_at' => Clock::sql($now), 'last_message_at' => Clock::sql($now), 'expires_at' => Clock::sql($now + $days * 86400),
        ]);
        return ['key' => $key, 'retention_days' => $days];
    }

    /**
     * @return array{reply:string,intent:string,mode:string,order_recognised:bool}
     */
    public static function message(Request $req, string $key, string $text, ?string $orderToken): array
    {
        if (!self::enabled()) {
            throw new HttpException(404, 'Support chat is not available right now.');
        }
        RateLimiter::enforce('support-message-ip', $req->ip(), 60, 600, 'Too many messages. Please wait a few minutes.');
        RateLimiter::enforce('support-message', $key, 20, 600, 'Too many messages in this chat. Please wait a few minutes.');
        $conv = self::conversation($key);
        $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', ' ', $text) ?? '');
        if ($text === '' || mb_strlen($text) > self::MAX_MESSAGE_LEN) {
            throw new HttpException(422, 'Enter a message of up to ' . self::MAX_MESSAGE_LEN . ' characters.', 'invalid', ['fields' => ['text' => 'Enter a message of up to ' . self::MAX_MESSAGE_LEN . ' characters.']]);
        }
        if ((int) $conv['message_count'] >= self::MAX_MESSAGES) {
            Db::update('support_conversations', ['status' => 'closed'], 'id = ?', [$conv['id']]);
            return ['reply' => 'This chat has had a lot of messages, so it is now closed. Please start a new chat if you still need help.', 'intent' => 'closed', 'mode' => 'rules', 'order_recognised' => false];
        }

        $order = $orderToken !== null && preg_match('/^[a-f0-9]{32}$/', $orderToken) === 1 ? Orders::byToken($orderToken) : null;
        $orderView = $order !== null ? Orders::customerView($order) : null;
        $intent = self::classifyIntent($text);
        [$reply, $aiEligible] = self::deterministicReply($intent, $orderView);

        $mode = 'rules';
        if ($aiEligible && AiService::available() && (bool) Settings::get('ai.use_for_support', false)) {
            $polished = self::tryAiPolish($reply, $text);
            if ($polished !== null) {
                $reply = $polished;
                $mode = 'ai';
            }
        }

        $now = Clock::sql();
        Db::insert('support_messages', ['conversation_id' => $conv['id'], 'role' => 'customer', 'body' => mb_substr($text, 0, 1200), 'intent' => $intent, 'order_id' => $order['id'] ?? null, 'mode' => 'rules', 'created_at' => $now]);
        Db::insert('support_messages', ['conversation_id' => $conv['id'], 'role' => 'assistant', 'body' => mb_substr($reply, 0, 1200), 'intent' => $intent, 'order_id' => $order['id'] ?? null, 'mode' => $mode, 'created_at' => $now]);
        Db::exec('UPDATE support_conversations SET message_count = message_count + 2, ai_messages = ai_messages + ?, last_message_at = ? WHERE id = ?', [$mode === 'ai' ? 1 : 0, $now, $conv['id']]);

        return ['reply' => $reply, 'intent' => $intent, 'mode' => $mode, 'order_recognised' => $order !== null];
    }

    /** @return list<array{role:string,body:string,created_at:?string}> */
    public static function history(Request $req, string $key): array
    {
        RateLimiter::enforce('support-history', $req->ip(), 60, 60);
        $conv = self::conversation($key);
        return array_map(
            static fn(array $m): array => ['role' => $m['role'], 'body' => $m['body'], 'created_at' => Clock::iso($m['created_at'])],
            Db::all('SELECT role, body, created_at FROM support_messages WHERE conversation_id = ? ORDER BY id', [$conv['id']])
        );
    }

    /** @return array<string,mixed> */
    private static function conversation(string $key): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            throw new HttpException(404, 'That chat could not be found.');
        }
        $conv = Db::one('SELECT * FROM support_conversations WHERE public_key = ?', [$key]);
        if ($conv === null || Clock::fromSql($conv['expires_at']) <= Clock::now()) {
            throw new HttpException(404, 'That chat could not be found. Start a new one.');
        }
        if ($conv['status'] !== 'open') {
            throw new HttpException(410, 'This chat is closed. Start a new one.');
        }
        return $conv;
    }

    private const ORDER_INTENTS = ['payment_status', 'order_status', 'expired_session'];

    private static function classifyIntent(string $text): string
    {
        $t = mb_strtolower($text);
        $any = static function (array $words) use ($t): bool {
            foreach ($words as $w) {
                if (str_contains($t, $w)) {
                    return true;
                }
            }
            return false;
        };
        if ($any(['expired', 'timed out', 'time out', 'session ended'])) {
            return 'expired_session';
        }
        if ($any(['order id', 'order number', 'order code', 'reference number', 'find my order', 'lost my order', 'smb-'])) {
            return 'find_order_id';
        }
        if ($any(['refund', 'money back', 'cancel my order', 'cancellation', 'cancel order'])) {
            return 'refund_policy';
        }
        if ($any(['paid', 'payment', 'upi', 'transaction', 'charged', 'debited', 'did you receive my money', 'money deducted'])) {
            return 'payment_status';
        }
        if ($any(['order status', 'my order', 'track', 'delivery progress', 'when will i get', 'how is my order', 'order update', 'still processing'])) {
            return 'order_status';
        }
        if ($any(['how long', 'delivery time', 'eta', 'how fast', 'how soon', 'how much time'])) {
            return 'delivery_time';
        }
        if ($any(['payment method', 'how do i pay', 'accepted payment', 'credit card', 'debit card', 'net banking', 'can i pay with'])) {
            return 'payment_methods';
        }
        if ($any(['human', 'real person', 'agent', 'contact support', 'talk to someone', 'contact you'])) {
            return 'general_contact';
        }
        if (preg_match('/^(hi|hello|hey|good (morning|afternoon|evening))\b/u', trim($t)) === 1 && mb_strlen($t) <= 40) {
            return 'greeting';
        }
        return 'unclear';
    }

    /** @return array{0:string,1:bool} [reply text, true if this reply carries no customer-specific facts and may be AI-polished] */
    private static function deterministicReply(string $intent, ?array $order): array
    {
        if ($order !== null && in_array($intent, self::ORDER_INTENTS, true)) {
            $label = Status::customerLabel((string) $order['status'], '');
            $base = 'Order ' . $order['order_id'] . ' (' . $order['service'] . '): ' . $label . '.';
            $extra = match (true) {
                $order['status'] === Status::EXPIRED => ' The payment link for an expired session cannot be reused — please place the order again if you still want it.',
                $order['status'] === Status::FAILED => ' Our team has already been notified and will follow up if anything further is needed from you.',
                $order['done'] => '',
                default => ' You can see the live status, including each step, on your order’s own page at any time.',
            };
            return [$base . $extra, false];
        }
        if (in_array($intent, self::ORDER_INTENTS, true)) {
            return ['I can check that once I can verify your order — please paste the order link you were given right after paying (or the code starting “SMB-” together with that link). I cannot look up an order from its code alone, to keep every order private.', false];
        }
        return match ($intent) {
            'find_order_id' => ['Your order code starts with “SMB-” and is shown on the payment page and on your order’s own tracking page — the link for that page is also saved in your browser under the small shop icon in the header (“Your orders”), as long as you use the same browser and device you ordered from.', true],
            'refund_policy' => ['Refunds are reviewed and handled by our team case by case — I can’t approve or process one from this chat myself.' . self::contactSuffix(), true],
            'delivery_time' => ['Orders delivered automatically usually begin within minutes of payment being verified; orders our team processes by hand are usually completed within a day. Your order’s own page always shows exactly where things stand.', true],
            'payment_methods' => ['We accept UPI payments through our payment gateway — there is no other payment method on this site, and no card or bank details are ever typed here.', true],
            'general_contact' => ['I can help with payment status, order status, where to find your order id, refunds and delivery times.' . self::contactSuffix(), true],
            'greeting' => ['Hello! I can help with payment status, order status, where to find your order id, our refund process, delivery times and payment methods — what would you like to know?', true],
            default => ['I did not quite catch that. I can help with: payment status, order status, where to find your order id, refunds, delivery times, or payment methods — could you rephrase, or ask about one of those?', true],
        };
    }

    private static function contactSuffix(): string
    {
        $link = (string) Settings::site()['support_link'];
        return $link !== '' ? ' For anything else: ' . $link : ' Please describe what you need and our team will follow up.';
    }

    /** Returns a polished replacement, or null to keep the deterministic text verbatim (AI unavailable, invalid, or it invented something). */
    private static function tryAiPolish(string $approved, string $customerText): ?string
    {
        $r = AiService::complete('support', self::AI_SYSTEM, 'Customer asked: "' . mb_substr($customerText, 0, 300) . '"' . "\nApproved answer to convey: \"" . $approved . '"', 220);
        if (!$r->ok()) {
            return null;
        }
        $text = trim($r->text);
        if ($text === '' || mb_strlen($text) > 500 || str_contains($text, '```') || str_contains($text, '{')) {
            return null;
        }
        preg_match_all('#https?://\S+#', $text, $found);
        foreach ($found[0] as $url) {
            if (!str_contains($approved, rtrim($url, '.,;)'))) {
                return null; // the model invented a link — never trust it
            }
        }
        return $text;
    }

    // ── admin monitoring (support.view) ──────────────────────────────────────

    /** @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,per:int,counts:array{open:int,closed:int,ai_messages:int,messages_today:int}} */
    public static function adminList(string $status = '', int $page = 1, int $per = 25): array
    {
        $where = $status !== '' ? 'WHERE status = ?' : '';
        $args = $status !== '' ? [$status] : [];
        $total = (int) Db::val("SELECT COUNT(*) FROM support_conversations {$where}", $args);
        $pages = max(1, (int) ceil($total / $per));
        $page = min(max(1, $page), $pages);
        $rows = Db::all(
            "SELECT id, status, message_count, ai_messages, created_at, last_message_at, expires_at FROM support_conversations {$where} ORDER BY last_message_at DESC LIMIT {$per} OFFSET " . (($page - 1) * $per),
            $args
        );
        return [
            'rows' => array_map(static fn(array $r): array => [
                'id' => (int) $r['id'], 'status' => $r['status'], 'message_count' => (int) $r['message_count'], 'ai_messages' => (int) $r['ai_messages'],
                'created_at' => Clock::iso($r['created_at']), 'last_message_at' => Clock::iso($r['last_message_at']), 'expires_at' => Clock::iso($r['expires_at']),
            ], $rows),
            'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per,
            'counts' => [
                'open' => (int) Db::val("SELECT COUNT(*) FROM support_conversations WHERE status = 'open'"),
                'closed' => (int) Db::val("SELECT COUNT(*) FROM support_conversations WHERE status = 'closed'"),
                'ai_messages' => (int) Db::val('SELECT COALESCE(SUM(ai_messages), 0) FROM support_conversations'),
                'messages_today' => (int) Db::val("SELECT COUNT(*) FROM support_messages WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY"),
            ],
        ];
    }

    public static function adminConversation(int $id): array
    {
        $conv = Db::one('SELECT id, status, message_count, ai_messages, created_at, last_message_at, expires_at FROM support_conversations WHERE id = ?', [$id]);
        if ($conv === null) {
            throw new HttpException(404, 'That conversation could not be found.');
        }
        $messages = Db::all('SELECT role, body, intent, mode, order_id, created_at FROM support_messages WHERE conversation_id = ? ORDER BY id', [$id]);
        return [
            'id' => (int) $conv['id'], 'status' => $conv['status'], 'message_count' => (int) $conv['message_count'], 'ai_messages' => (int) $conv['ai_messages'],
            'created_at' => Clock::iso($conv['created_at']), 'last_message_at' => Clock::iso($conv['last_message_at']), 'expires_at' => Clock::iso($conv['expires_at']),
            'messages' => array_map(static fn(array $m): array => [
                'role' => $m['role'], 'body' => $m['body'], 'intent' => $m['intent'], 'mode' => $m['mode'], 'order_id' => $m['order_id'] !== null ? (int) $m['order_id'] : null, 'created_at' => Clock::iso($m['created_at']),
            ], $messages),
        ];
    }

    /** @param array{id:int,username:string,email:string,role:string} $admin */
    public static function saveSettings(array $in, array $admin, ?Request $req = null): array
    {
        $errors = [];
        $set = [];
        if (array_key_exists('enabled', $in)) {
            if (!is_bool($in['enabled'])) {
                $errors['enabled'] = 'Choose on or off.';
            } else {
                $set['support.enabled'] = $in['enabled'];
            }
        }
        if (array_key_exists('retention_days', $in)) {
            $v = $in['retention_days'];
            if (!is_int($v) || $v < 1 || $v > 365) {
                $errors['retention_days'] = 'Allowed 1–365.';
            } else {
                $set['support.retention_days'] = $v;
            }
        }
        if ($errors) {
            throw new HttpException(422, 'Some of the settings are not valid.', 'invalid', ['fields' => $errors]);
        }
        if ($set) {
            Settings::setMany($set, $admin['id']);
        }
        Audit::log('support.settings_changed', 'settings', 'support', $set, $req, $admin);
        return ['enabled' => self::enabled(), 'retention_days' => self::retentionDays()];
    }
}
