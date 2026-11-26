<?php
declare(strict_types=1);

namespace App\Payments;

/**
 * Stands in for Stripe in tests and rehearsals (PAYMENT_GATEWAY=fake, development only): never calls the network.
 * Intents live in a JSON file shared by the PHP processes of a test run, locked while read and written.
 * Tests change an intent with FakeGateway::change() (as a customer's bank would) and send the webhook themselves.
 */
final class FakeGateway implements Gateway
{
    private const CANCELLABLE = ['requires_payment_method', 'requires_confirmation', 'requires_action'];

    public function __construct(private readonly string $file)
    {
    }

    public function createPromptPay(int $amount, string $currency, array $metadata, string $idempotencyKey, ?string $email): array
    {
        return $this->with(static function (array &$state) use ($amount, $currency, $metadata, $idempotencyKey): array {
            self::failIfAsked($state, 'create');
            $state['calls']['create'] = ($state['calls']['create'] ?? 0) + 1;
            if (isset($state['keys'][$idempotencyKey])) {
                return $state['intents'][$state['keys'][$idempotencyKey]];
            }
            $id = 'pi_fake' . bin2hex(random_bytes(8));
            $state['keys'][$idempotencyKey] = $id;
            return $state['intents'][$id] = [
                'id' => $id, 'status' => 'requires_action', 'amount' => $amount, 'currency' => strtoupper($currency), 'metadata' => array_map('strval', $metadata),
                'qr' => ['data' => "00020101021230FAKEPROMPTPAY$id", 'image_png' => null, 'image_svg' => null, 'hosted_url' => null],
                'expires_at' => null, 'qr_fields' => ['data'],
            ];
        });
    }

    public function retrieve(string $intentId): array
    {
        return $this->with(static function (array &$state) use ($intentId): array {
            self::failIfAsked($state, 'retrieve');
            $state['calls']['retrieve'] = ($state['calls']['retrieve'] ?? 0) + 1;
            return $state['intents'][$intentId] ?? throw new GatewayError('Stripe 404: No such payment_intent', 404, 'resource_missing');
        });
    }

    public function cancel(string $intentId, string $idempotencyKey): array
    {
        return $this->with(static function (array &$state) use ($intentId): array {
            self::failIfAsked($state, 'cancel');
            $intent = &$state['intents'][$intentId];
            if ($intent === null) {
                throw new GatewayError('Stripe 404: No such payment_intent', 404, 'resource_missing');
            }
            if ($intent['status'] === 'canceled') {
                return $intent;
            }
            if (!in_array($intent['status'], self::CANCELLABLE, true)) {
                throw new GatewayError("Stripe 400: This PaymentIntent's status is {$intent['status']} and cannot be canceled", 400, 'payment_intent_unexpected_state');
            }
            $intent['status'] = 'canceled';
            $intent['qr'] = null;
            return $intent;
        });
    }

    /** Test control: changes an intent (e.g. status => succeeded) and returns it. */
    public static function change(string $file, string $intentId, array $changes): array
    {
        return (new self($file))->with(static function (array &$state) use ($intentId, $changes): array {
            $state['intents'][$intentId] = $changes + ($state['intents'][$intentId] ?? []);
            if (($changes['status'] ?? '') !== 'requires_action') {
                $state['intents'][$intentId]['qr'] = $changes['qr'] ?? null;
            }
            return $state['intents'][$intentId];
        });
    }

    /** Test control: the next call of $operation ('create', 'retrieve', 'cancel') fails like a network error. */
    public static function failNext(string $file, string $operation): void
    {
        (new self($file))->with(static function (array &$state) use ($operation): void {
            $state['fail'][$operation] = true;
        });
    }

    /** Test control: how many times each operation was called. */
    public static function calls(string $file): array
    {
        return (new self($file))->with(static fn (array &$state): array => $state['calls'] ?? []);
    }

    private static function failIfAsked(array &$state, string $operation): void
    {
        if (!empty($state['fail'][$operation])) {
            unset($state['fail'][$operation]);
            throw new GatewayError('Stripe did not answer (network or timeout)');
        }
    }

    private function with(callable $work): mixed
    {
        $handle = fopen($this->file, 'c+');
        if ($handle === false) {
            throw new GatewayError('Fake gateway file cannot be opened');
        }
        try {
            flock($handle, LOCK_EX);
            $state = json_decode((string) stream_get_contents($handle), true) ?: [];
            try {
                return $work($state);
            } finally {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, json_encode($state));
                fflush($handle);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
