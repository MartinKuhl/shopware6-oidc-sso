<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\AdminAuth;

use MartinKuhl\Sw6Oidc\Service\Cache\AtomicCacheInterface;

/**
 * The one-time nonce hand-off from the OIDC callback (a full browser
 * navigation, server-rendered) into the Administration SPA (which resumes
 * control at `/admin#/login?sw6oidc_nonce=...` and exchanges it via XHR) — the
 * direct analogue of the Magento module's oidc_admin_nonce cookie ->
 * Oidccallback hand-off, adapted to a query param since the SPA reads the URL
 * itself rather than a controller reading a cookie.
 */
class AdminLoginNonceService
{
    private const TTL_SECONDS = 120;
    private const CACHE_PREFIX = 'sw6oidc_admin_nonce_';

    public function __construct(private readonly AtomicCacheInterface $cache)
    {
    }

    public function createNonce(string $userId): string
    {
        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->cache->save(self::CACHE_PREFIX . $nonce, $userId, self::TTL_SECONDS);

        return $nonce;
    }

    public function redeemNonce(?string $nonce): ?string
    {
        if ($nonce === null || $nonce === '') {
            return null;
        }

        return $this->cache->getAndDelete(self::CACHE_PREFIX . $nonce);
    }
}
