<?php

declare(strict_types=1);

namespace SampleWeb;

use DateTimeImmutable;
use DateTimeZone;

/** The debug entries, newest first. The same shape as in the other language samples, which public/app.js renders. */
final class DebugLog
{
    public function __construct(private readonly JsonList $list) {}

    /** @param array<string, mixed> $fields */
    public function add(string $kind, string $title, array $fields = []): void
    {
        $entry = [
            'at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'kind' => $kind,
            'title' => $title,
            'requestHeaders' => (object) [],
            'responseHeaders' => (object) [],
        ];
        foreach ($fields as $key => $value) {
            if ($value !== null) {
                $entry[$key] = $value;
            }
        }
        $this->list->prepend($entry);
    }

    public function info(string $title, ?string $body = null): void
    {
        $this->add('info', $title, ['responseBody' => $body]);
    }

    /** @return list<array<string, mixed>> */
    public function recent(): array
    {
        return $this->list->all();
    }

    public function clear(): void
    {
        $this->list->clear();
    }

    public static function pretty(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return $text;
        }
        $decoded = json_decode($text);
        if ($decoded === null && trim($text) !== 'null') {
            return $text;
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $text;
    }
}
