<?php
// QA helper (local dev only): change the default SMM provider the way an admin would, so failure scenarios are repeatable.
//   php tools/qa/smm-provider.php --key=smm-key-bad          wrong API key  → provider answers "Invalid API key"
//   php tools/qa/smm-provider.php --key=smm-key-poor         no balance     → "Not enough funds on balance"
//   php tools/qa/smm-provider.php --key=smm-key-ok --active=1 --url=http://127.0.0.1:4020/api/v2   (healthy again)
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (!App\Core\Env::isLocal()) { fwrite(STDERR, "local only\n"); exit(1); }

use App\Core\{Crypto, Db};

$o = getopt('', ['key::', 'active::', 'url::']);
$set = ['updated_at' => App\Support\Clock::sql()];
if (isset($o['key'])) { $set['api_key_enc'] = Crypto::encrypt((string) $o['key']); }
if (isset($o['active'])) { $set['is_active'] = (int) $o['active']; }
if (isset($o['url'])) { $set['api_url'] = (string) $o['url']; }
Db::update('smm_providers', $set, 'is_default = 1');
echo "provider updated: " . implode(', ', array_keys($set)) . "\n";
