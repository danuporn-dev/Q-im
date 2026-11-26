<?php
declare(strict_types=1);

// Usage: php scripts/stripe-smoke.php [amount in satang, default 2000]   (development only)
// Checks that the Stripe sandbox account in .env can create a PromptPay PaymentIntent (D42): creates one, creates
// it again with the same Idempotency-Key, reads it back and cancels it. Prints what Stripe answered, never the key.
// Optional: STRIPE_PLACEHOLDER_EMAIL, sent as billing email only if set (never a customer's address).

use App\Payments\GatewayError;
use App\Payments\StripeGateway;
use App\Support\Env;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';
if (!Env::isDevelopment()) {
    fwrite(STDERR, "Refusing: development only.\n");
    exit(1);
}
$amount = (int) ($argv[1] ?? 2000);
$email = Env::get('STRIPE_PLACEHOLDER_EMAIL') ?: null;
$show = static function (string $label, array $pi): void {
    echo str_pad($label, 22) . json_encode([
        'id' => $pi['id'], 'status' => $pi['status'], 'amount' => $pi['amount'], 'currency' => $pi['currency'], 'metadata' => $pi['metadata'],
        'qr' => $pi['qr'] === null ? null : ['data_length' => strlen($pi['qr']['data']), 'image_png' => $pi['qr']['image_png'] !== null, 'image_svg' => $pi['qr']['image_svg'] !== null, 'hosted_url' => $pi['qr']['hosted_url'] !== null],
        'qr_fields' => $pi['qr_fields'], 'expires_at' => $pi['expires_at'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
};
try {
    $stripe = StripeGateway::fromEnv();
    echo "Key: sandbox key from .env (not shown)\n";
    echo 'Billing email: ' . ($email === null ? 'none' : 'STRIPE_PLACEHOLDER_EMAIL') . "\n";
    $key = 'qim-smoke-' . bin2hex(random_bytes(12));
    $first = $stripe->createPromptPay($amount, 'THB', ['order_id' => 'smoke', 'app' => 'qim'], $key, $email);
    $show('created', $first);
    $again = $stripe->createPromptPay($amount, 'THB', ['order_id' => 'smoke', 'app' => 'qim'], $key, $email);
    $show('same key again', $again);
    echo 'Idempotency: ' . ($again['id'] === $first['id'] ? 'same PaymentIntent' : 'DIFFERENT PaymentIntent') . "\n";
    $show('retrieved', $stripe->retrieve($first['id']));
    $show('cancelled', $stripe->cancel($first['id'], 'qim-smoke-cancel-' . bin2hex(random_bytes(12))));
    echo "Smoke test passed.\n";
} catch (GatewayError $e) {
    fwrite(STDERR, 'Stripe refused: ' . StripeGateway::redact($e->getMessage()) . ($e->providerCode !== '' ? " [{$e->providerCode}]" : '') . "\n");
    exit(2);
}
