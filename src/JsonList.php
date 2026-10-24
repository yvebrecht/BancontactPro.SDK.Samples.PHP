<?php

declare(strict_types=1);

namespace SampleWeb;

/**
 * A capped, newest-first list kept in a JSON file. PHP serves each request in a fresh process, so the debug log and the received
 * callbacks cannot live in memory; a file with a lock is the simplest thing that survives from one request to the next.
 */
final class JsonList
{
    public function __construct(private readonly string $file, private readonly int $capacity = 300) {}

    /** @param array<string, mixed> $item */
    public function prepend(array $item): void
    {
        $this->locked(function (array $items) use ($item): array {
            array_unshift($items, $item);

            return array_slice($items, 0, $this->capacity);
        });
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $json = @file_get_contents($this->file);
        $items = $json === false ? [] : json_decode($json, true);

        return is_array($items) ? $items : [];
    }

    public function clear(): void
    {
        $this->locked(static fn(array $items): array => []);
    }

    /** @param callable(list<array<string, mixed>>): list<array<string, mixed>> $change */
    private function locked(callable $change): void
    {
        if (!is_dir(dirname($this->file))) {
            mkdir(dirname($this->file), 0777, true);
        }
        $handle = fopen($this->file, 'c+');
        if ($handle === false) {
            return;
        }
        flock($handle, LOCK_EX);
        $json = stream_get_contents($handle);
        $items = $json === '' || $json === false ? [] : json_decode($json, true);
        $items = $change(is_array($items) ? $items : []);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]');
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
