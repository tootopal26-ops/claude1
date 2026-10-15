<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A UPI payment intent: the `upi://pay?pa=<payee>&pn=<name>&am=<amount>&cu=INR&…` string a UPI app reads from a QR code or opens
 * from a link, straight into its payment screen with the payee and the amount filled in.
 *
 * It is the ONLY thing the payment page is allowed to turn into a QR code. A gateway's hosted checkout URL (https://…) is not a UPI
 * intent: scanning that with a UPI app does not pay, and scanning it with a camera opens a web page that draws another QR code.
 * validateFor() accepts a string only if it is a well-formed intent for exactly this amount in INR, so a QR can never carry a
 * different amount, another currency or a look-alike link.
 *
 * Parameters we know (pa, pn, am, cu, tn, tr, mc, mode, purpose, orgid, sign, tid, url, mam) are checked; vendor extras such as
 * Paytm's `paytmqr` are allowed when their name is a plain identifier and their value is short and not a web link, because a gateway's
 * own intents carry them. No parameter may appear twice (which one would an app use?).
 */
final class Upi
{
    /**
     * Look at an intent and say what is wrong with it. `data` is set only when it is valid.
     *
     * @return array{ok:bool,reason:string,data:?array{uri:string,payee:string,name:?string,amount_paise:int,reference:?string,note:?string}}
     */
    public static function inspect(?string $uri): array
    {
        $bad = static fn(string $reason): array => ['ok' => false, 'reason' => $reason, 'data' => null];
        if ($uri === null || $uri === '') {
            return $bad('empty');
        }
        if (strlen($uri) > 1000) {
            return $bad('too_long');
        }
        // URL-safe characters only: no whitespace, control characters, quotes, angle brackets, backslashes or braces.
        if (!preg_match('/^[A-Za-z0-9\-._~:\/?&=%@+,()!*]+$/D', $uri) || !preg_match('#^upi://pay\?(.+)$#D', $uri, $m)) {
            return $bad('not_a_upi_pay_link');
        }
        $params = [];
        foreach (explode('&', $m[1]) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false || $eq === 0) {
                return $bad('malformed_parameter');
            }
            $key = substr($pair, 0, $eq);
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,30}$/D', $key) || array_key_exists($key, $params)) {
                return $bad(array_key_exists($key, $params) ? 'repeated_parameter' : 'bad_parameter_name');
            }
            $value = rawurldecode(substr($pair, $eq + 1));
            if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                return $bad('empty_or_control_value');
            }
            $params[$key] = $value;
        }

        // payee address (VPA): name@handle
        $payee = $params['pa'] ?? '';
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,62}@[A-Za-z][A-Za-z0-9.\-]{1,48}$/D', $payee)) {
            return $bad('no_valid_payee');
        }
        // amount: required (a QR without one makes the customer type it), plain rupees with at most two decimals, greater than zero
        if (!isset($params['am'])) {
            return $bad('no_amount');
        }
        if (!preg_match('/^(?:0|[1-9]\d{0,8})(?:\.\d{1,2})?$/D', $params['am'])) {
            return $bad('bad_amount');
        }
        $paise = Money::parseRupees($params['am']);
        if ($paise <= 0) {
            return $bad('zero_amount');
        }
        // currency: INR or absent (the specification's default); the shop charges rupees only
        if (isset($params['cu']) && $params['cu'] !== 'INR') {
            return $bad('not_inr');
        }
        foreach (['pn' => 100, 'tn' => 120, 'tr' => 80, 'tid' => 80, 'sign' => 600] as $k => $max) {
            if (isset($params[$k]) && mb_strlen($params[$k]) > $max) {
                return $bad('parameter_too_long');
            }
        }
        if ((isset($params['mc']) && !preg_match('/^\d{4}$/D', $params['mc'])) || (isset($params['mode']) && !preg_match('/^\d{2}$/D', $params['mode']))
            || (isset($params['purpose']) && !preg_match('/^\d{2}$/D', $params['purpose'])) || (isset($params['orgid']) && !preg_match('/^\d{1,8}$/D', $params['orgid']))) {
            return $bad('bad_parameter_value');
        }
        if (isset($params['url']) && (!preg_match('#^https://#i', $params['url']) || strlen($params['url']) > 300)) {
            return $bad('insecure_details_link');   // the optional "more details" link must be https
        }
        foreach ($params as $k => $v) {
            if (!in_array($k, ['pa', 'pn', 'am', 'cu', 'tn', 'tr', 'mc', 'mode', 'purpose', 'orgid', 'sign', 'tid', 'url', 'mam'], true)
                && (mb_strlen($v) > 200 || str_contains($v, '://'))) {
                return $bad('suspicious_extra_parameter');   // a vendor extra is a short token, never a link
            }
        }
        return ['ok' => true, 'reason' => 'ok', 'data' => [
            'uri' => $uri, 'payee' => $payee, 'name' => $params['pn'] ?? null, 'amount_paise' => $paise,
            'reference' => $params['tr'] ?? null, 'note' => $params['tn'] ?? null,
        ]];
    }

    /**
     * @return array{uri:string,payee:string,name:?string,amount_paise:int,reference:?string,note:?string}|null null = not a valid intent
     */
    public static function parse(?string $uri): ?array
    {
        return self::inspect($uri)['data'];
    }

    /**
     * The intent, if it is valid AND asks for exactly $expectedPaise.
     *
     * @return array{uri:string,payee:string,name:?string,amount_paise:int,reference:?string,note:?string}|null
     */
    public static function validateFor(?string $uri, int $expectedPaise): ?array
    {
        $p = self::parse($uri);
        return $p !== null && $p['amount_paise'] === $expectedPaise ? $p : null;
    }
}
