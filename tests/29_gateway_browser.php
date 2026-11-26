<?php
declare(strict_types=1);

// Online PromptPay in a real headless browser (D42), with FakeGateway. Skipped without Chrome or Edge.

use App\Payments\FakeGateway;
use App\Payments\StripeSignature;
use App\Support\Database;
use App\Support\Env;

require_once __DIR__ . '/browser.php';

$binary = Browser::find();
if ($binary === null) {
    T::skip('T53', 'no Chrome or Edge; set CHROME_PATH');
    return;
}

$a = '/api/v1';
$secret = 'whsec_test_' . bin2hex(random_bytes(16));
$fake = sys_get_temp_dir() . '/qim-fake-gateway-ui-' . Fixture::run() . '.json';
@unlink($fake);
$gw = Servers::start(8361, ['PAYMENT_MODE' => 'gateway', 'PAYMENT_GATEWAY' => 'fake', 'FAKE_GATEWAY_FILE' => $fake, 'STRIPE_WEBHOOK_SECRET' => $secret,
    'STRIPE_SECRET_KEY' => 'sk_test_FAKE' . bin2hex(random_bytes(8)), 'RATE_LIMIT_MULTIPLIER' => '1000', 'POLL_SECONDS' => '2']);
$s = Fixture::shop('gwui');
$m = Fixture::login($gw, 'merchant', $s['login']);
$c = new Client($gw);
$c->csrf = $c->post("$a/auth/dev", ['customer' => 'CUSTOMER_2'])['body']['data']['csrf'];
$dialog = "document.querySelector('dialog')||document.createElement('i')";
$latest = static fn (): int => (int) Database::value('SELECT o.id FROM orders o JOIN users u ON u.id = o.user_id WHERE u.line_user_id = ? AND o.shop_id = ? ORDER BY o.id DESC LIMIT 1', ['DEMO_CUSTOMER_2', $s['shop']]);
$intentOf = static fn (int $order): string => (string) Database::value('SELECT intent_id FROM payment_intents WHERE order_id = ? ORDER BY attempt DESC LIMIT 1', [$order]);
$webhook = static function (array $intent) use ($gw, $secret): void {
    $payload = json_encode(['id' => 'evt_' . bin2hex(random_bytes(8)), 'object' => 'event', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => $intent['id'], 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => $intent['amount'], 'currency' => 'thb', 'metadata' => $intent['metadata']]]]);
    (new Client($gw))->post('/api/webhooks/stripe', $payload, ['Content-Type: application/json', 'Stripe-Signature: ' . StripeSignature::header($payload, $secret, time())]);
};
$cartWith = static fn () => $c->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [['menu_id' => $s['menu'], 'quantity' => 1]], 'replace' => true]);

$b = new Browser($binary, 9392);
try {
    T::group('T53 customer pays online in the browser');
    $b->size(390, 844);
    $cartWith();
    $b->open("$gw/customer/");
    $b->click('ต้นกล้า');
    $b->until("document.querySelector('[data-bell]')");
    $b->open("$gw/customer/#/cart");
    T::ok($b->sees('จ่ายออนไลน์ (PromptPay)'), 'checkout offers online PromptPay');
    T::eq(false, (bool) $b->js("[...document.querySelectorAll('input[name=payment_method]')].some((e) => e.value === 'PROMPTPAY')"), "the shop's own PromptPay tile is hidden in gateway mode");
    $b->js("document.querySelector('input[value=STRIPE_PROMPTPAY]').click()");
    $b->click('ยืนยันออเดอร์');
    T::ok($b->sees('ปุ่มชำระจะแสดงเมื่อร้านรับออเดอร์'), 'no QR before the shop accepts');
    $order = $latest();
    T::eq('STRIPE_PROMPTPAY', Database::value('SELECT requested_method FROM payments WHERE order_id = ?', [$order]), 'saved as online PromptPay');
    $m->post("$a/merchant/orders/$order/accept");
    T::ok($b->sees('แสดง QR จ่าย ฿45', 15), 'after accepting: pay button');
    $b->click('แสดง QR จ่าย ฿45');
    T::ok($b->sees('QR ใช้ได้อีก') && (bool) $b->until("document.querySelector('.payqr .qr img')"), 'QR with a countdown');
    T::ok($b->sees('บันทึกรูป QR') && $b->sees('ตรวจสถานะการชำระ') && $b->sees('เปลี่ยนเป็นจ่ายที่ร้าน'), 'save, check and switch buttons');
    $webhook(FakeGateway::change($fake, $intentOf($order), ['status' => 'succeeded']));
    T::ok($b->sees('ชำระแล้วออนไลน์', 15), 'paid shows up by itself (polling)');

    $cartWith();
    $b->open("$gw/customer/#/cart");
    $b->until("document.querySelector('input[value=STRIPE_PROMPTPAY]')");
    $b->js("document.querySelector('input[value=STRIPE_PROMPTPAY]').click()");
    $b->click('ยืนยันออเดอร์');
    $b->sees('ปุ่มชำระจะแสดงเมื่อร้านรับออเดอร์');
    $second = $latest();
    $m->post("$a/merchant/orders/$second/accept");
    $b->sees('แสดง QR จ่าย', 15);
    $b->click('แสดง QR จ่าย');
    $b->sees('QR ใช้ได้อีก');
    Database::run('UPDATE payment_intents SET qr_expires_at = ? WHERE order_id = ?', [gmdate('Y-m-d H:i:s', time() + 3), $second]);
    $b->reload();
    T::ok($b->sees('QR หมดอายุแล้ว', 15) && $b->sees('ขอ QR ใหม่'), 'expired QR: ask for a new one');
    $b->click('ขอ QR ใหม่');
    T::ok($b->sees('QR ใช้ได้อีก') && (int) Database::value('SELECT COUNT(*) FROM payment_intents WHERE order_id = ?', [$second]) === 1, 'new QR, same PaymentIntent');
    $b->click('เปลี่ยนเป็นจ่ายที่ร้าน');
    $b->click('เปลี่ยนเป็นจ่ายที่ร้าน', $dialog);
    T::ok($b->sees('ชำระที่ร้านตอนรับอาหาร', 15) && !$b->js("!!document.querySelector('.payqr')"), 'switched to paying at the shop');
    T::eq('canceled', (new FakeGateway($fake))->retrieve($intentOf($second))['status'], 'the PaymentIntent was cancelled first');
    T::eq([], $b->errors, 'no script errors');

    T::group('T55 the shop sees online payments, hands over without taking money, and lists refunds');
    $m->post("$a/merchant/orders/$order/ready");
    $b->size(1280, 800);
    $b->open("$gw/merchant/");
    $b->fill('input[name=login]', $s['login']);
    $b->fill('input[name=password]', Env::get('SEED_DEMO_PASSWORD'));
    $b->click('เข้าสู่ระบบ');
    T::ok($b->sees('ชำระแล้วออนไลน์'), 'board label: paid online');
    $b->click('ส่งมอบ');
    T::ok($b->sees('✓ ชำระแล้ว สแกน QR เพื่อส่งมอบ'), 'hand-over knows it is paid');
    $b->fill('dialog input[name=pickup_qr]', (string) Database::value('SELECT public_order_no FROM orders WHERE id = ?', [$order]));
    $b->click('ตรวจ', $dialog);
    T::ok($b->sees('ส่งมอบเรียบร้อย'), 'completed without a "money received" step');
    T::eq(['COMPLETED', 0], [Database::value('SELECT status FROM orders WHERE id = ?', [$order]), (int) $b->js("[...document.querySelectorAll('button')].filter((e) => e.innerText.includes('ได้รับเงินแล้ว')).length")], 'no money button was needed');
    $cartWith();
    $paidOrder = (int) $c->post("$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $c->get("$a/cart")['body']['data']['quote'], 'payment_method' => 'STRIPE_PROMPTPAY'])['body']['data']['id'];
    $m->post("$a/merchant/orders/$paidOrder/accept");
    $c->post("$a/orders/$paidOrder/online-payment");
    $webhook(FakeGateway::change($fake, $intentOf($paidOrder), ['status' => 'succeeded']));
    $m->post("$a/merchant/orders/$paidOrder/cancel", ['reason' => 'แก๊สหมด']);
    $b->js("location.hash='#/refunds'");
    T::ok($b->sees('รอคืนเงิน ตั้งแต่') && $b->sees($intentOf($paidOrder)), 'refunds page lists it with the Stripe id');
    $b->click('ทำเครื่องหมายคืนเงินแล้ว');
    $b->fill('dialog textarea', 're_test คืนแล้ว');
    $b->click('คืนเงินแล้ว', $dialog);
    T::ok($b->sees('คืนแล้ว'), 'marked as refunded');
    T::eq('REFUNDED', Database::value('SELECT status FROM payments WHERE order_id = ?', [$paidOrder]), 'recorded');
    [, $adminLogin] = Fixture::staff('admin', 'gwui_' . Fixture::run());
    $b->open("$gw/admin/");
    $b->fill('input[name=login]', $adminLogin);
    $b->fill('input[name=password]', Env::get('SEED_DEMO_PASSWORD'));
    $b->click('เข้าสู่ระบบ');
    $b->sees('ภาพรวมวันนี้');
    $b->click('ชำระออนไลน์');
    T::ok($b->sees('รายการผิดปกติ') && $b->sees($intentOf($paidOrder)), 'admin page lists online payments to check');
    T::eq([], $b->errors, 'no script errors');
} finally {
    $b->close();
}
