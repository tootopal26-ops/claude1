<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A UPI payment intent: the `upi://pay?pa=<payee>&pn=<name>&am=<amount>&cu=INR&…` string a UPI app reads from a QR code or opens
 * from a link, straight into its payment screen with the payee and the amount filled in.
 *
 * It is the ONLY thing the payment page is allowed to turn into a QR code. A gateway's hosted checkout URL (https://…) is not a UPI
 * intent: scanning that with a UPI app does not pay, and scanning it with a camera opens a web page that draws another QR code — the
 * "QR that opens a second QR" this class exists to prevent. validateFor() accepts a string only if it is a well-formed intent for
 * exactly this amount in INR, so a QR can never carry a different amount, another currency or a look-alike link.
 */
final class Upi
{
    /** Parameters of the NPCI UPI linking specification; anything else is refused. */
    private const KEYS = ['pa', 'pn', 'am', 'cu', 'tn', 'tr', 'mc', 'mode', 'purpose', 'orgid', 'sign', 'tid', 'url'];

    /**
     * @return array{uri:string,payee:string,name:?string,amount_paise:int,reference:?string,note:?string}|null null = not a valid intent
     */
    public static function parse(?string $uri): ?array
    {
        if ($uri === null || $uri === '' || strlen($uri) > 1000) {
            return null;
        }
        // URL-safe characters only: no whitespace, control characters, quotes, angle brackets, backslashes or braces.
        if (!preg_match('/^[A-Za-z0-9\-._~:\/?&=%@+,()!*]+$/D', $uri) || !preg_match('#^upi://pay\?(.+)$#D', $uri, $m)) {
            return null;
        }
        $params = [];
        foreach (explode('&', $m[1]) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false || $eq === 0) {
                return null;
            }
            $key = substr($pair, 0, $eq);
            if (!in_array($key, self::KEYS, true) || array_key_exists($key, $params)) {
                return null;   // an unknown parameter, or the same one twice (which app would win?)
            }
            $value = rawurldecode(substr($pair, $eq + 1));
            if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                return null;
            }
            $params[$key] = $value;
        }

        // payee address (VPA): name@handle
        $payee = $params['pa'] ?? '';
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,62}@[A-Za-z][A-Za-z0-9.\-]{1,48}$/D', $payee)) {
            return null;
        }
        // amount: required (a QR without one makes the customer type it), plain rupees with at most two decimals, greater than zero
        $amount = $params['am'] ?? '';
        if (!preg_match('/^(?:0|[1-9]\d{0,8})(?:\.\d{1,2})?$/D', $amount)) {
            return null;
        }
        $paise = Money::parseRupees($amount);
        if ($paise <= 0) {
            return null;
        }
        // currency: INR or absent (the specification's default); the shop charges rupees only
        if (isset($params['cu']) && $params['cu'] !== 'INR') {
            return null;
        }
        foreach (['pn' => 100, 'tn' => 80, 'tr' => 35, 'tid' => 35, 'sign' => 600] as $k => $max) {
            if (isset($params[$k]) && mb_strlen($params[$k]) > $max) {
                return null;
            }
        }
        if ((isset($params['mc']) && !preg_match('/^\d{4}$/D', $params['mc'])) || (isset($params['mode']) && !preg_match('/^\d{2}$/D', $params['mode']))
            || (isset($params['purpose']) && !preg_match('/^\d{2}$/D', $params['purpose'])) || (isset($params['orgid']) && !preg_match('/^\d{1,8}$/D', $params['orgid']))) {
            return null;
        }
        if (isset($params['url']) && (!preg_match('#^https://#i', $params['url']) || strlen($params['url']) > 300)) {
            return null;   // the optional "more details" link must be https
        }
        return [
            'uri' => $uri, 'payee' => $payee, 'name' => $params['pn'] ?? null, 'amount_paise' => $paise,
            'reference' => $params['tr'] ?? null, 'note' => $params['tn'] ?? null,
        ];
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
