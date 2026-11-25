<?php
declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** An error that is safe to show to the client. */
final class HttpError extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'ไม่พบข้อมูล'): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    public static function invalid(string $message, array $details = []): self
    {
        return new self(422, 'VALIDATION_FAILED', $message, $details);
    }

    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self(409, $code, $message, $details);
    }
}
