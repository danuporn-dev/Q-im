<?php
declare(strict_types=1);

namespace App\Payments;

use RuntimeException;

/**
 * A provider call that failed. The message is safe to log: keys are removed (StripeGateway::redact).
 * $status 0 = network failure or timeout, so the outcome is unknown and the same idempotency key must be reused.
 */
final class GatewayError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $providerCode = '')
    {
        parent::__construct($message);
    }
}
