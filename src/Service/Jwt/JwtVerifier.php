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
    private const JWKS_FAILURE_TTL_SECONDS = 60;

    /** OIDC Back-Channel Logout 1.0 §2.4: the `events` member identifying a logout token. */
    public const BACKCHANNEL_LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

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
        $payload = $this->verifySignedPayload($jwt, $jwksEndpoint, $jwksCacheTtlSeconds, $httpTimeoutSeconds);

        $this->assertClaims($payload, $issuer, $audience, $expectedNonce);

        return $payload;
    }

    /**
     * Verifies an OIDC Back-Channel Logout token (§2.6): same signature and
     * exp/nbf/iss/aud checks as an id_token, plus: `iat` present, an
     * `events` object containing the back-channel-logout member, `sub`
     * and/or `sid` present, and **no** `nonce` (which is what keeps an
     * id_token from being replayed as a logout token).
     *
     * @return array<string, mixed>
     *
     * @throws InvalidJwtException
     */
    public function verifyLogoutToken(
        string $jwt,
        string $jwksEndpoint,
        string $issuer,
        string $audience,
        int $jwksCacheTtlSeconds,
        int $httpTimeoutSeconds,
    ): array {
        $payload = $this->verifySignedPayload($jwt, $jwksEndpoint, $jwksCacheTtlSeconds, $httpTimeoutSeconds);

        $this->assertStandardClaims($payload, $issuer, $audience);

        if (!isset($payload['iat']) || !is_numeric($payload['iat'])) {
            throw new InvalidJwtException('Logout token has no "iat" claim.');
        }

        $events = $payload['events'] ?? null;

        if (!\is_array($events) || !\array_key_exists(self::BACKCHANNEL_LOGOUT_EVENT, $events) || !\is_array($events[self::BACKCHANNEL_LOGOUT_EVENT])) {
            throw new InvalidJwtException('Logout token has no back-channel logout "events" member.');
        }

        if (\array_key_exists('nonce', $payload)) {
            throw new InvalidJwtException('Logout token must not contain a "nonce" claim.');
        }

        $hasSub = \is_string($payload['sub'] ?? null) && $payload['sub'] !== '';
        $hasSid = \is_string($payload['sid'] ?? null) && $payload['sid'] !== '';

        if (!$hasSub && !$hasSid) {
            throw new InvalidJwtException('Logout token contains neither "sub" nor "sid".');
        }

        return $payload;
    }

    /**
     * Reads a JWT payload **without** verifying it — only to learn which
     * provider (`iss`) must verify it. Never trust the result on its own.
     *
     * @return array<string, mixed>|null
     */
    public function decodeUnverified(string $jwt): ?array
    {
        return JwtPayloadReader::decode($jwt);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidJwtException
     */
    private function verifySignedPayload(string $jwt, string $jwksEndpoint, int $jwksCacheTtlSeconds, int $httpTimeoutSeconds): array
    {
        $jws = $this->deserialize($jwt);
        $this->assertSupportedAlgorithm($jws);

        $jwsVerifier = new JWSVerifier(new AlgorithmManager([new RS256(), new RS384(), new RS512()]));

        $jwkSet = $this->getJwks($jwksEndpoint, $jwksCacheTtlSeconds, $httpTimeoutSeconds, false);

        if (!$jwsVerifier->verifyWithKeySet($jws, $jwkSet, 0)) {
            // The IdP may have rotated its signing key since the JWKS was
            // cached: refetch once (still circuit-breaker protected) instead
            // of rejecting every login until the cache TTL runs out.
            $this->logger->info('sw6oidc: JWT signature did not verify against the cached JWKS; refetching once.');
            $jwkSet = $this->getJwks($jwksEndpoint, $jwksCacheTtlSeconds, $httpTimeoutSeconds, true);

            if (!$jwsVerifier->verifyWithKeySet($jws, $jwkSet, 0)) {
                throw new InvalidJwtException('JWT signature verification failed against the provider JWKS.');
            }
        }

        $payload = json_decode($jws->getPayload() ?? '', true);

        if (!\is_array($payload)) {
            throw new InvalidJwtException('JWT payload is not a valid JSON object.');
        }

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
        $this->assertStandardClaims($payload, $issuer, $audience);

        if ($expectedNonce === null) {
            $this->logger->warning('sw6oidc: JWT nonce validation skipped (no expected nonce supplied).');

            return;
        }

        if (($payload['nonce'] ?? null) !== $expectedNonce) {
            throw new InvalidJwtException('JWT nonce does not match the nonce sent in the authorization request.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertStandardClaims(array $payload, string $issuer, string $audience): void
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
    }

    /**
     * JWKS from cache, else fetched. A failed fetch trips a 60s circuit
     * breaker (flag in the same cache pool) so an unreachable IdP doesn't
     * cost a blocking HTTP timeout on every single login attempt.
     *
     * @throws InvalidJwtException
     */
    private function getJwks(string $jwksEndpoint, int $ttlSeconds, int $httpTimeoutSeconds, bool $forceRefresh): JWKSet
    {
        $endpointHash = hash('sha256', $jwksEndpoint);
        $item = $this->cache->getItem('sw6oidc_jwks_' . $endpointHash);

        if ($forceRefresh) {
            // At most one forced (key-rotation) refetch per endpoint per
            // cooldown window, so a stream of badly signed tokens can't turn
            // into a stream of requests against the IdP.
            $refreshedItem = $this->cache->getItem('sw6oidc_jwks_refreshed_' . $endpointHash);

            if ($refreshedItem->isHit()) {
                throw new InvalidJwtException('JWT signature verification failed against the provider JWKS.');
            }

            $refreshedItem->set(true);
            $refreshedItem->expiresAfter(self::JWKS_FAILURE_TTL_SECONDS);
            $this->cache->save($refreshedItem);
        } elseif ($item->isHit() && \is_string($item->get())) {
            try {
                return JWKSet::createFromJson($item->get());
            } catch (\Throwable) {
                // Corrupted cache entry: fall through and re-fetch.
            }
        }

        $failKey = 'sw6oidc_jwks_fail_' . $endpointHash;

        if ($this->cache->getItem($failKey)->isHit()) {
            throw new InvalidJwtException('The provider JWKS endpoint is temporarily unavailable (recent fetch failed); try again shortly.');
        }

        try {
            $json = $this->httpClient->request('GET', $jwksEndpoint, ['timeout' => $httpTimeoutSeconds])->getContent();
            $jwkSet = JWKSet::createFromJson($json);

            if ($jwkSet->count() === 0) {
                throw new \RuntimeException('JWKS contains no keys.');
            }
        } catch (\Throwable $exception) {
            $failItem = $this->cache->getItem($failKey);
            $failItem->set(true);
            $failItem->expiresAfter(self::JWKS_FAILURE_TTL_SECONDS);
            $this->cache->save($failItem);

            $this->logger->warning('sw6oidc: JWKS fetch failed; pausing further fetches for this endpoint.', [
                'jwksEndpoint' => $jwksEndpoint,
                'pauseSeconds' => self::JWKS_FAILURE_TTL_SECONDS,
                'exception' => $exception->getMessage(),
            ]);

            throw new InvalidJwtException('Could not fetch the provider JWKS: ' . $exception->getMessage(), 0, $exception);
        }

        $item->set($json);
        $item->expiresAfter($ttlSeconds);
        $this->cache->save($item);

        return $jwkSet;
    }
}
