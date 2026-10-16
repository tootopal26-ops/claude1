<?php
declare(strict_types=1);

use App\Ai\AiProviderInterface;
use App\Ai\AiResult;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\AiService;
use App\Services\Settings;
use App\Services\SupportAssistant;
use App\Support\Clock;
use App\Support\Status;

/**
 * The rules-based support assistant (master prompt §7). The one thing every other test here is really checking: a reply that
 * carries a specific order's facts is decided ONLY by Status::customerLabel + Orders::customerView (the same code the order page
 * itself uses) and is NEVER handed to AI — proven by wiring a fake AI provider that would answer something else entirely and
 * showing the order-specific reply is unchanged regardless. A bare order id or a wrong/foreign token is never accepted: only the
 * 128-bit access token proves ownership, exactly like the order tracking page.
 */
function sa_reset(): void
{
    an_reset();
    AiService::$providerFactory = null;
}

function sa_req(string $method, string $path, string $ip = '203.0.113.9'): Request
{
    return Request::fake($method, $path, ['Content-Type' => 'application/json', 'X-Requested-With' => 'smb', 'Origin' => 'http://127.0.0.1:8080'], '', [], $ip);
}

function sa_start(string $ip = '203.0.113.9'): string
{
    return SupportAssistant::start(sa_req('POST', '/api/support/start', $ip))['key'];
}

function sa_token(int $orderId): string
{
    return (string) Db::val('SELECT access_token FROM orders WHERE id = ?', [$orderId]);
}

T::test('starting a chat: a fresh 32-hex key, respects the retention setting, and is rate-limited per IP', function () {
    sa_reset();
    $r = SupportAssistant::start(sa_req('POST', '/api/support/start'));
    T::same(1, preg_match('/^[a-f0-9]{32}$/', $r['key']));
    T::same(30, $r['retention_days'], 'the default');
    $row = Db::one('SELECT status, message_count, expires_at FROM support_conversations WHERE public_key = ?', [$r['key']]);
    T::same(['open', 0], [$row['status'], (int) $row['message_count']]);
    T::same(30, (int) round((Clock::fromSql($row['expires_at']) - Clock::now()) / 86400));

    SupportAssistant::saveSettings(['retention_days' => 5], ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner']);
    T::same(5, SupportAssistant::start(sa_req('POST', '/api/support/start'))['retention_days']);

    for ($i = 0; $i < 20; $i++) {
        SupportAssistant::start(sa_req('POST', '/api/support/start', '198.51.100.5'));
    }
    T::throws(HttpException::class, fn() => SupportAssistant::start(sa_req('POST', '/api/support/start', '198.51.100.5')), 429);
});

T::test('a question about "my order" is never answered without a verified token — and a wrong token is treated exactly like none, never another order’s data', function () {
    sa_reset();
    $completed = an_order('AUTO', 10000, 6000);
    $key = sa_start();

    $r = SupportAssistant::message(sa_req('POST', "/api/support/{$key}/message"), $key, 'what is my order status?', null);
    T::same(['order_status', 'rules', false], [$r['intent'], $r['mode'], $r['order_recognised']]);
    T::contains('paste the order link', $r['reply']);

    // a well-formed but unknown token — never resolves to someone else's order, never errors oddly
    $r = SupportAssistant::message(sa_req('POST', "/api/support/{$key}/message"), $key, 'payment status please', bin2hex(random_bytes(16)));
    T::same(false, $r['order_recognised']);
    T::contains('paste the order link', $r['reply']);

    $r = SupportAssistant::message(sa_req('POST', "/api/support/{$key}/message"), $key, 'has my order been delivered?', sa_token($completed));
    T::true($r['order_recognised']);
    $public = (string) Db::val('SELECT public_id FROM orders WHERE id = ?', [$completed]);
    T::contains($public, $r['reply']);
    T::contains(Status::customerLabel('COMPLETED'), $r['reply']);
});

T::test('an order-specific reply is NEVER handed to AI, even when AI is switched on and would answer differently — only order-free answers may be polished', function () {
    sa_reset();
    $failed = an_order('AUTO', 10000, 0, status: 'FAILED');
    $expired = an_order('AUTO', 10000, 0, status: 'EXPIRED');
    AiService::saveSettings(['enabled' => true, 'provider' => 'anthropic', 'model' => 'claude-x', 'api_key' => 'k', 'use_for_support' => true], ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner']);
    AiService::$providerFactory = static fn(array $s): AiProviderInterface => new AiFakeProvider();
    AiFakeProvider::$next = new AiResult(AiResult::OK, text: 'This is a totally different AI-invented answer that must never be shown for an order.');
    $key = sa_start();

    $r = SupportAssistant::message(sa_req('POST', "/api/support/{$key}/message"), $key, 'order status?', sa_token($failed));
    T::same('rules', $r['mode']);
    T::contains('already been notified', $r['reply']);
    T::false(str_contains($r['reply'], 'AI-invented'));

    $r = SupportAssistant::message(sa_req('POST', "/api/support/{$key}/message"), $key, 'my payment session expired, what now', sa_token($expired));
    T::same('rules', $r['mode']);
    T::contains('cannot be reused', $r['reply']);

    // an order-free question CAN be polished …
    AiFakeProvider::$next = new AiResult(AiResult::OK, text: 'Look for the SMB- code on your payment page, or your saved Your Orders list.');
    $r = SupportAssistant::message(sa_req('POST', "/api/support/{$key}/message"), $key, 'where is my order id', null);
    T::same('ai', $r['mode']);
    T::same('Look for the SMB- code on your payment page, or your saved Your Orders list.', $r['reply']);

    // … but never with an invented link the deterministic answer never gave
    AiFakeProvider::$next = new AiResult(AiResult::OK, text: 'Visit https://not-our-site.example/help for more.');
    $r = SupportAssistant::message(sa_req('POST', "/api/support/{$key}/message"), $key, 'where is my order id', null);
    T::same('rules', $r['mode'], 'the invented link is rejected and the safe deterministic text is used instead');
    T::false(str_contains($r['reply'], 'not-our-site'));

    // and never when the provider itself fails
    AiFakeProvider::$next = new AiResult(AiResult::UNREACHABLE, error: 'down');
    $r = SupportAssistant::message(sa_req('POST', "/api/support/{$key}/message"), $key, 'how do I pay', null);
    T::same('rules', $r['mode']);
    T::contains('UPI', $r['reply']);
});

T::test('message rules: length is bounded, a closed or unknown chat is refused, the conversation closes itself after too many messages, and history replays the thread', function () {
    sa_reset();
    $key = sa_start();
    $e = T::throws(HttpException::class, fn() => SupportAssistant::message(sa_req('POST', "/api/support/{$key}/message"), $key, '', null), 422);
    T::true(isset($e->extra['fields']['text']));
    T::throws(HttpException::class, fn() => SupportAssistant::message(sa_req('POST', "/api/support/{$key}/message"), $key, str_repeat('a', 1001), null), 422);

    T::throws(HttpException::class, fn() => SupportAssistant::message(sa_req('POST', '/x'), 'not-a-real-key-at-all', 'hi', null), 404);
    T::throws(HttpException::class, fn() => SupportAssistant::message(sa_req('POST', '/x'), str_repeat('0', 32), 'hi', null), 404);

    SupportAssistant::message(sa_req('POST', "/api/support/{$key}/message"), $key, 'hello', null);
    $h = SupportAssistant::history(sa_req('GET', "/api/support/{$key}/history"), $key);
    T::same(['customer', 'assistant'], array_column($h, 'role'));
    T::same('hello', $h[0]['body']);

    Db::exec('UPDATE support_conversations SET status = ? WHERE public_key = ?', ['closed', $key]);
    T::throws(HttpException::class, fn() => SupportAssistant::message(sa_req('POST', "/api/support/{$key}/message"), $key, 'still there?', null), 410);

    $key2 = sa_start();
    Db::exec('UPDATE support_conversations SET message_count = 200 WHERE public_key = ?', [$key2]);
    $r = SupportAssistant::message(sa_req('POST', "/api/support/{$key2}/message"), $key2, 'one more', null);
    T::same('closed', $r['intent']);
    T::same('closed', Db::val('SELECT status FROM support_conversations WHERE public_key = ?', [$key2]));
});

T::test('a conversation past its expiry cannot be used, and settings/support.enabled are respected', function () {
    sa_reset();
    $key = sa_start();
    Db::exec('UPDATE support_conversations SET expires_at = ? WHERE public_key = ?', [Clock::sql(Clock::now() - 10), $key]);
    T::throws(HttpException::class, fn() => SupportAssistant::message(sa_req('POST', "/api/support/{$key}/message"), $key, 'hi', null), 404);

    Settings::set('support.enabled', false);
    T::throws(HttpException::class, fn() => SupportAssistant::start(sa_req('POST', '/api/support/start')), 404);
    Settings::set('support.enabled', true);

    $e = T::throws(HttpException::class, fn() => SupportAssistant::saveSettings(['retention_days' => 400], ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner']), 422);
    T::true(isset($e->extra['fields']['retention_days']));
});

T::test('admin monitoring: conversations and their transcripts are readable, counts match the database', function () {
    sa_reset();
    $k1 = sa_start();
    $k2 = sa_start();
    SupportAssistant::message(sa_req('POST', "/api/support/{$k1}/message"), $k1, 'hello there', null);
    $list = SupportAssistant::adminList();
    T::same(2, $list['total']);
    T::same([2, 2, 2], [$list['counts']['open'], $list['counts']['open'] + $list['counts']['closed'], $list['total']]);
    $id = (int) Db::val('SELECT id FROM support_conversations WHERE public_key = ?', [$k1]);
    $conv = SupportAssistant::adminConversation($id);
    T::same(2, count($conv['messages']));
    T::same(['customer', 'hello there'], [$conv['messages'][0]['role'], $conv['messages'][0]['body']]);
    T::throws(HttpException::class, fn() => SupportAssistant::adminConversation(999999), 404);
});
