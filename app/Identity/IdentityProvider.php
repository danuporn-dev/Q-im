<?php
declare(strict_types=1);

namespace App\Identity;

interface IdentityProvider
{
    /** Verifies the credential and throws HttpError 401 when it is not valid. */
    public function verify(string $credential): Identity;
}
