<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Db;

/**
 * Applies database/migrations/*.sql in order and records them in
 * schema_migrations. Used by bin/migrate.php, the web installer and the tests.
 */
final class Migrator
{
    /** @return list<string> versions applied during this call */
    public static function run(?string $dir = null): array
    {
        $dir ??= SMB_ROOT . '/database/migrations';
        Db::exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) NOT NULL, applied_at DATETIME NOT NULL, PRIMARY KEY (version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $done = array_flip(Db::col('SELECT version FROM schema_migrations'));
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);

        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (isset($done[$version])) {
                continue;
            }
            foreach (self::splitStatements((string) file_get_contents($file)) as $sql) {
                Db::pdo()->exec($sql);
            }
            Db::exec('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)', [$version, Clock::sql()]);
            $applied[] = $version;
        }
        return $applied;
    }

    /** @return list<string> */
    public static function pending(?string $dir = null): array
    {
        $dir ??= SMB_ROOT . '/database/migrations';
        try {
            $done = array_flip(Db::col('SELECT version FROM schema_migrations'));
        } catch (\Throwable) {
            $done = [];
        }
        $out = [];
        foreach (glob($dir . '/*.sql') ?: [] as $f) {
            $v = basename($f, '.sql');
            if (!isset($done[$v])) {
                $out[] = $v;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Split a SQL script into statements on ';' — aware of quotes, backticks,
     * -- / # line comments and block comments. (No DELIMITER support needed.)
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $n = $sql[$i + 1] ?? '';
            if ($quote !== null) {
                $buf .= $c;
                if ($c === '\\' && $quote !== '`') {
                    $buf .= $n;
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if (($c === '-' && $n === '-' && (($sql[$i + 2] ?? ' ') === ' ' || ($sql[$i + 2] ?? '') === "\n")) || $c === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                $buf .= "\n";
                continue;
            }
            if ($c === '/' && $n === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if ($c === ';') {
                $stmt = trim($buf);
                if ($stmt !== '') {
                    $out[] = $stmt;
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $tail = trim($buf);
        if ($tail !== '') {
            $out[] = $tail;
        }
        return $out;
    }
}
