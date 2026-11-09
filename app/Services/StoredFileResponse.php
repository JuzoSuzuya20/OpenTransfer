<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The download of a stored share file, encrypted at rest or not. Plain files
 * keep Laravel's download response; encrypted files are decrypted on the fly,
 * with single byte ranges so that interrupted downloads can resume.
 *
 * Whatever cannot be opened fails here, before a single byte or header leaves:
 * callers count the download once they have a response.
 */
class StoredFileResponse
{
    public function __construct(private StorageEncryption $encryption)
    {
    }

    public function download(Request $request, string $path, string $name, ?string $type = null): Response
    {
        if (!StorageEncryption::isEncrypted($path)) {
            return response()->download($path, $name);
        }

        $reader = $this->encryption->open($path);
        $size = $reader->size();
        [$start, $end, $status] = $this->range($request->header('Range'), $size);

        if ($status === 416) {
            $reader->close();

            return response('', 416, ['Content-Range' => "bytes */{$size}"]);
        }

        $headers = $this->headers($name, $type, $size === 0 ? 0 : $end - $start + 1) + ['Accept-Ranges' => 'bytes'];
        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return new StreamedResponse(function () use ($reader, $start, $end, $size) {
            if ($size > 0) {
                $this->send($reader->chunks($start, $end - $start + 1));
            }
            $reader->close();
        }, $status, $headers);
    }

    /**
     * @param array{method: int, compressed: int, size: int, offset: int} $entry
     */
    public function zipEntry(ZipEntryReader $zip, array $entry, string $name, ?string $type = null): Response
    {
        return new StreamedResponse(
            fn () => $this->send($zip->stream($entry)),
            200,
            $this->headers($name, $type, $entry['size'])
        );
    }

    private function send(\Generator $parts): void
    {
        foreach ($parts as $part) {
            echo $part;
            flush();
            if (connection_aborted()) {
                break;
            }
        }
    }

    private function headers(string $name, ?string $type, int $length): array
    {
        return [
            'Content-Type' => $type ?: 'application/octet-stream',
            'Content-Length' => (string) $length,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $name,
                str_replace(['%', '/', '\\'], '_', preg_replace('/[^\x20-\x7E]/', '_', $name)) ?: 'download'
            ),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /**
     * A single "bytes=" range. Anything else, several ranges included, is
     * answered with the whole file, which the HTTP specification allows.
     *
     * @return array{0: int, 1: int, 2: int} start, end (inclusive), status
     */
    private function range(?string $header, int $size): array
    {
        $whole = [0, max(0, $size - 1), 200];

        if (!$header || $size === 0 || !preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $match) || $match[1].$match[2] === '') {
            return $whole;
        }

        if ($match[1] === '') {
            $length = (int) $match[2];

            return $length === 0 ? [0, 0, 416] : [max(0, $size - $length), $size - 1, 206];
        }

        $start = (int) $match[1];
        $end = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);

        return $start >= $size || $start > $end ? [0, 0, 416] : [$start, $end, 206];
    }
}
