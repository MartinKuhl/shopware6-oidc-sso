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
 *
 * Besides the user id, the nonce carries the login's provider/sub/sid/id_token
 * forward to the token exchange, where the session registry entry is written.
 */
class AdminLoginNonceService
{
    private const TTL_SECONDS = 120;
    private const CACHE_PREFIX = 'sw6oidc_admin_nonce_';

    public function __construct(private readonly AtomicCacheInterface $cache)
    {
    }

    public function createNonce(string $userId, ?string $providerId = null, ?string $sub = null, ?string $sid = null, ?string $idToken = null): string
    {
        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->cache->save(self::CACHE_PREFIX . $nonce, json_encode([
            'userId' => $userId,
            'providerId' => $providerId,
            'sub' => $sub,
            'sid' => $sid,
            'idToken' => $idToken,
        ], JSON_THROW_ON_ERROR), self::TTL_SECONDS);

        return $nonce;
    }

    public function redeemNonce(?string $nonce): ?AdminLoginNonce
    {
        if ($nonce === null || $nonce === '') {
            return null;
        }

        $raw = $this->cache->getAndDelete(self::CACHE_PREFIX . $nonce);

        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);

        // Nonces minted before this format change stored the bare user id.
        if (!\is_array($data)) {
            return new AdminLoginNonce($raw);
        }

        if (!\is_string($data['userId'] ?? null) || $data['userId'] === '') {
            return null;
        }

        $string = static fn (mixed $value): ?string => \is_string($value) && $value !== '' ? $value : null;

        return new AdminLoginNonce(
            $data['userId'],
            $string($data['providerId'] ?? null),
            $string($data['sub'] ?? null),
            $string($data['sid'] ?? null),
            $string($data['idToken'] ?? null),
        );
    }
}
