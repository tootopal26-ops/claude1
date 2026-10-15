<?php
declare(strict_types=1);

use App\Payments\HostedCheckout;
use App\Support\Upi;

/**
 * What the gateway's checkout page is searched for: a UPI payment request, in whatever form the page hides it in. These are pure text
 * tests (the page content is made up here — nothing says the real Alpha UPI page looks like any of them; the point is that whatever
 * is found is validated for exactly the order's amount, and that nothing else is ever returned).
 */

const HC_URI = 'upi://pay?pa=merchant.demo@okbizaxis&pn=Social%20Media%20Booster&am=5.00&cu=INR&tr=SMB1234&tn=Order%20SMB1234';

T::test('checkout page: a UPI request in a link and in a script is found as it is', function () {
    $page = '<a class="pay" href="' . HC_URI . '">Pay</a><script>var upi = "' . HC_URI . '";</script>';
    T::same([HC_URI], HostedCheckout::intents($page), 'the same request twice is one candidate');
    $r = HostedCheckout::read($page, 500);
    T::same(HC_URI, $r['intent']);
    T::same(1, $r['candidates']);
    T::same(null, $r['why']);
});

T::test('checkout page: HTML entities, JSON / JS string escapes and URL-encoding around the request are undone', function () {
    $entity = '<a href="' . str_replace('&', '&amp;', HC_URI) . '">Pay</a>';
    T::same([HC_URI], HostedCheckout::intents($entity), '&amp;');
    $json = '<script>window.__PAY__ = {"upi":"' . str_replace(['/', '&'], ['\\/', '\\u0026'], HC_URI) . '"};</script>';
    T::same([HC_URI], HostedCheckout::intents($json), 'JSON: \\/ and \\u0026');
    $js = "<script>go('" . str_replace(['&', '='], ['\\x26', '\\x3d'], HC_URI) . "')</script>";
    T::same([HC_URI], HostedCheckout::intents($js), 'JS hex escapes');
    $img = '<img alt="QR" src="https://qr.example.test/create?size=256x256&amp;data=' . rawurlencode(HC_URI) . '">';
    T::same([HC_URI], HostedCheckout::intents($img), 'URL-encoded inside an image address');
    foreach ([$entity, $json, $js, $img] as $page) {
        T::same(HC_URI, HostedCheckout::read($page, 500)['intent']);
    }
});

T::test('checkout page: an app\'s own deep link carries the same request and is read as upi://pay', function () {
    $q = 'pa=merchant.demo@okbizaxis&am=5.00&cu=INR&tr=SMB1234';
    foreach (['phonepe://pay?' . $q, 'tez://upi/pay?' . $q, 'paytmmp://pay?' . $q, 'gpay://upi/pay?' . $q, 'bhim://pay?' . $q, 'intent://pay?' . $q . '#Intent;scheme=upi;package=com.phonepe.app;end'] as $link) {
        T::same(['upi://pay?' . $q], HostedCheckout::intents('<a href="' . $link . '">app</a>'), $link);
    }
    $both = '<a href="phonepe://pay?pa=app.first@ybl&am=5.00">PhonePe</a><a href="' . HC_URI . '">UPI</a>';
    T::same(HC_URI, HostedCheckout::intents($both)[0], 'a plain upi:// link is preferred to an app\'s scheme');
});

T::test('checkout page: only a request for EXACTLY the order\'s amount in INR is ever returned', function () {
    $r = HostedCheckout::read('<a href="upi://pay?pa=m@ybl&am=6.00&cu=INR">x</a>', 500);
    T::same([null, 'amount_is_6.00_not_5.00'], [$r['intent'], $r['why']], 'another amount');
    T::same('no_amount', HostedCheckout::read('<a href="upi://pay?pa=m@ybl&cu=INR">x</a>', 500)['why'], 'a request without an amount');
    T::same('not_inr', HostedCheckout::read('<a href="upi://pay?pa=m@ybl&am=5.00&cu=USD">x</a>', 500)['why'], 'another currency');
    T::same('no_valid_payee', HostedCheckout::read('<a href="upi://pay?pa=nothandle&am=5.00">x</a>', 500)['why'], 'no payee address');
    T::same('repeated_parameter', HostedCheckout::read('<a href="upi://pay?pa=m@ybl&am=5.00&am=500.00">x</a>', 500)['why'], 'the amount twice');
    T::same('suspicious_extra_parameter', HostedCheckout::read('<a href="upi://pay?pa=m@ybl&am=5.00&next=https://evil.example/x">x</a>', 500)['why'], 'an extra parameter that is a link');
    // the right one wins when the page also carries others
    $page = '<a href="upi://pay?pa=a@ybl&am=99.00">a</a> <a href="upi://pay?pa=b@ybl&am=5.00&cu=INR">b</a>';
    $r = HostedCheckout::read($page, 500);
    T::same('upi://pay?pa=b@ybl&am=5.00&cu=INR', $r['intent']);
    T::same(2, $r['candidates']);
    // a hosted-page https address is not a UPI request, whatever it looks like
    T::same(null, HostedCheckout::read('<a href="https://alphaupi.site/pay/ABC123?pa=m@ybl&am=5.00">pay</a>', 500)['intent']);
});

T::test('checkout page: vendor extras such as Paytm\'s paytmqr are kept (the gateway\'s own request, passed on unchanged)', function () {
    $uri = 'upi://pay?pa=paytmqr2810050501011abc@paytm&pn=Paytm%20Merchant&mc=5499&mode=02&orgid=000000&paytmqr=2810050501011ABC&am=5&cu=INR&tn=Order%20SMB1';
    $r = HostedCheckout::read('<a href="' . $uri . '">x</a>', 500);
    T::same($uri, $r['intent'], 'unchanged, byte for byte');
    T::same(500, Upi::parse($r['intent'])['amount_paise']);
});

T::test('checkout page: a page without a UPI request says so (and why) instead of guessing', function () {
    $page = '<html><body><canvas id="qr"></canvas><img alt="QR" src="data:image/gif;base64,R0lGODlhAQABAAAAACw="><svg></svg>Scan the QR</body></html>';
    $r = HostedCheckout::read($page, 500);
    T::same([null, 0, null], [$r['intent'], $r['candidates'], $r['why']]);
    T::same(['img' => 1, 'canvas' => 1, 'svg' => 1], array_intersect_key($r['hints'], array_flip(['img', 'canvas', 'svg'])), 'what the page does contain, for the admin report');
    T::same([], HostedCheckout::intents(''));
    T::same([], HostedCheckout::intents('xupi://pay?pa=a@ybl&am=5.00'), 'upi:// inside a longer word is not a link');
    T::same([], HostedCheckout::intents('upi://pay'), 'no query');
});

T::test('checkout page: a request cut short by whitespace or ending in punctuation is handled safely', function () {
    // an unencoded space ends the match: the request has no amount any more → unusable, never a guessed one
    T::same('no_amount', HostedCheckout::read('<a href="upi://pay?pa=m@ybl&tn=Order SMB1&am=5.00">x</a>', 500)['why']);
    // a sentence that ends the request with punctuation
    T::same('upi://pay?pa=m@ybl&am=5.00&cu=INR', HostedCheckout::read('Open upi://pay?pa=m@ybl&am=5.00&cu=INR). Thanks', 500)['intent']);
});

T::test('checkout page: a very large page is searched quickly and the number of candidates is capped', function () {
    $page = str_repeat('<div>filler ', 150_000) . '<a href="' . HC_URI . '">pay</a>';
    $t0 = microtime(true);
    $r = HostedCheckout::read($page, 500);
    T::same(HC_URI, $r['intent']);
    T::true(microtime(true) - $t0 < 1.5, 'searched in ' . round(microtime(true) - $t0, 2) . ' s');
    $many = str_repeat('<a href="upi://pay?pa=a@ybl&am=1.00">x</a>', 5) . implode('', array_map(static fn(int $i): string => '<a href="upi://pay?pa=u' . $i . '@ybl&am=2.00">x</a>', range(1, 200)));
    T::true(count(HostedCheckout::intents($many)) <= 25, 'at most 25 candidates');
});
