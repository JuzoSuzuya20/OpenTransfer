<?php

use App\Casts\ProviderConfig;
use App\Models\Setting;
use App\Support\Secrets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * What an existing instance holds in clear: the SMTP password (and the former one, kept next to
 * it), the client secrets of its identity providers, the codes of self-registrations waiting for
 * their address to be confirmed. Encrypted or hashed once: run again, nothing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('settings')->whereIn('key', Setting::SECRETS)->get(['id', 'value']) as $row) {
            $value = $row->value;
            DB::table('settings')->where('id', $row->id)->update([
                'value' => is_string($value) && $value !== '' && !Secrets::isSealed($value) ? Secrets::seal($value) : $value,
                'previous_value' => null,
            ]);
        }

        foreach (DB::table('auth_providers')->get(['id', 'provider_config']) as $row) {
            $config = json_decode((string) $row->provider_config);
            if (!is_object($config)) {
                continue;
            }
            $changed = false;
            foreach (ProviderConfig::SECRETS as $name) {
                $value = $config->$name ?? null;
                if (is_string($value) && $value !== '' && !Secrets::isSealed($value)) {
                    $config->$name = Secrets::seal($value);
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('auth_providers')->where('id', $row->id)->update(['provider_config' => json_encode($config)]);
            }
        }

        // The code sent before the update keeps working: it is the same, hashed
        foreach (DB::table('users')->whereNotNull('email_verification_code')->get(['id', 'email_verification_code']) as $row) {
            if (!Hash::isHashed($row->email_verification_code)) {
                DB::table('users')->where('id', $row->id)->update(['email_verification_code' => Hash::make($row->email_verification_code)]);
            }
        }
    }

    // Back to a version that read them in clear. The codes stay hashed: a new one is to be asked for.
    public function down(): void
    {
        foreach (DB::table('settings')->whereIn('key', Setting::SECRETS)->get(['id', 'value']) as $row) {
            if (Secrets::isSealed($row->value)) {
                DB::table('settings')->where('id', $row->id)->update(['value' => Secrets::open($row->value)]);
            }
        }
        foreach (DB::table('auth_providers')->get(['id', 'provider_config']) as $row) {
            $config = json_decode((string) $row->provider_config);
            if (!is_object($config)) {
                continue;
            }
            foreach (ProviderConfig::SECRETS as $name) {
                if (is_string($config->$name ?? null) && Secrets::isSealed($config->$name)) {
                    $config->$name = Secrets::open($config->$name);
                }
            }
            DB::table('auth_providers')->where('id', $row->id)->update(['provider_config' => json_encode($config)]);
        }
    }
};
