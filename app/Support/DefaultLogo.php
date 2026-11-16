<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * The logo is a square mark, without a word on it: the name next to it is the one the admin chose
 * (application_name). An instance that kept a logo it was shipped with gets the square one at its
 * next start (refresh-default-logo, start-container); a logo an admin put in its place stays.
 */
final class DefaultLogo
{
    // SHA-256 of the logos shipped as images/logo.png
    public const SHIPPED = [
        '7d0dde59dfa74e60aa990c36e616c2130ba612ef2e869863dde6b9719f19731c', // OpenTransfer, square (1.5.1)
        'ab4413bc0a608b61bd164da038a0927c63c59882ab4b0ba9aa3aea1d20fb4093', // OpenTransfer, banner (up to 1.5.0)
        '768ff36a5c585ee6a643ccd039c82cb126ab040ed11207d2053b50b4f8bef3ee', // Erugo, for an instance moved from it
    ];

    public static function isShipped(string $contents): bool
    {
        return in_array(hash('sha256', $contents), self::SHIPPED, true);
    }

    public static function refresh(): void
    {
        $square = @file_get_contents(base_path('storage_skell/app/public/images/_default-logo.png'));
        if ($square === false) {
            return;
        }

        $disk = Storage::disk('public');
        // What the reset of the logo restores
        $disk->put('images/_default-logo.png', $square);
        if (!$disk->exists('images/logo.png') || self::isShipped($disk->get('images/logo.png'))) {
            $disk->put('images/logo.png', $square);
        }

        // The name the field of the logo showed for the shipped one in Erugo
        Setting::where('key', 'logo')->where('value', 'erugo-logo.png')->update(['value' => 'logo.png']);
    }
}
