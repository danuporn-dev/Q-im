<?php
declare(strict_types=1);

namespace App\Payments;

/**
 * An online payment provider (D42). StripeGateway talks to Stripe; FakeGateway stands in for it in tests, which
 * never call Stripe. Amounts are in the smallest unit (satang for THB).
 *
 * An intent is returned as:
 * ['id' => string, 'status' => string, 'amount' => int, 'currency' => string, 'metadata' => array<string, string>,
 *  'qr' => ['data' => string, 'image_png' => ?string, 'image_svg' => ?string, 'hosted_url' => ?string]|null,
 *  'expires_at' => ?int]
 */
interface Gateway
{
    /** Creates and confirms a PromptPay PaymentIntent; the same idempotency key returns the same intent. */
    public function createPromptPay(int $amount, string $currency, array $metadata, string $idempotencyKey, ?string $email): array;

    public function retrieve(string $intentId): array;

    /** @throws GatewayError when the intent can no longer be cancelled */
    public function cancel(string $intentId, string $idempotencyKey): array;
}
