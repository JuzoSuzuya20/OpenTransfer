<?php

$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    /*
     * Reverse proxies whose X-Forwarded-For is believed, comma separated.
     *
     * Always an array: when this is null, Laravel trusts everyone as soon as the
     * Host header ends with .on-forge.com or .on-vapor.com, and anyone can send that.
     *
     * "*" means the direct peer, whoever it is. Laravel's own "*" trusts every
     * address of the chain and ends up returning the left-most one, written by the client.
     */
    'proxies' => match ($proxies) {
        '' => [],
        '*' => ['REMOTE_ADDR'],
        default => array_values(array_filter(array_map('trim', explode(',', $proxies)))),
    },
];
