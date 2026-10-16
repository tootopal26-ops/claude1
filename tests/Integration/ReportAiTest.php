<?php
declare(strict_types=1);

use App\Ai\AiProviderInterface;
use App\Ai\AiResult;
use App\Core\Db;
use App\Core\HttpException;
use App\Services\AiService;
use App\Services\DailyReport;
use App\Services\ReportAi;

/**
 * Optional AI commentary on an already-built daily report. The deterministic figures are never read from or written by this —
 * ReportAi only ever UPDATEs the `ai_*` columns of a row DailyReport already wrote. A reply that is not strict JSON in the
 * expected shape is `invalid` and nothing is shown; a reply the provider could not give at all is `unavailable`; either way the
 * report itself (and every number in it) is completely unaffected.
 */
function rai_owner(): array
{
    return ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner'];
}

function rai_on(): void
{
    AiService::saveSettings(['enabled' => true, 'provider' => 'anthropic', 'model' => 'claude-x', 'api_key' => 'k', 'use_for_reports' => true], rai_owner());
    AiService::$providerFactory = static fn(array $s): AiProviderInterface => new AiFakeProvider();
}

T::test('report AI: off by default (nothing is attempted); once on, a valid reply is stored, an invalid one is not, and neither ever touches the figures', function () {
    dr_reset();
    $day = dr_yesterday();
    an_order('MANUAL', 10000, 0, $day);
    DailyReport::generate($day);
    $before = DailyReport::get($day)['snapshot'];

    $r = ReportAi::summarize($day, rai_owner());
    T::same('none', $r['status']);
    T::contains('not configured', $r['message']);
    T::same('none', DailyReport::get($day)['ai']['status']);

    // switched on, but not for reports specifically
    AiService::saveSettings(['enabled' => true, 'provider' => 'anthropic', 'model' => 'claude-x', 'api_key' => 'k'], rai_owner());
    $r = ReportAi::summarize($day, rai_owner());
    T::same('none', $r['status']);
    T::contains('switched off for reports', $r['message']);

    rai_on();
    AiFakeProvider::$next = new AiResult(AiResult::OK, text: "Here you go:\n```json\n" . json_encode([
        'summary' => 'A quiet day with one manual order.', 'changes' => ['Only one order came in.'], 'causes' => ['Possibly a slow weekday — correlation, not a confirmed cause.'],
        'next_steps' => ['Keep an eye on tomorrow’s numbers.'], 'uncertainties' => ['Too little data to say much with confidence.'],
    ]) . "\n```");
    $r = ReportAi::summarize($day, rai_owner());
    T::same(['ok', 'AI commentary added.'], [$r['status'], $r['message']]);
    $ai = DailyReport::get($day)['ai'];
    T::same(['ok', 'anthropic', 'claude-x'], [$ai['status'], $ai['provider'], $ai['model']]);
    $stored = json_decode((string) $ai['summary'], true);
    T::same('A quiet day with one manual order.', $stored['summary']);
    T::same(['Only one order came in.'], $stored['changes']);
    T::true($ai['generated_at'] !== null);
    T::same($before, DailyReport::get($day)['snapshot'], 'the figures themselves are byte-for-byte unchanged');
    T::true((int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'report.ai_generated'") === 1);

    // a reply that is not our JSON shape at all
    AiFakeProvider::$next = new AiResult(AiResult::OK, text: 'Sorry, I cannot help with that.');
    $r = ReportAi::summarize($day, rai_owner());
    T::same('invalid', $r['status']);
    $ai = DailyReport::get($day)['ai'];
    T::same(['invalid', null], [$ai['status'], $ai['summary']], 'the previous good summary is not left stale next to a status that says it failed');

    // the provider could not be reached at all
    AiFakeProvider::$next = new AiResult(AiResult::UNREACHABLE, error: 'timed out');
    $r = ReportAi::summarize($day, rai_owner());
    T::same('unavailable', $r['status']);
    T::same('unavailable', DailyReport::get($day)['ai']['status']);
});

T::test('report AI: a reply with an oversized or wrongly-shaped field is rejected, not trimmed and shown anyway', function () {
    dr_reset();
    $day = dr_yesterday();
    an_order('MANUAL', 10000, 0, $day);
    DailyReport::generate($day);
    rai_on();

    foreach ([
        ['summary' => ''],                                                                   // empty summary
        ['summary' => str_repeat('x', 800)],                                                  // too long
        ['summary' => 'ok', 'changes' => 'not a list'],                                       // wrong type
        ['summary' => 'ok', 'changes' => array_fill(0, 3, str_repeat('y', 300))],              // item too long
        ['summary' => 'ok', 'changes' => array_fill(0, 20, 'x')],                              // too many — still invalid if any one item were bad, but a long list alone is just truncated server-side, so this one is NOT rejected
    ] as $i => $payload) {
        AiFakeProvider::$next = new AiResult(AiResult::OK, text: json_encode($payload));
        $r = ReportAi::summarize($day, rai_owner());
        if ($i === 4) {
            T::same('ok', $r['status'], 'a too-long list is simply capped, not rejected');
        } else {
            T::same('invalid', $r['status'], "case $i");
        }
    }

    // no JSON at all in the reply
    AiFakeProvider::$next = new AiResult(AiResult::OK, text: 'just some prose, no braces here');
    T::same('invalid', ReportAi::summarize($day, rai_owner())['status']);
});

T::test('report AI endpoint: needs reports.generate, is idempotent per click, and 404s a day with no report', function () {
    dr_reset();
    $day = dr_yesterday();
    an_order('MANUAL', 10000, 0, $day);
    DailyReport::generate($day);
    rai_on();
    AiFakeProvider::$next = new AiResult(AiResult::OK, text: json_encode(['summary' => 'fine']));
    $ctl = new App\Controllers\Admin\ReportsController();
    $req = static fn(string $role, array $h = []): App\Core\Request => la_req('POST', "/admin/api/reports/{$GLOBALS['raiDay']}/ai", $role, [], [], $h);
    $GLOBALS['raiDay'] = $day;

    foreach (['analyst', 'support'] as $role) {
        T::throws(HttpException::class, fn() => $ctl->generateAi($req($role), ['date' => $day]), 403);
    }
    $body = json_decode((string) $ctl->generateAi($req('owner', ['Idempotency-Key' => 'rai-key-1']), ['date' => $day])->body, true);
    T::same('ok', $body['result']['status']);
    $replay = $ctl->generateAi($req('owner', ['Idempotency-Key' => 'rai-key-1']), ['date' => $day]);
    T::same('true', $replay->headers['X-Idempotent-Replay'] ?? null);

    T::throws(HttpException::class, fn() => $ctl->generateAi($req('owner'), ['date' => '2020-01-01']), 404);
});
