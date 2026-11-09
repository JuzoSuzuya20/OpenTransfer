<?php

namespace App\Services;

use RuntimeException;

/**
 * Reads one entry of a zip archive held in an encrypted file, as ZipArchive
 * needs a plain file on disk. Handles stored and deflated entries and ZIP64
 * archives, which is what ZipArchive writes. Adapted from the
 * moutonnoireu/erugo-security fork.
 */
class ZipEntryReader
{
    private const END = "PK\x05\x06";
    private const ZIP64_LOCATOR = "PK\x06\x07";
    private const ZIP64_END = "PK\x06\x06";
    private const CENTRAL = "PK\x01\x02";
    private const LOCAL = "PK\x03\x04";

    private const STORED = 0;
    private const DEFLATED = 8;

    private const LONGEST_END = 65557;
    private const STREAM_BLOCK = 262144;

    /** @var array<string, array{method: int, compressed: int, size: int, offset: int}>|null */
    private ?array $entries = null;

    public function __construct(private EncryptedFileReader $source)
    {
    }

    /**
     * @return array{method: int, compressed: int, size: int, offset: int}|null
     */
    public function find(string $name): ?array
    {
        return $this->entries()[$name] ?? null;
    }

    /**
     * The uncompressed content of an entry.
     *
     * @param array{method: int, compressed: int, size: int, offset: int} $entry
     * @return \Generator<string>
     */
    public function stream(array $entry): \Generator
    {
        $local = $this->source->read($entry['offset'], 30);
        if (strlen($local) !== 30 || !str_starts_with($local, self::LOCAL)) {
            throw new RuntimeException('Invalid zip local header');
        }

        // The local header has its own lengths, which can differ from the central directory's
        $lengths = unpack('vname/vextra', substr($local, 26, 4));
        $start = $entry['offset'] + 30 + $lengths['name'] + $lengths['extra'];

        $inflate = match ($entry['method']) {
            self::STORED => null,
            self::DEFLATED => inflate_init(ZLIB_ENCODING_RAW),
            default => throw new RuntimeException('Unsupported zip compression method '.$entry['method']),
        };

        $done = 0;
        while ($done < $entry['compressed']) {
            $block = $this->source->read($start + $done, min(self::STREAM_BLOCK, $entry['compressed'] - $done));
            if ($block === '') {
                throw new RuntimeException('Unexpected end of zip data');
            }
            $done += strlen($block);

            if (!$inflate) {
                yield $block;
                continue;
            }

            $plain = inflate_add($inflate, $block, $done >= $entry['compressed'] ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($plain === false) {
                throw new RuntimeException('Corrupted zip entry');
            }
            if ($plain !== '') {
                yield $plain;
            }
        }
    }

    /**
     * @return array<string, array{method: int, compressed: int, size: int, offset: int}>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        [$count, $directorySize, $directoryOffset] = $this->centralDirectory();
        $directory = $this->source->read($directoryOffset, $directorySize);

        $entries = [];
        $position = 0;
        for ($i = 0; $i < $count; $i++) {
            if (substr($directory, $position, 4) !== self::CENTRAL) {
                throw new RuntimeException('Invalid zip central directory');
            }

            $f = unpack(
                'vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset',
                substr($directory, $position + 10, 36)
            );
            $name = substr($directory, $position + 46, $f['name']);
            $extra = substr($directory, $position + 46 + $f['name'], $f['extra']);

            $entry = ['method' => $f['method'], 'size' => $f['size'], 'compressed' => $f['compressed'], 'offset' => $f['offset']];

            // ZIP64: the fields at their maximum are given, in this order, in the 0x0001 extra field
            $wide = $this->zip64Values($extra);
            foreach (['size', 'compressed', 'offset'] as $field) {
                if ($entry[$field] === 0xFFFFFFFF) {
                    $entry[$field] = array_shift($wide) ?? throw new RuntimeException('Invalid ZIP64 entry');
                }
            }

            $entries[$name] = $entry;
            $position += 46 + $f['name'] + $f['extra'] + $f['comment'];
        }

        return $this->entries = $entries;
    }

    /** @return array{0: int, 1: int, 2: int} entry count, directory size, directory offset */
    private function centralDirectory(): array
    {
        $size = $this->source->size();
        $tailLength = min($size, self::LONGEST_END);
        $tail = $this->source->read($size - $tailLength, $tailLength);

        $end = strrpos($tail, self::END);
        if ($end === false) {
            throw new RuntimeException('This is not a zip archive');
        }

        $f = unpack('vdisk/vdirectoryDisk/vdiskEntries/ventries/Vsize/Voffset', substr($tail, $end + 4, 16));
        if ($f['entries'] !== 0xFFFF && $f['size'] !== 0xFFFFFFFF && $f['offset'] !== 0xFFFFFFFF) {
            return [$f['entries'], $f['size'], $f['offset']];
        }

        // ZIP64: the locator sits right before the classic end record
        $locator = $end >= 20 ? substr($tail, $end - 20, 20) : '';
        if (!str_starts_with($locator, self::ZIP64_LOCATOR)) {
            throw new RuntimeException('ZIP64 locator not found');
        }

        $record = $this->source->read(unpack('P', substr($locator, 8, 8))[1], 56);
        if (!str_starts_with($record, self::ZIP64_END)) {
            throw new RuntimeException('Invalid ZIP64 end of central directory');
        }

        return [
            unpack('P', substr($record, 32, 8))[1],
            unpack('P', substr($record, 40, 8))[1],
            unpack('P', substr($record, 48, 8))[1],
        ];
    }

    /** @return int[] */
    private function zip64Values(string $extra): array
    {
        $position = 0;
        while ($position + 4 <= strlen($extra)) {
            $field = unpack('vid/vlength', substr($extra, $position, 4));
            if ($field['id'] === 0x0001) {
                return array_values(unpack('P*', substr($extra, $position + 4, $field['length'])) ?: []);
            }
            $position += 4 + $field['length'];
        }

        return [];
    }
}
