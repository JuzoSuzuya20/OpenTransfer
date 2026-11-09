<?php

namespace Tests\Support;

use OpenSSLAsymmetricKey;

/**
 * A WebAuthn authenticator in software: real keys and real signatures, so that
 * tests go through the whole verification instead of stubbing it. Takes the
 * options the server sends and answers like navigator.credentials does.
 */
class SoftwareAuthenticator
{
    private const USER_PRESENT = 0x01;
    private const USER_VERIFIED = 0x04;
    private const ATTESTED_CREDENTIAL = 0x40;

    public string $credentialId;
    public int $counter = 0;
    private OpenSSLAsymmetricKey $key;

    public function __construct(
        public string $rpId = 'localhost',
        public string $origin = 'http://localhost',
    ) {
        $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->credentialId = random_bytes(32);
    }

    /** The same credential id with another key: what an impostor would have. */
    public function impostor(): self
    {
        $other = new self($this->rpId, $this->origin);
        $other->credentialId = $this->credentialId;

        return $other;
    }

    public function register(array $options): array
    {
        $clientData = $this->clientData('webauthn.create', $options['challenge']);

        $authData = hash('sha256', $this->rpId, true)
            .chr(self::USER_PRESENT | self::USER_VERIFIED | self::ATTESTED_CREDENTIAL)
            .pack('N', $this->counter)
            .str_repeat("\0", 16) // AAGUID
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .$this->coseKey();

        // CBOR: {"fmt": "none", "attStmt": {}, "authData": <bytes>}
        $attestation = "\xA3"
            ."\x63fmt\x64none"
            ."\x67attStmt\xA0"
            ."\x68authData\x59".pack('n', strlen($authData)).$authData;

        return [
            'id' => $this->encode($this->credentialId),
            'rawId' => $this->encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->encode($clientData),
                'attestationObject' => $this->encode($attestation),
                'transports' => ['internal'],
            ],
        ];
    }

    public function assert(array $options, ?string $userHandle = null, bool $userVerified = true): array
    {
        $clientData = $this->clientData('webauthn.get', $options['challenge']);

        $this->counter++;
        $flags = self::USER_PRESENT | ($userVerified ? self::USER_VERIFIED : 0);
        $authData = hash('sha256', $this->rpId, true).chr($flags).pack('N', $this->counter);

        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => $this->encode($this->credentialId),
            'rawId' => $this->encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->encode($clientData),
                'authenticatorData' => $this->encode($authData),
                'signature' => $this->encode($signature),
                'userHandle' => $userHandle === null ? null : $this->encode($userHandle),
            ],
        ];
    }

    private function clientData(string $type, string $challenge): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $this->origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
    }

    /** CBOR: {1: 2 (EC2), 3: -7 (ES256), -1: 1 (P-256), -2: x, -3: y} */
    private function coseKey(): string
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];

        return "\xA5\x01\x02\x03\x26\x20\x01"
            ."\x21\x58\x20".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)
            ."\x22\x58\x20".str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    private function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
