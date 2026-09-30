<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

use MartinKuhl\Sw6Oidc\Service\Cache\AtomicCacheInterface;

/**
 * Remembers which provider (and id_token, for RFC 7009 revocation) logged a
 * given Storefront/Administration session in, purely so the logout path can
 * find the right `end_session_endpoint` to redirect to. Shopware's Storefront
 * customer sessions are stateless context tokens with no room for custom
 * session data the way Magento's PHP session carries oidc_id_token/provider_id
 * directly — this is the smallest equivalent: one short-lived cache entry per
 * login, keyed by the session/context token, read once at logout.
 */
class LogoutContextStore
{
    private const TTL_SECONDS = 86400; // matches typical Shopware storefront session lifetime
    private const CACHE_PREFIX = 'sw6oidc_logout_ctx_';
    private const ADMIN_CACHE_PREFIX = 'sw6oidc_admin_logout_ctx_';

    public function __construct(private readonly AtomicCacheInterface $cache)
    {
    }

    public function remember(string $sessionToken, string $providerId, ?string $idToken): void
    {
        $this->save(self::CACHE_PREFIX . $sessionToken, $providerId, $idToken);
    }

    public function consume(string $sessionToken): ?LogoutContext
    {
        return $this->load(self::CACHE_PREFIX . $sessionToken);
    }

    /**
     * Administration sessions are keyed by admin user id rather than by
     * access token: the SPA's silent refresh mints a new token (and jti)
     * every 10 minutes, so no token-derived key would still match at logout.
     * Concurrent sessions of the same admin share one entry (last login wins).
     */
    public function rememberForAdmin(string $userId, string $providerId, ?string $idToken): void
    {
        $this->save(self::ADMIN_CACHE_PREFIX . $userId, $providerId, $idToken);
    }

    public function consumeForAdmin(string $userId): ?LogoutContext
    {
        return $this->load(self::ADMIN_CACHE_PREFIX . $userId);
    }

    private function save(string $key, string $providerId, ?string $idToken): void
    {
        $context = new LogoutContext($providerId, $idToken);

        $this->cache->save($key, json_encode($context->toArray(), JSON_THROW_ON_ERROR), self::TTL_SECONDS);
    }

    private function load(string $key): ?LogoutContext
    {
        $raw = $this->cache->getAndDelete($key);

        if ($raw === null) {
            return null;
        }

        try {
            return LogoutContext::fromArray(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return null;
        }
    }
}
