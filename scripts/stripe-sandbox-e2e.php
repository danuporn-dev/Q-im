<?php
declare(strict_types=1);

// Usage: php scripts/stripe-sandbox-e2e.php   (development only; calls the real Stripe sandbox)
// One online PromptPay order against Stripe's sandbox with the sk_test_ key of .env, on the throw-away qim_test (D44):
// checkout gives the QR at once, the order is unseen by the shop, a headless browser "scans" the QR: in the sandbox the
// QR holds the address of Stripe's test payment page, where it presses "Authorize test payment"; the server reads the PaymentIntent back (the "check payment" reconcile, since no webhook can reach this
// computer), and the order reaches the shop as PENDING and PAID; then accept, ready and hand-over with the pickup QR.
// The key is passed to the local test server only; it is never printed. Up to 3 tries at the Stripe test page.

use App\Payments\StripeGateway;
use App\Support\Database;
use App\Support\Env;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
// The key of .env, read before the test setup blanks every Stripe setting for the test servers.
require dirname(__DIR__) . '/app/bootstrap.php';
$key = trim(Env::get('STRIPE_SECRET_KEY'));
$email = trim(Env::get('STRIPE_PLACEHOLDER_EMAIL'));
if (!Env::isDevelopment() || !str_starts_with($key, 'sk_test_') || StripeGateway::keyProblem($key) !== null) {
    fwrite(STDERR, "Refusing: development only, with a Stripe sandbox key (sk_test_...) in .env.\n");
    exit(1);
}
require dirname(__DIR__) . '/tests/lib.php';
require dirname(__DIR__) . '/tests/fixture.php';
require dirname(__DIR__) . '/tests/browser.php';

$binary = Browser::find();
if ($binary === null) {
    fwrite(STDERR, "No Chrome or Edge for Stripe's test page; set CHROME_PATH.\n");
    TestDb::finish();
    exit(1);
}
$a = '/api/v1';
$srv = Servers::start(8164, ['PAYMENT_MODE' => 'gateway', 'PAYMENT_GATEWAY' => 'stripe', 'STRIPE_SECRET_KEY' => $key, 'STRIPE_PLACEHOLDER_EMAIL' => $email !== '' ? $email : ' ',
    'STRIPE_WEBHOOK_SECRET' => ' ', 'RATE_LIMIT_MULTIPLIER' => '1000', 'PROMPTPAY_QR_MINUTES' => '15']);
$b = null;
T::group('Stripe sandbox E2E (D44)');
try {
    $s = Fixture::shop('sbx');
    $m = Fixture::login($srv, 'merchant', $s['login']);
    $c = Fixture::customer($srv, 'sbx');
    $cart = $c->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [['menu_id' => $s['menu'], 'quantity' => 1]], 'replace' => true]);
    $r = $c->post("$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote'] ?? '', 'payment_method' => 'STRIPE_PROMPTPAY']);
    T::status(201, $r, 'checkout with online PromptPay');
    $order = (int) ($r['body']['data']['id'] ?? 0);
    $pay = $r['body']['data']['online_payment'] ?? [];
    // A live QR holds the PromptPay payload (000201...); a sandbox QR holds the address of Stripe's test payment page.
    $scan = (string) ($pay['qr']['data'] ?? '');
    T::ok(($r['body']['data']['status'] ?? '') === 'AWAITING_PAYMENT' && ($pay['state'] ?? '') === 'qr' && str_starts_with($scan, 'https://payments.stripe.com/'), 'QR from Stripe at once, order waits for the money', ['status' => $r['body']['data']['status'] ?? null, 'state' => $pay['state'] ?? null, 'error' => $r['body']['error'] ?? null]);
    $intentId = (string) Database::value('SELECT intent_id FROM payment_intents WHERE order_id = ?', [$order]);
    echo "  PaymentIntent $intentId (sandbox), Stripe-Version " . StripeGateway::API_VERSION . "\n";
    T::ok(!in_array($order, array_column($m->get("$a/merchant/shops/{$s['shop']}/orders")['body']['data'] ?? [], 'id'), true), 'the shop does not see it yet');
    T::ok(str_starts_with((string) ($pay['qr']['test_url'] ?? ''), 'https://'), "Stripe's test page address is offered in sandbox mode");

    $b = new Browser($binary, 9394);
    $authorised = false;
    $why = '';
    for ($try = 1; $try <= 3 && !$authorised && $scan !== ''; $try++) {
        try {
            // The page redirects, so wait for its button rather than for the same address (Browser::open).
            $b->send('Page.navigate', ['url' => $scan]);
            $button = "[...document.querySelectorAll('button,a,[role=button]')].filter(e=>e.offsetParent!==null&&/authori[sz]e/i.test(e.innerText))";
            if (!$b->until("$button.length", 30)) {
                $why = 'no authorize button on the test page: ' . mb_substr(preg_replace('/\s+/', ' ', (string) $b->js('document.body.innerText')), 0, 300);
                echo "  try $try: $why
";
                continue;
            }
            $b->js("($button)[0].click()");
            // Stripe records the payment within a few seconds; the server asks Stripe (reconcile) until it is PAID.
            for ($i = 0; $i < 20 && !$authorised; $i++) {
                sleep(2);
                $view = $c->post("$a/orders/$order/online-payment/check")['body']['data'] ?? [];
                $authorised = ($view['payment_status'] ?? '') === 'PAID';
            }
            $why = $authorised ? '' : 'Stripe did not report the payment within 40 s (intent ' . Database::value('SELECT status FROM payment_intents WHERE order_id = ?', [$order]) . ')';
        } catch (Throwable $e) {
            $why = 'browser: ' . $e->getMessage();
        }
        echo "  try $try: " . ($authorised ? 'authorised' : $why) . "\n";
    }
    T::ok($authorised, "authorised on Stripe's test page and read back by the server", $why);
    $view = $c->get("$a/orders/$order")['body']['data'] ?? [];
    T::eq(['PENDING', 'PAID', 'STRIPE_PROMPTPAY'], [$view['status'] ?? null, $view['payment_status'] ?? null, $view['payment_method'] ?? null], 'the order reached the shop, paid online');
    $card = array_column($m->get("$a/merchant/shops/{$s['shop']}/orders")['body']['data'] ?? [], null, 'id')[$order] ?? [];
    T::eq(['PENDING', 'PAID'], [$card['status'] ?? null, $card['payment_status'] ?? null], "on the shop's board as paid");
    if ($authorised) {
        T::status(200, $m->post("$a/merchant/orders/$order/accept"), 'shop accepts');
        T::status(200, $m->post("$a/merchant/orders/$order/ready"), 'ready');
        $token = (string) ($c->get("$a/orders/$order")['body']['data']['pickup']['qr'] ?? '');
        T::status(200, $m->post("$a/merchant/orders/$order/complete", ['pickup_qr' => $token]), 'hand-over with the pickup QR, no money step');
        T::eq('COMPLETED', Database::value('SELECT status FROM orders WHERE id = ?', [$order]), 'completed');
    }
} catch (Throwable $e) {
    T::ok(false, 'exception: ' . StripeGateway::redact($e->getMessage()));
} finally {
    $b?->close();
}
echo "\n== Stripe sandbox E2E " . T::$passed . ' passed, ' . count(T::$failed) . " failed\n";
exit(TestDb::finish() && T::$failed === [] ? 0 : 1);
