<?php

namespace Omnibus\Ups;

use Omnibus\Exception\CarrierException;
use Omnibus\Http\Redaction;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** UPS's REST APIs on an OAuth2 client-credentials token, kept until it expires. */
final class Api
{
    public const LIVE = 'https://onlinetools.ups.com';
    public const TEST = 'https://wwwcie.ups.com';
    public const VERSION = 'v2409';

    private ?string $token = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        public readonly string $accountNumber,
        public readonly bool $sandbox = false,
        private readonly int $timeout = 20,
    ) {
    }

    public function base(): string
    {
        return $this->sandbox ? self::TEST : self::LIVE;
    }

    /** @return array<string, mixed> */
    public function call(string $method, string $path, ?array $body = null, array $query = []): array
    {
        try {
            $response = $this->http->request($method, $this->base().$path, [
                'headers' => ['Authorization' => 'Bearer '.$this->token(), 'Content-Type' => 'application/json', 'transId' => bin2hex(random_bytes(8)), 'transactionSrc' => 'omnibus'],
                'query' => $query,
                'body' => null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface|\JsonException $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new CarrierException('ups', 'UPS request failed: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        if (!\is_array($data)) {
            throw Redaction::withoutArguments(new CarrierException('ups', sprintf('UPS answered HTTP %d with a body that is not JSON.', $status)));
        }
        if ($status >= 400) {
            $error = $data['response']['errors'][0] ?? [];
            throw Redaction::withoutArguments(new CarrierException('ups', (string) ($error['message'] ?? sprintf('HTTP %d', $status)), isset($error['code']) ? (string) $error['code'] : null));
        }

        return $data;
    }

    private function token(): string
    {
        if (null !== $this->token && time() < $this->expiresAt - 60) {
            return $this->token;
        }
        try {
            $data = $this->http->request('POST', $this->base().'/security/v1/oauth/token', [
                'auth_basic' => [$this->clientId, $this->clientSecret],
                'headers' => ['x-merchant-id' => $this->accountNumber],
                'body' => ['grant_type' => 'client_credentials'],
                'timeout' => $this->timeout,
            ])->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new CarrierException('ups', 'UPS gave no token: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        if (empty($data['access_token'])) {
            throw Redaction::withoutArguments(new CarrierException('ups', (string) ($data['response']['errors'][0]['message'] ?? 'UPS gave no token: check the client id and secret.')));
        }
        $this->token = (string) $data['access_token'];
        $this->expiresAt = time() + (int) ($data['expires_in'] ?? 14400);

        return $this->token;
    }

    /**
     * What a failure must not carry: the credentials and the token, and what a request made of them.
     *
     * @return list<string>
     */
    private function secrets(string ...$more): array
    {
        return [$this->clientSecret, (string) $this->token, ...$more];
    }
}
