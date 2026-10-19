<?php

declare(strict_types=1);

namespace Maxx\Ai\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Maxx\Ai\Auth\TokenProvider;
use Maxx\Ai\Data\ApiResponse;
use Maxx\Ai\Exceptions\ApiException;
use Maxx\Ai\Exceptions\ProtocolException;
use Maxx\Ai\Exceptions\TransportException;

final class GuzzleTransport implements Transport
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly Settings $settings,
        private readonly TokenProvider $tokens,
    ) {
    }

    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        #[\SensitiveParameter] ?string $bearer = null,
    ): ApiResponse {
        if (!preg_match('~\A/(?:v1|internal/m2)/[A-Za-z0-9_./%:-]+\z~', $path)
            || str_contains($path, '..') || !in_array($method, ['GET', 'POST'], true)) {
            throw new \InvalidArgumentException('Unsupported SDK request target.');
        }
        $correlation = bin2hex(random_bytes(16));
        $token = $bearer ?? $this->tokens->token();
        if ($token === '' || preg_match('/[\r\n]/', $token)) {
            throw new \InvalidArgumentException('Invalid bearer token.');
        }
        $options = [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
                'X-Correlation-ID' => $correlation,
                'User-Agent' => 'maxx-laravel-ai-sdk/0.1',
            ],
            'query' => $query,
            'timeout' => $this->settings->timeout,
            'connect_timeout' => $this->settings->connectTimeout,
            'http_errors' => false,
            'allow_redirects' => false,
            'verify' => true,
            'cookies' => false,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }
        try {
            $response = $this->http->request($method, rtrim($this->settings->baseUrl, '/').$path, $options);
        } catch (GuzzleException) {
            // Guzzle exceptions retain request bodies and credentials; do not chain them.
            throw new TransportException($method !== 'GET', $correlation);
        }
        $status = $response->getStatusCode();
        $stream = $response->getBody();
        try {
            $raw = $stream->read(2_000_001);
        } catch (\RuntimeException) {
            throw new TransportException($method !== 'GET', $correlation);
        }
        if (strlen($raw) > 2_000_000) {
            throw new ProtocolException($method !== 'GET', $correlation);
        }
        try {
            $object = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            if ($status < 200 || $status >= 300) {
                throw new ApiException($status, 'REQUEST_FAILED', $correlation, $method !== 'GET' && $status >= 500);
            }
            throw new ProtocolException($method !== 'GET', $correlation);
        }
        if ($status < 200 || $status >= 300) {
            $code = is_array($data) && isset($data['code']) && is_string($data['code'])
                && preg_match('/\A[A-Z][A-Z0-9_]{0,99}\z/', $data['code'])
                ? $data['code'] : 'REQUEST_FAILED';
            throw new ApiException($status, $code, $correlation, $method !== 'GET' && $status >= 500);
        }
        if (!$object instanceof \stdClass || !is_array($data)) {
            throw new ProtocolException($method !== 'GET', $correlation);
        }

        return new ApiResponse($data, $correlation);
    }
}
