<?php

namespace Tests\Feature\Redeem;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Real concurrency: separate PHP processes hit one MySQL database at the same
 * moment (an in-process test can't race itself). Same approach as the tournament
 * join and wallet concurrency tests.
 */
class RedeemConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const ENV = ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'minimax_testing'];

    /**
     * @return array{campaign_id: int, code: string, user_ids: list<int>}
     */
    private function prepare(int $capacity, int $players): array
    {
        if (! Process::run('mysql --version')->successful()) {
            $this->markTestSkipped('mysql client not available to prepare the concurrency fixture.');
        }

        $migrate = Process::env(self::ENV)->path(base_path())->run('php artisan migrate:fresh --no-interaction');
        $this->assertTrue($migrate->successful(), $migrate->errorOutput());

        $seed = Process::env(self::ENV)->path(base_path())->run("php artisan app:seed-redeem-concurrency-fixture {$capacity} {$players} 100");
        $this->assertTrue($seed->successful(), $seed->errorOutput());

        return json_decode(trim($seed->output()), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<int>  $userIds
     * @return list<string>
     */
    private function race(string $code, array $userIds): array
    {
        $results = Process::pool(function ($pool) use ($code, $userIds) {
            foreach ($userIds as $index => $userId) {
                $pool->as("{$userId}-{$index}")
                    ->env(self::ENV)
                    ->path(base_path())
                    ->command("php artisan app:attempt-redeem-code {$userId} {$code}");
            }
        })->start()->wait();

        return collect($results)->map(fn ($result) => trim($result->output()))->values()->all();
    }

    private function db(): Connection
    {
        config(['database.connections.concurrency_check' => array_merge(config('database.connections.mysql'), ['database' => 'minimax_testing'])]);
        DB::purge('concurrency_check');

        return DB::connection('concurrency_check');
    }

    public function test_concurrent_redemptions_never_exceed_the_campaign_capacity(): void
    {
        $fixture = $this->prepare(capacity: 3, players: 10);

        $outcomes = $this->race($fixture['code'], $fixture['user_ids']);

        $this->assertCount(3, array_filter($outcomes, fn ($line) => $line === 'REDEEMED'), implode(' | ', $outcomes));
        $this->assertCount(7, array_filter($outcomes, fn ($line) => $line === 'REJECTED:limit_reached'), implode(' | ', $outcomes));

        $db = $this->db();
        $this->assertSame(3, (int) $db->table('redeem_campaigns')->where('id', $fixture['campaign_id'])->value('redeemed_count'));
        $this->assertSame(3, $db->table('redeem_redemptions')->count());
        $this->assertSame(3, $db->table('wallet_ledger_entries')->where('type', 'deposit')->count(), 'exactly one credit per successful redemption');
        $this->assertSame('300.00', number_format((float) $db->table('wallets')->sum('deposit_balance'), 2, '.', ''), 'exactly capacity × reward was credited in total');
    }

    public function test_one_player_firing_many_requests_at_once_is_credited_only_once(): void
    {
        $fixture = $this->prepare(capacity: 50, players: 1);
        $player = $fixture['user_ids'][0];

        $outcomes = $this->race($fixture['code'], array_fill(0, 8, $player));

        $this->assertCount(1, array_filter($outcomes, fn ($line) => $line === 'REDEEMED'), implode(' | ', $outcomes));
        $this->assertCount(7, array_filter($outcomes, fn ($line) => $line === 'REJECTED:already_redeemed'), implode(' | ', $outcomes));

        $db = $this->db();
        $this->assertSame(1, $db->table('redeem_redemptions')->where('user_id', $player)->count());
        $this->assertSame(1, $db->table('wallet_ledger_entries')->where('user_id', $player)->where('type', 'deposit')->count());
        $this->assertSame('100.00', number_format((float) $db->table('wallets')->where('user_id', $player)->value('deposit_balance'), 2, '.', ''));
    }
}
