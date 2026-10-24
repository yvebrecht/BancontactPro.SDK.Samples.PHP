<?php

declare(strict_types=1);

namespace SampleWeb;

/** Server-rendered pages. The behaviour on them is in public/app.js, shared with the other language samples. */
final class Views
{
    public static function esc(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    private static function layout(string $title, string $path, string $body): string
    {
        $nav = '';
        foreach (['/' => 'Status', '/pay' => 'Payment', '/qr' => 'QR test', '/callbacks' => 'Callbacks', '/debug' => 'Debug'] as $href => $label) {
            $nav .= '<a href="' . $href . '"' . ($href === $path ? ' class="active"' : '') . '>' . $label . '</a>';
        }
        $title = self::esc($title);

        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title><link rel="stylesheet" href="/app.css"></head>
<body><header class="topbar"><strong>Bancontact Pro sample</strong><nav>{$nav}</nav></header>
<main>{$body}</main><script src="/app.js" type="module"></script></body></html>
HTML;
    }

    /** @param list<string> $values */
    private static function options(array $values, ?string $selected = null): string
    {
        $html = '';
        foreach ($values as $v) {
            $html .= '<option value="' . self::esc($v) . '"' . ($v === $selected ? ' selected' : '') . '>' . self::esc($v) . '</option>';
        }

        return $html;
    }

    /** @param list<array{string, bool}> $products */
    public static function status(string $environment, array $products, ?string $publicBaseUrl, int $port): string
    {
        $callback = ($publicBaseUrl ?? "http://localhost:{$port}") . '/bancontact/callback';
        $rows = '';
        foreach ($products as [$product, $configured]) {
            $rows .= '<tr><th>' . self::esc($product) . '</th><td>' . ($configured ? 'API key and PPID set' : 'not configured') . '</td></tr>';
        }
        $base = self::esc($publicBaseUrl ?? 'not set: Bancontact cannot reach the callback');
        $environment = self::esc($environment);
        $callback = self::esc($callback);

        return self::layout('Status', '/', <<<HTML
<h1>Status</h1>
<table>
<tr><th>Environment</th><td>{$environment}</td></tr>
<tr><th>PUBLIC_BASE_URL</th><td>{$base}</td></tr>
<tr><th>Callback URL</th><td><code>{$callback}</code></td></tr></table>
<h2>Products</h2><table>{$rows}</table>
<p class="muted">This app calls Bancontact with your credentials and is meant to stay local: it listens on localhost only.</p>
HTML);
    }

    /** @param list<string> $products */
    public static function pay(array $products): string
    {
        $options = self::options($products, 'OnDisplay');

        return self::layout('Payment', '/pay', <<<HTML
<h1>Payment</h1>
<form id="pay-form"><div class="form-grid">
<label>Product<select name="product">{$options}</select></label>
<label>Amount (euro cents)<input name="amount" type="number" min="1" value="100" required></label>
<label>Description<input name="description" value="sample payment"></label>
<label>Reference<input name="reference"></label>
<label id="pos-field" hidden>POS ID (Static QR)<input name="posId" value="pos-1"></label>
</div><button type="submit">Create payment</button></form>
<p id="pay-error" class="error"></p>
<section id="pay-result"></section>
<h2>Debug</h2><div id="debug-root"></div>
HTML);
    }

    /**
     * @param list<string> $products
     * @param array<string, string> $q
     * @param array<string, string>|null $result
     */
    public static function qr(array $products, array $q, ?array $result = null): string
    {
        $outcome = '';
        if ($result !== null && isset($result['error'])) {
            $outcome = '<p class="error">' . self::esc($result['error']) . '</p>';
        } elseif ($result !== null) {
            $env = self::esc($result['environment']);
            $payload = self::esc($result['payloadUrl']);
            $image = self::esc($result['imageUrl']);
            $outcome = <<<HTML
<p>Environment: <strong>{$env}</strong></p>
<p>Payload URL: <code>{$payload}</code></p><p>Generator URL: <code>{$image}</code></p>
<img class="qr" src="{$image}" alt="QR code">
<p class="muted">No image? Open the generator URL in a tab: a 400 means the generator rejects the payload's host.</p>
HTML;
        }
        $sel = static fn(string $name, string $value): string => $q[$name] === $value ? ' selected' : '';
        $options = self::options($products, $q['product']);
        [$amount, $description, $reference, $posId] = [self::esc($q['amount']), self::esc($q['description']), self::esc($q['reference']), self::esc($q['posId'])];
        [$fixed, $pos, $png, $svg] = [$sel('kind', 'fixed'), $sel('kind', 'pos'), $sel('format', 'PNG'), $sel('format', 'SVG')];

        return self::layout('QR test', '/qr', <<<HTML
<h1>QR test</h1>
<p>Builds the self-made payload URLs the guides describe, without an API call, with the product's PPID and the hosts of the configured environment.
Scan the QR image with the preprod test app and note whether it opens.</p>
<form method="get" action="/qr"><div class="form-grid">
<label>Product<select name="product">{$options}</select></label>
<label>Type<select name="kind"><option value="fixed"{$fixed}>Fixed amount (/t/1/{PPID})</option><option value="pos"{$pos}>Static QR till (/l/1/{PPID}/{POSId})</option></select></label>
<label>Amount (euro cents, fixed only)<input name="amount" type="number" min="1" value="{$amount}"></label>
<label>Description (fixed only)<input name="description" value="{$description}"></label>
<label>Reference (fixed only)<input name="reference" value="{$reference}"></label>
<label>POS ID (till only)<input name="posId" value="{$posId}"></label>
<label>Format<select name="format"><option{$png}>PNG</option><option{$svg}>SVG</option></select></label>
</div><button type="submit">Build QR</button></form>{$outcome}
HTML);
    }

    public static function callbacks(): string
    {
        return self::layout('Callbacks', '/callbacks', '<h1>Callbacks</h1><p class="muted">The verified callbacks, newest first. Rejected ones are on the Debug page.</p><div id="callbacks-root"></div>');
    }

    public static function debug(): string
    {
        return self::layout('Debug', '/debug', <<<'HTML'
<h1>Debug</h1>
<p class="muted">Every call the library makes to Bancontact (Authorization masked, Signature, body, response, trace IDs) and every received callback, including rejected ones.</p>
<div id="debug-root"></div>
HTML);
    }
}
