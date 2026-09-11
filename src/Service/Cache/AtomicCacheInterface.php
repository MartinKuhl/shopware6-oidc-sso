<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Cache;

/**
 * Atomic one-time-token storage: PKCE verifiers, OAuth state tokens, OIDC nonces,
 * admin-login hand-off nonces, WebAuthn challenge nonces.
 *
 * getAndDelete() must be a single atomic read-and-remove so two concurrent requests
 * can never both successfully consume the same one-time token (the TOCTOU race the
 * Magento module's AtomicCacheInterface/RedisAtomicCache close via Redis GETDEL).
 */
interface AtomicCacheInterface
{
    public function save(string $key, string $value, int $ttlSeconds): void;

    /**
     * Reads and immediately removes the value. Returns null if the key does not
     * exist (already consumed, expired, or never set).
     */
    public function getAndDelete(string $key): ?string;
}
