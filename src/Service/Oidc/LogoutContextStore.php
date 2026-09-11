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

    public function __construct(private readonly AtomicCacheInterface $cache)
    {
    }

    public function remember(string $sessionToken, string $providerId, ?string $idToken): void
    {
        $context = new LogoutContext($providerId, $idToken);

        $this->cache->save(
            self::CACHE_PREFIX . $sessionToken,
            json_encode($context->toArray(), JSON_THROW_ON_ERROR),
            self::TTL_SECONDS,
        );
    }

    public function consume(string $sessionToken): ?LogoutContext
    {
        $raw = $this->cache->getAndDelete(self::CACHE_PREFIX . $sessionToken);

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
