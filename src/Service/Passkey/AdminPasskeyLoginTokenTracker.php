<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Passkey;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Tracks which passkey credential authenticated a given admin OAuth access
 * token, keyed by that token's own `jti` claim — Shopware's own bearer-token
 * validator already exposes the *current* request's jti as
 * `PlatformRequest::ATTRIBUTE_OAUTH_ACCESS_TOKEN_ID`
 * (Core/Framework/Api/OAuth/SymfonyBearerTokenValidator), so this only ever
 * needs to remember the *other* half of that pairing: which credential
 * minted a given jti in the first place.
 *
 * Lets the "My passkeys" profile tab force an immediate logout when an admin
 * deletes the exact passkey that authenticated their current live session,
 * instead of leaving that session usable under a since-revoked credential.
 *
 * Deliberate scope limit: this only covers the access token minted directly
 * by the passkey login itself (~10 minutes, see
 * AdminAuthorizationServerFactory::ACCESS_TOKEN_TTL) - once the Administration
 * SPA silently refreshes that token via Shopware's own native refresh_token
 * grant (a completely separate code path this plugin doesn't touch), the
 * resulting new access token gets a new jti this tracker never learns about.
 * The forced-logout protection simply stops applying past that point rather
 * than acting on stale or incorrect state.
 */
class AdminPasskeyLoginTokenTracker
{
    private const TTL_SECONDS = 900;
    private const CACHE_PREFIX = 'sw6oidc_admin_login_jti_';

    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    public function remember(string $tokenId, string $credentialId): void
    {
        $item = $this->cache->getItem($this->hashKey($tokenId));
        $item->set($credentialId);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    public function wasUsedFor(string $tokenId, string $credentialId): bool
    {
        $item = $this->cache->getItem($this->hashKey($tokenId));

        return $item->isHit() && $item->get() === $credentialId;
    }

    /**
     * PSR-6 keys forbid several characters a raw JWT jti could contain, so
     * the identifier is hashed into a safe, fixed-length cache key.
     */
    private function hashKey(string $tokenId): string
    {
        return self::CACHE_PREFIX . hash('sha256', $tokenId);
    }
}
