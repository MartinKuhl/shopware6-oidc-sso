<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Jwt;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use MartinKuhl\Sw6Oidc\Service\Jwt\Exception\InvalidJwtException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Verifies OIDC id_tokens / JWTs: RS256/384/512 signature against the provider's
 * JWKS (cached, per-provider TTL), plus exp/nbf/iss/aud/nonce claim checks.
 * Replaces the Magento module's Helper/JwtVerifier.php.
 */
class JwtVerifier
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed> the decoded JWT payload (claims)
     *
     * @throws InvalidJwtException
     */
    public function verify(
        string $jwt,
        string $jwksEndpoint,
        string $issuer,
        string $audience,
        ?string $expectedNonce,
        int $jwksCacheTtlSeconds,
        int $httpTimeoutSeconds,
    ): array {
        $jws = $this->deserialize($jwt);
        $this->assertSupportedAlgorithm($jws);

        $jwkSet = $this->getJwks($jwksEndpoint, $jwksCacheTtlSeconds, $httpTimeoutSeconds);

        $algorithmManager = new AlgorithmManager([new RS256(), new RS384(), new RS512()]);
        $jwsVerifier = new JWSVerifier($algorithmManager);

        if (!$jwsVerifier->verifyWithKeySet($jws, $jwkSet, 0)) {
            throw new InvalidJwtException('JWT signature verification failed against the provider JWKS.');
        }

        $payload = json_decode($jws->getPayload() ?? '', true);

        if (!\is_array($payload)) {
            throw new InvalidJwtException('JWT payload is not a valid JSON object.');
        }

        $this->assertClaims($payload, $issuer, $audience, $expectedNonce);

        return $payload;
    }

    private function deserialize(string $jwt): \Jose\Component\Signature\JWS
    {
        try {
            return (new CompactSerializer())->unserialize($jwt);
        } catch (\Throwable $exception) {
            throw new InvalidJwtException('Malformed JWT: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function assertSupportedAlgorithm(\Jose\Component\Signature\JWS $jws): void
    {
        $alg = $jws->getSignature(0)->getProtectedHeaderParameter('alg');

        if (!\in_array($alg, ['RS256', 'RS384', 'RS512'], true)) {
            throw new InvalidJwtException(sprintf('Unsupported JWT signature algorithm "%s".', (string) $alg));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertClaims(array $payload, string $issuer, string $audience, ?string $expectedNonce): void
    {
        $now = time();

        if (!isset($payload['exp']) || $now >= (int) $payload['exp']) {
            throw new InvalidJwtException('JWT has expired.');
        }

        if (isset($payload['nbf']) && $now < (int) $payload['nbf']) {
            throw new InvalidJwtException('JWT is not yet valid.');
        }

        if (($payload['iss'] ?? null) !== $issuer) {
            throw new InvalidJwtException('JWT issuer does not match the configured provider issuer.');
        }

        $aud = $payload['aud'] ?? null;
        $audienceMatches = \is_array($aud) ? \in_array($audience, $aud, true) : $aud === $audience;

        if (!$audienceMatches) {
            throw new InvalidJwtException("JWT audience does not match this provider's client id.");
        }

        if ($expectedNonce === null) {
            $this->logger->warning('sw6oidc: JWT nonce validation skipped (no expected nonce supplied).');

            return;
        }

        if (($payload['nonce'] ?? null) !== $expectedNonce) {
            throw new InvalidJwtException('JWT nonce does not match the nonce sent in the authorization request.');
        }
    }

    private function getJwks(string $jwksEndpoint, int $ttlSeconds, int $httpTimeoutSeconds): JWKSet
    {
        $cacheKey = 'sw6oidc_jwks_' . hash('sha256', $jwksEndpoint);
        $item = $this->cache->getItem($cacheKey);

        if ($item->isHit() && \is_string($item->get())) {
            try {
                return JWKSet::createFromJson($item->get());
            } catch (\Throwable) {
                // Corrupted cache entry: fall through and re-fetch.
            }
        }

        $response = $this->httpClient->request('GET', $jwksEndpoint, ['timeout' => $httpTimeoutSeconds]);
        $json = $response->getContent();

        $item->set($json);
        $item->expiresAfter($ttlSeconds);
        $this->cache->save($item);

        return JWKSet::createFromJson($json);
    }
}
