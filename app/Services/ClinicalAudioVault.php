<?php

namespace App\Services;

use RuntimeException;

final class ClinicalAudioVault
{
    private const MAGIC = "ENSHAAUD1";
    private const BLOCK = 65536;

    public function sealChunks(array $chunkPaths, string $destination): array
    {
        $directory = dirname($destination);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('ساخت پوشه امن صوت ناموفق بود.');
        }

        $output = fopen($destination, 'xb');
        if (! $output) {
            throw new RuntimeException('ایجاد فایل رمزگذاری‌شده ناموفق بود.');
        }
        chmod($destination, 0600);

        $plainHash = hash_init('sha256');
        $plainBytes = 0;
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key());
            $this->writeAll($output, self::MAGIC.$header);
            foreach ($chunkPaths as $chunkPath) {
                $input = fopen($chunkPath, 'rb');
                if (! $input) {
                    throw new RuntimeException('خواندن یکی از قطعات صوت ناموفق بود.');
                }
                try {
                    while (! feof($input)) {
                        $plain = fread($input, self::BLOCK);
                        if ($plain === false) {
                            throw new RuntimeException('خواندن جریان صوت ناموفق بود.');
                        }
                        if ($plain === '') {
                            continue;
                        }
                        $plainBytes += strlen($plain);
                        hash_update($plainHash, $plain);
                        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain);
                        $this->writeFrame($output, $cipher);
                    }
                } finally {
                    fclose($input);
                }
            }
            $final = sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                '',
                '',
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
            );
            $this->writeFrame($output, $final);
        } catch (\Throwable $e) {
            fclose($output);
            @unlink($destination);
            throw $e;
        }
        fclose($output);

        return [
            'size_bytes' => $plainBytes,
            'sha256' => hash_file('sha256', $destination),
            'plaintext_sha256' => hash_final($plainHash),
        ];
    }

    public function stream(string $source, callable $consumer): void
    {
        $input = fopen($source, 'rb');
        if (! $input) {
            throw new RuntimeException('فایل صوت امن قابل خواندن نیست.');
        }
        try {
            $magic = $this->readExact($input, strlen(self::MAGIC));
            if (! hash_equals(self::MAGIC, $magic)) {
                throw new RuntimeException('قالب فایل صوت معتبر نیست.');
            }
            $header = $this->readExact($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key());
            $finalSeen = false;
            while (! feof($input)) {
                $lengthBytes = fread($input, 4);
                if ($lengthBytes === '' && feof($input)) {
                    break;
                }
                if ($lengthBytes === false || strlen($lengthBytes) !== 4) {
                    throw new RuntimeException('فریم صوت رمزگذاری‌شده ناقص است.');
                }
                $length = unpack('Nlength', $lengthBytes)['length'];
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > self::BLOCK + 1024) {
                    throw new RuntimeException('اندازه فریم صوت معتبر نیست.');
                }
                $cipher = $this->readExact($input, $length);
                $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($pulled === false) {
                    throw new RuntimeException('اصالت فایل صوت تأیید نشد.');
                }
                [$plain, $tag] = $pulled;
                if ($plain !== '') {
                    $consumer($plain);
                }
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $finalSeen = true;
                    break;
                }
            }
            if (! $finalSeen) {
                throw new RuntimeException('پایان امن فایل صوت پیدا نشد.');
            }
        } finally {
            fclose($input);
        }
    }

    public function decryptToTemporary(string $source): string
    {
        $directory = storage_path('app/private/ensha-audio-tmp');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('ساخت پوشه موقت امن ناموفق بود.');
        }
        $temporary = tempnam($directory, 'audio-');
        if ($temporary === false) {
            throw new RuntimeException('ساخت فایل موقت ناموفق بود.');
        }
        chmod($temporary, 0600);
        $output = fopen($temporary, 'wb');
        try {
            $this->stream($source, fn (string $plain) => $this->writeAll($output, $plain));
        } catch (\Throwable $e) {
            fclose($output);
            @unlink($temporary);
            throw $e;
        }
        fclose($output);
        return $temporary;
    }

    private function key(): string
    {
        $appKey = (string) config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false) {
                $appKey = $decoded;
            }
        }
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY برای رمزگذاری صوت تنظیم نشده است.');
        }
        return hash_hkdf('sha256', $appKey, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, 'ensha-clinical-audio-v1');
    }

    private function writeFrame($stream, string $payload): void
    {
        $this->writeAll($stream, pack('N', strlen($payload)).$payload);
    }

    private function writeAll($stream, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('نوشتن فایل صوت امن ناموفق بود.');
            }
            $offset += $written;
        }
    }

    private function readExact($stream, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && ! feof($stream)) {
            $part = fread($stream, $length - strlen($data));
            if ($part === false) {
                throw new RuntimeException('خواندن فایل صوت امن ناموفق بود.');
            }
            $data .= $part;
        }
        if (strlen($data) !== $length) {
            throw new RuntimeException('فایل صوت امن ناقص است.');
        }
        return $data;
    }
}
