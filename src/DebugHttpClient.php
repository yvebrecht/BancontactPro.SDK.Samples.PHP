<?php

declare(strict_types=1);

namespace SampleWeb;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A PSR-18 decorator that records every request the library sends to Bancontact and the response it gets.
 *
 * The library takes any PSR-18 client (`new ClientFactory($credentials, http: ...)`) and uses it for the API calls and for the JWKS
 * fetches alike. It adds the Authorization and Signature headers before it calls the client, so the log shows the request as it goes
 * out on the wire. The API key itself is masked; everything else is shown as it is.
 */
final class DebugHttpClient implements ClientInterface
{
    public function __construct(private readonly ClientInterface $inner, private readonly DebugLog $log) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $started = hrtime(true);
        $requestBody = self::read($request->getBody());
        $common = [
            'title' => $request->getMethod() . ' ' . $request->getUri()->getPath(),
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'requestHeaders' => (object) self::headers($request->getHeaders()),
            'requestBody' => $requestBody === '' ? null : DebugLog::pretty($requestBody),
        ];

        try {
            $response = $this->inner->sendRequest($request);
        } catch (Throwable $e) {
            $this->log->add('request', $common['title'], $common + [
                'elapsedMs' => (int) round((hrtime(true) - $started) / 1e6),
                'error' => $e::class . ': ' . $e->getMessage(),
            ]);
            throw $e;
        }

        // Read it here, and give the library a body it can still read.
        $text = (string) $response->getBody();
        $response = $response->withBody(Utils::streamFor($text));
        $ids = [];
        if ($response->getStatusCode() >= 400) {
            $parsed = json_decode($text, true);
            foreach (['traceId', 'spanId'] as $key) {
                if (is_array($parsed) && isset($parsed[$key]) && is_string($parsed[$key])) {
                    $ids[$key] = $parsed[$key];
                }
            }
        }
        $this->log->add('request', $common['title'], $common + $ids + [
            'status' => $response->getStatusCode(),
            'elapsedMs' => (int) round((hrtime(true) - $started) / 1e6),
            'responseHeaders' => (object) self::headers($response->getHeaders()),
            'responseBody' => DebugLog::pretty($text),
        ]);

        return $response;
    }

    private static function read(\Psr\Http\Message\StreamInterface $stream): string
    {
        $text = (string) $stream;
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $text;
    }

    /**
     * @param array<string, list<string>> $headers
     * @return array<string, string>
     */
    private static function headers(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $values) {
            $value = implode(', ', $values);
            $result[$name] = strtolower($name) === 'authorization' ? self::maskAuthorization($value) : $value;
        }

        return $result;
    }

    /** "Bearer abcdef1234" becomes "Bearer ••••1234": enough to tell which key was used, not enough to reuse it. */
    private static function maskAuthorization(string $value): string
    {
        $parts = explode(' ', $value, 2);
        $token = end($parts);
        $masked = strlen($token) > 8 ? '••••' . substr($token, -4) : '••••';

        return count($parts) === 2 ? $parts[0] . ' ' . $masked : $masked;
    }
}
