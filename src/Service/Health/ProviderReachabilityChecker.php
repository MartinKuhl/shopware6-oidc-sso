<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Health;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Security\SsrfUrlValidator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Is the IdP answering? Fetches the JWKS and requires a non-empty `keys`
 * array (the one endpoint every login depends on); without a JWKS endpoint,
 * falls back to the discovery document and requires its `issuer`.
 *
 * The URL is re-validated against SsrfUrlValidator right before every fetch
 * (DNS may have changed since the provider was saved), on top of the
 * NoPrivateNetworkHttpClient guard of the injected client.
 */
class ProviderReachabilityChecker
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SsrfUrlValidator $urlValidator,
    ) {
    }

    public function check(Sw6OidcProviderEntity $provider): ReachabilityResult
    {
        $jwks = $provider->getJwksEndpoint();

        if ($jwks !== null && $jwks !== '') {
            return $this->probe('jwks', $jwks, $provider->getHttpTimeout(), $this->jwksError(...));
        }

        $discovery = $provider->getWellKnownConfigUrl();

        if ($discovery !== null && $discovery !== '') {
            return $this->probe('discovery', $discovery, $provider->getHttpTimeout(), $this->discoveryError(...));
        }

        return new ReachabilityResult(false, null, 'Neither a JWKS endpoint nor a discovery URL is configured.', 0);
    }

    /**
     * @param \Closure(array<mixed>): ?string $validateBody returns an error, or null when the body is fine
     */
    private function probe(string $kind, string $url, int $timeoutSeconds, \Closure $validateBody): ReachabilityResult
    {
        $ssrf = $this->urlValidator->validate($url);

        if ($ssrf['blocked']) {
            return new ReachabilityResult(false, $kind, 'URL blocked: ' . implode(' ', $ssrf['warnings']), 0);
        }

        $start = microtime(true);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => max(1, min($timeoutSeconds, 30)),
                'headers' => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (\Throwable $exception) {
            return new ReachabilityResult(false, $kind, 'Request failed: ' . $exception->getMessage(), $this->elapsed($start));
        }

        if ($status >= 400) {
            return new ReachabilityResult(false, $kind, sprintf('HTTP %d.', $status), $this->elapsed($start));
        }

        $body = json_decode($content, true);

        if (!\is_array($body)) {
            return new ReachabilityResult(false, $kind, 'The response is not a JSON object.', $this->elapsed($start));
        }

        $error = $validateBody($body);

        $detail = $error ?? sprintf('HTTP %d, valid %s.', $status, $kind === 'jwks' ? 'key set' : 'discovery document');

        return new ReachabilityResult($error === null, $kind, $detail, $this->elapsed($start));
    }

    /**
     * @param array<mixed> $body
     */
    private function jwksError(array $body): ?string
    {
        return \is_array($body['keys'] ?? null) && $body['keys'] !== [] ? null : 'The JWKS contains no keys.';
    }

    /**
     * @param array<mixed> $body
     */
    private function discoveryError(array $body): ?string
    {
        return \is_string($body['issuer'] ?? null) && $body['issuer'] !== '' ? null : 'The discovery document has no issuer.';
    }

    private function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000.0);
    }
}
