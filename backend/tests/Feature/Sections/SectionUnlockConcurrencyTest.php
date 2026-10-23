<?php

namespace Tests\Feature\Sections;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Real concurrency: separate PHP processes hit one MySQL database at the same
 * moment (an in-process test can't race itself). Same approach as the redeem,
 * tournament join and wallet concurrency tests.
 */
class SectionUnlockConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const ENV = ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'minimax_testing'];

    /**
     * @return list<int>
     */
    private function prepare(int $players, int $balance, int $price): array
    {
        if (! Process::run('mysql --version')->successful()) {
            $this->markTestSkipped('mysql client not available to prepare the concurrency fixture.');
        }

        $migrate = Process::env(self::ENV)->path(base_path())->run('php artisan migrate:fresh --no-interaction');
        $this->assertTrue($migrate->successful(), $migrate->errorOutput());

        $seed = Process::env(self::ENV)->path(base_path())->run("php artisan app:seed-section-unlock-concurrency-fixture {$players} {$balance} {$price}");
        $this->assertTrue($seed->successful(), $seed->errorOutput());

        return json_decode(trim($seed->output()), true, flags: JSON_THROW_ON_ERROR)['user_ids'];
    }

    /**
     * @param  list<array{0: int, 1: string}>  $attempts  [user id, section key]
     * @return list<string>
     */
    private function race(array $attempts): array
    {
        $results = Process::pool(function ($pool) use ($attempts) {
            foreach ($attempts as $index => [$userId, $section]) {
                $pool->as("{$userId}-{$section}-{$index}")
                    ->env(self::ENV)
                    ->path(base_path())
                    ->command("php artisan app:attempt-section-unlock {$userId} {$section}");
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

    public function test_one_player_firing_many_unlock_requests_at_once_is_charged_exactly_once(): void
    {
        [$player] = $this->prepare(players: 1, balance: 500, price: 100);

        $outcomes = $this->race(array_fill(0, 8, [$player, 'leaderboard']));

        $this->assertCount(1, array_filter($outcomes, fn ($line) => $line === 'UNLOCKED'), implode(' | ', $outcomes));
        $this->assertCount(7, array_filter($outcomes, fn ($line) => $line === 'ALREADY'), implode(' | ', $outcomes));

        $db = $this->db();
        $this->assertSame(1, $db->table('section_unlocks')->where('user_id', $player)->count());
        $this->assertSame(1, $db->table('wallet_ledger_entries')->where('user_id', $player)->where('type', 'section_unlock')->count(), 'one ledger charge, not eight');
        $this->assertSame('400.00', number_format((float) $db->table('wallets')->where('user_id', $player)->value('deposit_balance'), 2, '.', ''));
    }

    public function test_two_different_purchases_cannot_spend_the_same_coins_twice(): void
    {
        // 150 coins, two sections at 100 each: only one purchase can be afforded.
        [$player] = $this->prepare(players: 1, balance: 150, price: 100);

        $outcomes = $this->race([[$player, 'leaderboard'], [$player, 'arena'], [$player, 'leaderboard'], [$player, 'arena']]);

        $this->assertCount(1, array_filter($outcomes, fn ($line) => $line === 'UNLOCKED'), implode(' | ', $outcomes));
        $this->assertCount(0, array_filter($outcomes, fn ($line) => $line === 'REJECTED:unknown_section'), implode(' | ', $outcomes));

        $db = $this->db();
        $this->assertSame(1, $db->table('section_unlocks')->where('user_id', $player)->count());
        $this->assertSame('50.00', number_format((float) $db->table('wallets')->where('user_id', $player)->value('deposit_balance'), 2, '.', ''), 'never negative, charged once');
        $this->assertSame(0, $db->table('wallets')->where('deposit_balance', '<', 0)->count());
    }
}
