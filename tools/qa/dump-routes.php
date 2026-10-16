<?php
// QA helper: print every registered route as "METHOD<TAB>example path<TAB>middleware" so the security audit can hit each one as an attacker would.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

$router = new App\Core\Router();
(require SMB_ROOT . '/app/routes.php')($router);
$routes = (new ReflectionProperty($router, 'routes'))->getValue($router);

/** Turn a compiled route regex back into a concrete URL: (?P<id>\d+) → 1, (?P<ref>[a-f0-9]{32}) → 32 hex digits, … */
function example(string $regex): string
{
    $s = substr($regex, 2, -2);                 // strip  #^  and  $#
    $out = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        if (substr($s, $i, 4) === '(?P<') {
            $end = strpos($s, '>', $i);
            $name = substr($s, $i + 4, $end - $i - 4);
            $depth = 1;
            for ($j = $end + 1; $j < $n && $depth > 0; $j++) {
                if ($s[$j] === '\\') { $j++; continue; }
                if ($s[$j] === '(') { $depth++; } elseif ($s[$j] === ')') { $depth--; }
            }
            $pattern = substr($s, $end + 1, $j - $end - 2);
            $out .= match (true) {
                $name === 'id' => '1',
                $name === 'ref' || $name === 'token' => str_repeat('a', 32),
                $name === 'page' => 'dashboard',
                (bool) preg_match('/^[a-z_\-]+(\|[a-z_\-]+)+$/', $pattern) => explode('|', $pattern)[0],   // alternatives such as site_logo|favicon
                default => sample($pattern),
            };
            $i = $j - 1;
            continue;
        }
        if ($s[$i] === '\\' && $i + 1 < $n) { $out .= $s[++$i]; continue; }
        $out .= $s[$i];
    }
    return $out;
}

/** A concrete value for a route parameter: the first of a few typical values that the parameter's own pattern accepts (an id, a job name …). */
function sample(string $pattern): string
{
    foreach (['1', 'payments', 'abc', 'a1b', '2026-01-15'] as $candidate) {
        if (@preg_match('#^(?:' . $pattern . ')$#', $candidate) === 1) {
            return $candidate;
        }
    }
    return '1';
}

foreach ($routes as $r) {
    $mw = array_map(static fn($m) => is_array($m) ? (string) ($m[1] ?? '?') : 'closure', $r['middleware']);
    echo $r['method'] . "\t" . example($r['regex']) . "\t" . implode(',', $mw) . "\n";
}
