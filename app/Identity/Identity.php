<?php
declare(strict_types=1);

namespace App\Identity;

/** A customer identity that the server has verified. */
final class Identity
{
    public function __construct(
        public readonly string $subject,
        public readonly string $displayName,
        public readonly ?string $avatarUrl = null
    ) {
    }
}
