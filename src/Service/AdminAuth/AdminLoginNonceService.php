<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\AdminAuth;

use MartinKuhl\Sw6Oidc\Service\Cache\AtomicCacheInterface;

/**
 * The one-time nonce hand-off from the OIDC callback (a full browser
 * navigation, server-rendered) into the Administration SPA (which resumes
 * control at `/admin#/login?sw6oidc_nonce=...` and exchanges it via XHR).
 *
 * Carries only references (user, provider, pending registry entry) — the
 * id_token itself is stored once, encrypted, in the session registry.
 */
class AdminLoginNonceService
{
    private const TTL_SECONDS = 120;
    private const CACHE_PREFIX = 'sw6oidc_admin_nonce_';

    public function __construct(private readonly AtomicCacheInterface $cache)
    {
    }

    public function createNonce(string $userId, ?string $providerId = null, ?string $registrySessionId = null): string
    {
        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->cache->save(self::CACHE_PREFIX . $nonce, json_encode([
            'userId' => $userId,
            'providerId' => $providerId,
            'registrySessionId' => $registrySessionId,
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

        try {
            $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data) || !\is_string($data['userId'] ?? null) || $data['userId'] === '') {
            return null;
        }

        $string = static fn (mixed $value): ?string => \is_string($value) && $value !== '' ? $value : null;

        return new AdminLoginNonce(
            $data['userId'],
            $string($data['providerId'] ?? null),
            $string($data['registrySessionId'] ?? null),
        );
    }
}
