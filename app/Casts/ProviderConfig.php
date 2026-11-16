<?php

namespace App\Casts;

use App\Support\Secrets;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * The configuration of an identity provider, an object as before, whose secrets are stored
 * encrypted (App\Support\Secrets): the providers read them in clear, the database never holds them so.
 */
final class ProviderConfig implements CastsAttributes
{
    public const SECRETS = ['client_secret'];

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        $config = $value === null ? null : json_decode($value);
        if (!is_object($config)) {
            return $config;
        }
        foreach (self::SECRETS as $name) {
            if (isset($config->$name) && is_string($config->$name) && $config->$name !== '') {
                $config->$name = Secrets::open($config->$name) ?? '';
            }
        }

        return $config;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }
        $config = json_decode(json_encode($value));
        $stored = isset($attributes[$key]) && is_string($attributes[$key]) ? json_decode($attributes[$key]) : null;
        foreach (self::SECRETS as $name) {
            $plain = $config->$name ?? null;
            if (!is_string($plain) || $plain === '') {
                continue;
            }
            // The same secret keeps its encrypted form: saving the provider again changes nothing
            $before = is_object($stored) && is_string($stored->$name ?? null) ? $stored->$name : null;
            $config->$name = $before !== null && Secrets::open($before) === $plain ? $before : Secrets::seal($plain);
        }

        return [$key => json_encode($config)];
    }
}
