<?php

namespace App\Services;

use RuntimeException;

/**
 * Random access to a file written by StorageEncryption. Every chunk is
 * authenticated before any of its bytes are returned.
 */
class EncryptedFileReader
{
    /** @var resource */
    private $handle;
    private string $header;
    private string $prefix;
    private int $chunkSize;
    private int $size;

    private ?int $cachedIndex = null;
    private string $cachedChunk = '';

    public function __construct(string $path, private string $key, string $keyId)
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('Cannot open '.$path);
        }
        $this->handle = $handle;

        if (!StorageEncryption::isEncrypted($path)) {
            throw new RuntimeException('Not a complete encrypted file: '.$path);
        }

        $this->header = fread($handle, StorageEncryption::HEADER_SIZE);

        if (!hash_equals(substr($this->header, 40, 8), $keyId)) {
            throw new RuntimeException('This file was encrypted with another STORAGE_ENCRYPTION_KEY: '.$path);
        }

        $this->chunkSize = unpack('N', substr($this->header, 12, 4))[1];
        $this->size = unpack('J', substr($this->header, 16, 8))[1];
        $this->prefix = substr($this->header, 24, 16);
    }

    public function size(): int
    {
        return $this->size;
    }

    public function read(int $offset, int $length): string
    {
        return implode('', iterator_to_array($this->chunks($offset, $length), false));
    }

    /**
     * The plain bytes between $offset and $offset + $length, chunk by chunk.
     *
     * @return \Generator<string>
     */
    public function chunks(int $offset, int $length): \Generator
    {
        if ($offset < 0 || $length < 0) {
            throw new RuntimeException('Invalid range');
        }

        $end = min($this->size, $offset + $length);
        while ($offset < $end) {
            $index = intdiv($offset, $this->chunkSize);
            $chunk = $this->chunk($index);
            $start = $offset - $index * $this->chunkSize;
            $part = substr($chunk, $start, $end - $offset);
            yield $part;
            $offset += strlen($part);
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function chunk(int $index): string
    {
        if ($this->cachedIndex === $index) {
            return $this->cachedChunk;
        }

        $length = min($this->chunkSize, $this->size - $index * $this->chunkSize);
        fseek($this->handle, StorageEncryption::HEADER_SIZE + $index * ($this->chunkSize + StorageEncryption::TAG_SIZE));
        $cipher = StorageEncryption::readExactly($this->handle, $length + StorageEncryption::TAG_SIZE);

        $packed = pack('J', $index);
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, $this->header.$packed, $this->prefix.$packed, $this->key);
        if ($plain === false) {
            throw new RuntimeException('This encrypted file was modified or is corrupted');
        }

        $this->cachedIndex = $index;
        $this->cachedChunk = $plain;

        return $plain;
    }
}
