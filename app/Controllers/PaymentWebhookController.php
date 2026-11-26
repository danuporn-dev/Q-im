<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\OnlinePayments;
use App\Support\Request;
use App\Support\Response;

/** POST /api/webhooks/stripe (D42). No session or CSRF token: the Stripe-Signature over the raw body is the check. */
final class PaymentWebhookController
{
    private const MAX_BYTES = 262144;

    public static function stripe(Request $q): ?array
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BYTES || strlen($q->raw()) > self::MAX_BYTES) {
            Response::json(['error' => 'too large'], 413);
            return null;
        }
        [$status, $body] = OnlinePayments::webhook($q->raw(), $q->header('Stripe-Signature'));
        Response::json($body, $status);
        return null;
    }
}
