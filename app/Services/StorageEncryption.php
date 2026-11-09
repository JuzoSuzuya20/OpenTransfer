<?php

namespace App\Services;

use RuntimeException;

/**
 * Optional encryption at rest of shared files (libsodium XChaCha20-Poly1305),
 * adapted from the moutonnoireu/erugo-security fork.
 *
 * The key is never stored by the application: it comes from the
 * STORAGE_ENCRYPTION_KEY environment variable (32 random bytes, base64), so a
 * copy of the storage volume or of a backup is useless without it. Losing the
 * key means losing the encrypted files.
 *
 * File format (integers are big-endian):
 *   header  magic | version u8 | 3 reserved bytes | chunk size u32
 *           | plain size u64 | nonce prefix (16) | key id (8)         = 48 bytes
 *   chunk i AEAD(plain[i], nonce = prefix || u64 i, aad = header || u64 i)
 *
 * The header is authenticated with every chunk, the index prevents reordering,
 * and the size in the header fixes the number of chunks, so truncation is
 * detected. Chunks are independent, which allows random access for HTTP range
 * requests and for reading one file out of an archive.
 */
class StorageEncryption
{
    public const SETTING_ENABLED = 'storage_encryption_enabled';

    public const MAGIC = 'OPENTFIL';
    public const VERSION = 1;
    public const HEADER_SIZE = 48;
    public const CHUNK_SIZE = 1048576;
    public const TAG_SIZE = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    private const KEY_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

    public function __construct(private SettingsService $settings)
    {
    }

    public function hasKey(): bool
    {
        return $this->derivedKey() !== null;
    }

    /** New shares are encrypted: the admin setting is on and a key is available. */
    public function isEnabled(): bool
    {
        return $this->isRequested() && $this->hasKey();
    }

    /** The admin setting is on but there is no valid key. */
    public function isMisconfigured(): bool
    {
        return $this->isRequested() && !$this->hasKey();
    }

    private function isRequested(): bool
    {
        return $this->settings->get(self::SETTING_ENABLED) === true;
    }

    /**
     * Beyond the magic, the size must match what the header announces: a file
     * that merely starts like an encrypted one stays what it is.
     */
    public static function isEncrypted(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $header = fread($handle, self::HEADER_SIZE);
        fclose($handle);

        if ($header === false || strlen($header) !== self::HEADER_SIZE || !str_starts_with($header, self::MAGIC)) {
            return false;
        }

        clearstatcache(true, $path);

        return ord($header[8]) === self::VERSION && filesize($path) === self::expectedSize($header);
    }

    /** What a file with this header must weigh. */
    public static function expectedSize(string $header): int
    {
        $chunkSize = unpack('N', substr($header, 12, 4))[1];
        $plainSize = unpack('J', substr($header, 16, 8))[1];

        if ($chunkSize < 1 || $plainSize < 0) {
            return -1;
        }

        return self::HEADER_SIZE + $plainSize + max(1, intdiv($plainSize + $chunkSize - 1, $chunkSize)) * self::TAG_SIZE;
    }

    /**
     * Encrypts in place, through a temporary file: at any moment the path holds
     * either the whole original or the whole encrypted version.
     */
    public function encryptFile(string $path): void
    {
        $key = $this->requireKey();
        clearstatcache(true, $path);
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('Cannot read the size of '.$path);
        }

        $prefix = random_bytes(16);
        $header = self::MAGIC
            .chr(self::VERSION)."\0\0\0"
            .pack('N', self::CHUNK_SIZE)
            .pack('J', $size)
            .$prefix
            .$this->keyId($key);

        $this->rewrite($path, '.enc-tmp', function ($out) use ($path, $size, $header, $prefix, $key) {
            $in = fopen($path, 'rb');
            if (!$in) {
                throw new RuntimeException('Cannot open '.$path);
            }

            try {
                self::write($out, $header);
                $chunks = max(1, intdiv($size + self::CHUNK_SIZE - 1, self::CHUNK_SIZE));
                for ($i = 0; $i < $chunks; $i++) {
                    $plain = self::readExactly($in, min(self::CHUNK_SIZE, $size - $i * self::CHUNK_SIZE));
                    $index = pack('J', $i);
                    self::write($out, sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $header.$index, $prefix.$index, $key));
                }
            } finally {
                fclose($in);
            }
        });
    }

    public function decryptFile(string $path): void
    {
        $reader = $this->open($path);

        try {
            $this->rewrite($path, '.dec-tmp', function ($out) use ($reader) {
                foreach ($reader->chunks(0, $reader->size()) as $plain) {
                    self::write($out, $plain);
                }
            });
        } finally {
            $reader->close();
        }
    }

    public function open(string $path): EncryptedFileReader
    {
        $key = $this->requireKey();

        return new EncryptedFileReader($path, $key, $this->keyId($key));
    }

    /**
     * A key suitable for STORAGE_ENCRYPTION_KEY.
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(self::KEY_BYTES));
    }

    private function rewrite(string $path, string $suffix, callable $writer): void
    {
        $tmp = $path.$suffix;
        $out = fopen($tmp, 'wb');
        if (!$out) {
            throw new RuntimeException('Cannot write next to '.$path);
        }

        try {
            $writer($out);
            fflush($out);
            fclose($out);
            $out = null;

            if (!rename($tmp, $path)) {
                throw new RuntimeException('Cannot replace '.$path);
            }
        } catch (\Throwable $e) {
            if ($out) {
                fclose($out);
            }
            @unlink($tmp);
            throw $e;
        }
    }

    /** A sub-key dedicated to this use, from the key given by the admin. */
    private function derivedKey(): ?string
    {
        $raw = trim((string) config('app.storage_encryption_key', ''));
        $decoded = base64_decode($raw, true);

        if ($decoded === false || strlen($decoded) !== self::KEY_BYTES) {
            return null;
        }

        return sodium_crypto_kdf_derive_from_key(self::KEY_BYTES, 1, 'otfiles1', $decoded);
    }

    private function requireKey(): string
    {
        return $this->derivedKey() ?? throw new RuntimeException('STORAGE_ENCRYPTION_KEY is missing or invalid (expected 32 bytes, base64)');
    }

    private function keyId(string $key): string
    {
        return substr(hash('sha256', 'opentransfer-key-id'.$key, true), 0, 8);
    }

    /** @param resource $handle */
    private static function write($handle, string $bytes): void
    {
        if (fwrite($handle, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Write failed, the disk may be full');
        }
    }

    /** @param resource $handle */
    public static function readExactly($handle, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($handle)) {
            $part = fread($handle, $length - strlen($data));
            if ($part === false || $part === '') {
                break;
            }
            $data .= $part;
        }

        if (strlen($data) !== $length) {
            throw new RuntimeException('Unexpected end of file');
        }

        return $data;
    }
}
