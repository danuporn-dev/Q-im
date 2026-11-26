<?php
declare(strict_types=1);

namespace App\Payments;

use App\Support\Env;

/** Which payment setup is in use (D42). */
final class Payments
{
    /** PAYMENT_MODE: "gateway" turns online PromptPay on; anything else, or nothing, is "manual" (D38 only). */
    public static function mode(): string
    {
        return strtolower(trim(Env::get('PAYMENT_MODE'))) === 'gateway' ? 'gateway' : 'manual';
    }

    public static function online(): bool
    {
        return self::mode() === 'gateway';
    }

    /** Stripe, or the fake one for tests (PAYMENT_GATEWAY=fake, a test hook that only works in development). */
    public static function gateway(): Gateway
    {
        if (Env::isDevelopment() && Env::get('PAYMENT_GATEWAY') === 'fake') {
            return new FakeGateway(Env::get('FAKE_GATEWAY_FILE') ?: dirname(__DIR__, 2) . '/storage/logs/fake-gateway.json');
        }
        return StripeGateway::fromEnv();
    }

    /** Sandbox keys only show the Stripe test page link to customers. */
    public static function sandbox(): bool
    {
        return !str_starts_with(Env::get('STRIPE_SECRET_KEY'), 'sk_live_');
    }
}
