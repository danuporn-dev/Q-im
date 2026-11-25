<?php
declare(strict_types=1);

namespace App\Identity;

use App\Support\Env;
use App\Support\HttpError;

/** Local stand-in for LINE Login. Works only with APP_ENV=development. */
final class DevMockIdentity implements IdentityProvider
{
    /** Offered on the customer page as one-tap sign-in. */
    public const CUSTOMERS = ['CUSTOMER_1' => 'มินท์', 'CUSTOMER_2' => 'ต้นกล้า', 'CUSTOMER_3' => 'ฟ้าใส'];

    public function verify(string $credential): Identity
    {
        if (!Env::isDevelopment()) {
            throw HttpError::notFound();
        }
        if (!preg_match('/^[A-Z0-9_]{1,40}$/D', $credential)) {
            throw new HttpError(401, 'INVALID_IDENTITY', 'ไม่สามารถยืนยันตัวตนได้');
        }
        return new Identity('DEMO_' . $credential, self::CUSTOMERS[$credential] ?? 'Demo ' . ucwords(strtolower(str_replace('_', ' ', $credential))));
    }
}
