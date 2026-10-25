<?php

declare(strict_types=1);

namespace SampleWeb;

use BancontactPro\ClientFactory;
use BancontactPro\Credentials\Credentials;
use BancontactPro\Credentials\ProductCredentials;
use BancontactPro\Enums\Environment;
use BancontactPro\Enums\Product;
use BancontactPro\Enums\QrCodeImageFormat;
use BancontactPro\Exception\ApiException;
use BancontactPro\Exception\InvalidCallbackException;
use BancontactPro\Exception\ProductNotConfiguredException;
use BancontactPro\Models\CreatePaymentRequest;
use BancontactPro\Models\CreateStaticQrPaymentRequest;
use BancontactPro\Models\CreatePaymentResponse;
use BancontactPro\Models\Payment;
use BancontactPro\Qr\QrLinks;
use DateTimeImmutable;
use DateTimeInterface;
use GuzzleHttp\Client;
use Throwable;

/** A small web UI over the PHP client, to try a payment by hand against Bancontact's preprod and see what goes over the wire. */
final class App
{
    private const CALLBACK_PATH = '/bancontact/callback';

    private readonly DebugLog $debug;
    private readonly JsonList $callbacks;
    private readonly ClientFactory $factory;

    public function __construct(string $varDir)
    {
        $this->debug = new DebugLog(new JsonList($varDir . '/debug.json', 300));
        $this->callbacks = new JsonList($varDir . '/callbacks.json', 100);
        // Every call to Bancontact, including the JWKS fetches of the callback verifier, goes through the debug client.
        $this->factory = new ClientFactory(fn(): Credentials => self::readCredentials(), new DebugHttpClient(new Client(['timeout' => 100]), $this->debug));
    }

    public function handle(string $method, string $path): void
    {
        match (true) {
            $method === 'POST' && $path === self::CALLBACK_PATH => $this->callback($path),
            $method === 'GET' && $path === '/' => $this->html(Views::status(self::readCredentials()->environment->value, $this->productStates(), self::publicBaseUrl(), self::port())),
            $method === 'GET' && $path === '/pay' => $this->html(Views::pay(self::productNames())),
            $method === 'GET' && $path === '/qr' => $this->qr(),
            $method === 'GET' && $path === '/callbacks' => $this->html(Views::callbacks()),
            $method === 'GET' && $path === '/debug' => $this->html(Views::debug()),
            $method === 'GET' && $path === '/api/debug' => $this->json(200, $this->debug->recent()),
            $method === 'POST' && $path === '/api/debug/clear' => $this->clearDebug(),
            $method === 'GET' && $path === '/api/callbacks' => $this->callbackList(),
            $method === 'POST' && $path === '/api/payments' => $this->createPayment(),
            (bool) preg_match('#^/api/payments/([A-Za-z]+)/([^/]+)$#', $path, $m) && in_array($method, ['GET', 'DELETE'], true) => $this->onePayment($method, $m[1], rawurldecode($m[2])),
            default => $this->send(404, 'text/plain', 'Not found'),
        };
    }

    // --- configuration -------------------------------------------------------------------------------------------

    private static function port(): int
    {
        return (int) (getenv('PORT') ?: 5087);
    }

    private static function publicBaseUrl(): ?string
    {
        $url = rtrim(trim((string) getenv('PUBLIC_BASE_URL')), '/');

        return $url === '' ? null : $url;
    }

    private static function callbackUrl(): string
    {
        return (self::publicBaseUrl() ?? 'http://localhost:' . self::port()) . self::CALLBACK_PATH;
    }

    /** Credentials from the environment (or `.env`): BANCONTACT_<PRODUCT>_API_KEY and BANCONTACT_<PRODUCT>_PPID. Read on every call. */
    private static function readCredentials(): Credentials
    {
        $products = [];
        foreach (Product::cases() as $product) {
            $prefix = 'BANCONTACT_' . strtoupper($product->value);
            $apiKey = trim((string) getenv($prefix . '_API_KEY'));
            $ppid = trim((string) getenv($prefix . '_PPID'));
            if ($apiKey !== '' && $ppid !== '') {
                $products[$product->value] = new ProductCredentials($apiKey, $ppid);
            }
        }

        return new Credentials(
            environment: getenv('BANCONTACT_ENVIRONMENT') === Environment::Production->value ? Environment::Production : Environment::Preprod,
            products: $products,
        );
    }

    /** @return list<string> */
    private static function productNames(): array
    {
        return array_map(static fn(Product $p): string => $p->value, Product::cases());
    }

    /** @return list<array{string, bool}> */
    private function productStates(): array
    {
        return array_map(fn(Product $p): array => [$p->value, $this->factory->isConfigured($p)], Product::cases());
    }

    // --- pages ---------------------------------------------------------------------------------------------------

    private function qr(): void
    {
        $q = [
            'product' => $_GET['product'] ?? 'OnDisplay',
            'kind' => $_GET['kind'] ?? 'fixed',
            'amount' => $_GET['amount'] ?? '100',
            'description' => $_GET['description'] ?? 'sample',
            'reference' => $_GET['reference'] ?? '',
            'posId' => $_GET['posId'] ?? 'POS00001',
            'format' => $_GET['format'] ?? 'PNG',
        ];
        if (!isset($_GET['product'])) {
            $this->html(Views::qr(self::productNames(), $q));

            return;
        }

        try {
            $product = Product::tryFrom($q['product']) ?? throw new \InvalidArgumentException("Unknown product '{$q['product']}'.");
            $credentials = self::readCredentials();
            $ppid = $credentials->forProduct($product)?->merchantProfileId ?? throw new ProductNotConfiguredException($product);
            $environment = $credentials->environment;
            $payload = $q['kind'] === 'pos'
                ? QrLinks::staticQrLocationUrl($environment, $ppid, $q['posId'])
                : QrLinks::fixedAmountPaymentUrl($environment, $ppid, (int) $q['amount'], $q['description'] !== '' ? $q['description'] : null, $q['reference'] !== '' ? $q['reference'] : null);
            $image = QrLinks::qrCodeImageUrl($environment, $payload, $q['format'] === 'SVG' ? QrCodeImageFormat::Svg : QrCodeImageFormat::Png);
            $this->debug->info("QR payload built ({$q['kind']}, {$environment->value})", "payload: {$payload}\nimage: {$image}");
            $this->html(Views::qr(self::productNames(), $q, ['environment' => $environment->value, 'payloadUrl' => $payload, 'imageUrl' => $image]));
        } catch (Throwable $e) {
            $this->html(Views::qr(self::productNames(), $q, ['error' => $e->getMessage()]));
        }
    }

    // --- JSON API (the same contract as the other language samples) ----------------------------------------------

    private function clearDebug(): void
    {
        $this->debug->clear();
        $this->json(200, new \stdClass());
    }

    private function callbackList(): void
    {
        $id = $_GET['paymentId'] ?? null;
        $all = $this->callbacks->all();
        $this->json(200, array_values(array_filter($all, static fn(array $c): bool => $id === null || $c['payload']['paymentId'] === $id)));
    }

    private function createPayment(): void
    {
        try {
            $body = json_decode((string) file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
            $product = Product::tryFrom((string) ($body['product'] ?? ''));
            if ($product === null) {
                $this->json(400, ['error' => "Unknown product '" . ($body['product'] ?? '') . "'."]);

                return;
            }
            $payments = $this->factory->payments($product);
            $description = trim((string) ($body['description'] ?? '')) ?: null;
            $reference = trim((string) ($body['reference'] ?? '')) ?: null;
            $amount = (int) ($body['amount'] ?? 0);
            $created = $product === Product::StaticQr
                ? $payments->createStaticQrPayment(new CreateStaticQrPaymentRequest(amount: $amount, posId: trim((string) ($body['posId'] ?? '')) ?: 'POS00001', reference: $reference, description: $description, callbackUrl: self::callbackUrl()))
                : $payments->create(new CreatePaymentRequest(amount: $amount, reference: $reference, description: $description, callbackUrl: self::callbackUrl()));
            $this->json(201, self::view($created));
        } catch (Throwable $e) {
            $this->failure($e);
        }
    }

    private function onePayment(string $method, string $productName, string $paymentId): void
    {
        $product = Product::tryFrom($productName);
        if ($product === null) {
            $this->json(404, ['error' => "Unknown product '{$productName}'."]);

            return;
        }
        try {
            $payments = $this->factory->payments($product);
            if ($method === 'DELETE') {
                $payments->cancel($paymentId);
                $this->debug->info("Payment {$paymentId} cancelled");
                $this->send(204, 'text/plain', '');

                return;
            }
            $this->json(200, self::view($payments->get($paymentId)));
        } catch (Throwable $e) {
            $this->failure($e);
        }
    }

    /** What the browser needs to show and follow a payment. */
    private static function view(CreatePaymentResponse|Payment $p): array
    {
        $expires = $p instanceof CreatePaymentResponse ? $p->expiresAt : $p->expireAt;

        return [
            'paymentId' => $p->paymentId,
            'status' => self::statusValue($p->status),
            'amount' => $p->amount,
            'currency' => $p->currency,
            'expiresAt' => $expires->format(DateTimeInterface::ATOM),
            'qrcode' => $p->links?->qrcode->href ?? '',
            'deeplink' => $p->links?->deeplink->href ?? '',
        ];
    }

    private static function statusValue(\BackedEnum|string $status): string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : $status;
    }

    private function failure(Throwable $e): void
    {
        if ($e instanceof ProductNotConfiguredException) {
            $this->json(400, ['error' => $e->getMessage()]);
        } elseif ($e instanceof ApiException) {
            $this->json($e->statusCode >= 400 ? $e->statusCode : 502, ['error' => "Bancontact returned {$e->statusCode} {$e->errorCode}: {$e->getMessage()}"]);
        } else {
            $this->json(502, ['error' => $e::class . ': ' . $e->getMessage()]);
        }
    }

    // --- callback ------------------------------------------------------------------------------------------------

    /** Bancontact calls this when a payment changes. Every call is logged, valid or not, with the Signature header and the exact body. */
    private function callback(string $path): void
    {
        $body = (string) file_get_contents('php://input');
        $received = (object) (function_exists('getallheaders') ? getallheaders() : []);
        $analysis = Diagnostics::analyseSignature($_SERVER['HTTP_SIGNATURE'] ?? '', $body);
        try {
            // The exact bytes received: re-serialising a parsed body first would change them and break the signature.
            $verified = $this->factory->callbackVerifier()->verifyAndParse($_SERVER['HTTP_SIGNATURE'] ?? '', $body, $path);
        } catch (InvalidCallbackException $e) {
            $this->debug->add('callback', 'Callback rejected', ['method' => 'POST', 'url' => $path, 'status' => 401, 'requestHeaders' => $received, 'requestBody' => DebugLog::pretty($body), 'rawRequestBody' => $body, 'analysis' => $analysis, 'error' => $e->getMessage()]);
            $this->send(401, 'text/plain', '');

            return;
        }

        $payload = $verified->payload;
        $status = self::statusValue($payload->status);
        $this->callbacks->prepend([
            'receivedAt' => (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'requestId' => $verified->requestId,
            'payload' => [
                'paymentId' => $payload->paymentId,
                'status' => $status,
                'amount' => $payload->amount,
                'currency' => $payload->currency,
                'debtor' => ['name' => $payload->debtor->name, 'iban' => $payload->debtor->iban],
            ],
        ]);
        $this->debug->add('callback', "Callback {$status} for {$payload->paymentId}", [
            'method' => 'POST',
            'url' => $path,
            'status' => 200,
            'requestHeaders' => $received,
            'requestBody' => DebugLog::pretty($body),
            'rawRequestBody' => $body,
            'analysis' => $analysis,
            'responseBody' => "Signature valid. Bancontact request ID: {$verified->requestId}",
        ]);
        $this->send(200, 'text/plain', '');
    }

    // --- responses -----------------------------------------------------------------------------------------------

    private function html(string $html): void
    {
        $this->send(200, 'text/html; charset=utf-8', $html);
    }

    private function json(int $status, mixed $data): void
    {
        $this->send($status, 'application/json; charset=utf-8', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');
    }

    private function send(int $status, string $type, string $body): void
    {
        http_response_code($status);
        header('Content-Type: ' . $type);
        echo $body;
    }
}
