<?php
declare(strict_types=1);

namespace App\Payments;

use App\Support\Env;
use App\Support\HttpClient;

/**
 * Stripe over plain HTTPS (no SDK), PromptPay PaymentIntents only (D42). The secret key stays in this class: it is
 * never returned, logged or put in an exception message.
 */
final class StripeGateway implements Gateway
{
    private const BASE = 'https://api.stripe.com/v1';
    /**
     * Every request names the API version it was written for, so a change of the account's default version in the
     * Dashboard cannot change the answers this class reads (next_action.promptpay_display_qr_code, status, ...).
     */
    public const API_VERSION = '2025-03-31.basil';

    public function __construct(private readonly string $key)
    {
        $problem = self::keyProblem($key);
        if ($problem !== null) {
            throw new GatewayError($problem);
        }
    }

    public static function fromEnv(): self
    {
        return new self(trim(Env::get('STRIPE_SECRET_KEY')));
    }

    /**
     * Only sandbox keys, unless production explicitly allows live ones (STRIPE_ALLOW_LIVE=1).
     * Returns why the key cannot be used, without any part of the key.
     */
    public static function keyProblem(string $key): ?string
    {
        if ($key === '') {
            return 'STRIPE_SECRET_KEY is not set';
        }
        if (str_starts_with($key, 'sk_test_')) {
            return null;
        }
        if (str_starts_with($key, 'sk_live_') && Env::isProduction() && Env::get('STRIPE_ALLOW_LIVE') === '1') {
            return null;
        }
        return 'STRIPE_SECRET_KEY must be a sandbox secret key (sk_test_...); live keys need APP_ENV=production and STRIPE_ALLOW_LIVE=1';
    }

    /** Removes anything that looks like a Stripe key or webhook secret from a text. */
    public static function redact(string $text): string
    {
        return (string) preg_replace('/\b(sk|rk|pk)_(test|live)_[A-Za-z0-9*]+|\bwhsec_[A-Za-z0-9*]+/', '[key]', $text);
    }

    public function createPromptPay(int $amount, string $currency, array $metadata, string $idempotencyKey, ?string $email): array
    {
        $params = [
            'amount' => $amount,
            'currency' => strtolower($currency),
            // payment_method_types is refused by Stripe now; the PromptPay method data picks the method, and
            // no redirect methods because there is no return page.
            'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'],
            'confirm' => 'true',
            'payment_method_data' => ['type' => 'promptpay'] + ($email === null ? [] : ['billing_details' => ['email' => $email]]),
            'metadata' => $metadata,
        ];
        return self::intent($this->call('POST', '/payment_intents', $params, $idempotencyKey));
    }

    public function retrieve(string $intentId): array
    {
        return self::intent($this->call('GET', '/payment_intents/' . self::id($intentId), null, null));
    }

    public function cancel(string $intentId, string $idempotencyKey): array
    {
        return self::intent($this->call('POST', '/payment_intents/' . self::id($intentId) . '/cancel', ['cancellation_reason' => 'requested_by_customer'], $idempotencyKey));
    }

    /** Turns Stripe's PaymentIntent into the shape described in Gateway. */
    public static function intent(array $pi): array
    {
        $qr = $pi['next_action']['promptpay_display_qr_code'] ?? null;
        return [
            'id' => (string) ($pi['id'] ?? ''),
            'status' => (string) ($pi['status'] ?? ''),
            'amount' => (int) ($pi['amount'] ?? 0),
            'currency' => strtoupper((string) ($pi['currency'] ?? '')),
            'metadata' => array_map('strval', is_array($pi['metadata'] ?? null) ? $pi['metadata'] : []),
            'qr' => is_array($qr) && is_string($qr['data'] ?? null) ? [
                'data' => $qr['data'],
                'image_png' => is_string($qr['image_url_png'] ?? null) ? $qr['image_url_png'] : null,
                'image_svg' => is_string($qr['image_url_svg'] ?? null) ? $qr['image_url_svg'] : null,
                'hosted_url' => is_string($qr['hosted_instructions_url'] ?? null) ? $qr['hosted_instructions_url'] : null,
            ] : null,
            'expires_at' => is_int($qr['expires_at'] ?? null) ? $qr['expires_at'] : null,
            // Field names Stripe sent with the QR, for scripts/stripe-smoke.php (the docs do not list an expiry).
            'qr_fields' => is_array($qr) ? array_keys($qr) : [],
        ];
    }

    private static function id(string $intentId): string
    {
        if (!preg_match('/^pi_[A-Za-z0-9]{1,100}$/D', $intentId)) {
            throw new GatewayError('Not a PaymentIntent id');
        }
        return $intentId;
    }

    private function call(string $method, string $path, ?array $params, ?string $idempotencyKey): array
    {
        $headers = ['Authorization: Bearer ' . $this->key, 'Accept: application/json', 'Stripe-Version: ' . self::API_VERSION];
        if ($params !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $r = HttpClient::request($method, self::BASE . $path, $params === null ? null : http_build_query($params, '', '&', PHP_QUERY_RFC1738), $headers);
        $body = json_decode($r['body'], true);
        if ($r['status'] === 0) {
            throw new GatewayError('Stripe did not answer (network or timeout)');
        }
        if ($r['status'] >= 400 || !is_array($body)) {
            $error = is_array($body['error'] ?? null) ? $body['error'] : [];
            throw new GatewayError(self::redact('Stripe ' . $r['status'] . ': ' . (string) ($error['message'] ?? 'unreadable answer')), $r['status'], (string) ($error['code'] ?? $error['type'] ?? ''));
        }
        return $body;
    }
}
