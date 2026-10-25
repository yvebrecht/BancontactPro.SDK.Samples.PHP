<?php

declare(strict_types=1);

namespace SampleWeb;

/**
 * Describes what a callback's Signature header looks like, so a rejected callback can be understood without a debugger: how
 * many dots it has, what the JOSE header says, how big the body is. It verifies nothing.
 */
final class Diagnostics
{
    public static function analyseSignature(string $signatureHeader, string $body): string
    {
        if (trim($signatureHeader) === '') {
            return 'No Signature header was sent.';
        }

        $parts = explode('.', $signatureHeader);
        $form = match (true) {
            count($parts) === 2 => 'header.signature (one dot)',
            count($parts) === 3 && $parts[1] === '' => 'header..signature (two dots, empty payload segment)',
            count($parts) === 3 => 'header.payload.signature (a non-empty middle segment)',
            default => (count($parts) - 1) . ' dots',
        };
        $lines = ['Signature header: ' . strlen($signatureHeader) . " characters, form {$form}.", 'Body: ' . strlen($body) . ' bytes.'];

        $header = json_decode(self::decode($parts[0]) ?? '', true);
        if (is_array($header)) {
            $lines[] = 'JOSE header:';
            $lines[] = (string) json_encode($header, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $lines[] = 'The first segment is not a base64url JSON header.';
        }

        $signature = self::decode($parts[count($parts) - 1]);
        $lines[] = $signature === null
            ? 'The last segment is not valid base64url.'
            : 'Signature segment: ' . strlen($signature) . ' bytes (ES256 expects 64).';

        return implode("\n", $lines);
    }

    private static function decode(string $text): ?string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}