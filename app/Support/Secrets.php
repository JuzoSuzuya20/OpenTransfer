<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * The secrets the application must send on to another server (the SMTP password, the client secret
 * of an identity provider) cannot be hashed: they are encrypted with the key of the instance
 * (storage/app.key, APP_PREVIOUS_KEYS for a former one), so that a copy of the database or of a
 * backup does not give them away.
 */
final class Secrets
{
    public static function seal(string $plain): string
    {
        return Crypt::encryptString($plain);
    }

    // The secret in clear, or null: nothing stored, or encrypted with a key the instance no longer
    // has (the secret is then to be entered again)
    public static function open(?string $sealed): ?string
    {
        if ($sealed === null || $sealed === '') {
            return null;
        }
        try {
            return Crypt::decryptString($sealed);
        } catch (DecryptException) {
            Log::warning('A stored secret could not be decrypted with the key of this instance: enter it again');

            return null;
        }
    }

    public static function isSealed(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
