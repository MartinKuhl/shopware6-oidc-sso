<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Session;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Maps IdP subjects / IdP sessions (`sub`, `sid`, both scoped to the
 * provider — they are only unique per issuer) and local accounts to the
 * local sessions an OIDC login created, so Back-/Front-Channel Logout and
 * forced logouts can find what to destroy.
 *
 * Backed by plain `cache.app` (not AtomicCacheInterface: nothing here is a
 * one-time token). Each session is stored once under its id; three index
 * entries (by sid, by sub, by local account) list session ids. Index updates
 * are read-modify-write without locking: a concurrent login for the same
 * subject can drop an index entry, which only means that one session is not
 * found by a later logout — acceptable for a best-effort logout fan-out,
 * never a way to gain access.
 */
class Sw6OidcSessionRegistry
{
    public const DEFAULT_TTL_SECONDS = 86400;

    private const PREFIX = 'sw6oidc_session_';
    /** Bounds each index list; the oldest entries fall out first. */
    private const MAX_INDEX_ENTRIES = 50;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(
        string $providerId,
        string $sub,
        ?string $sid,
        string $userType,
        string $userId,
        string $sessionKey,
        ?string $salesChannelId = null,
        ?string $idToken = null,
        int $ttl = self::DEFAULT_TTL_SECONDS,
    ): Sw6OidcSession {
        $session = new Sw6OidcSession(
            bin2hex(random_bytes(16)),
            $providerId,
            $sub,
            $sid !== '' ? $sid : null,
            $userType,
            $userId,
            $sessionKey,
            $salesChannelId,
            $idToken,
            time(),
        );

        $item = $this->cache->getItem($this->sessionKey($session->id));
        $item->set(json_encode($session->toArray(), JSON_THROW_ON_ERROR));
        $item->expiresAfter($ttl);
        $this->cache->save($item);

        foreach ($this->indexKeysFor($session) as $indexKey) {
            $this->addToIndex($indexKey, $session->id, $ttl);
        }

        $this->logger->debug('sw6oidc: session registered.', [
            'providerId' => $providerId,
            'userType' => $userType,
            'userId' => $userId,
            'hasSid' => $session->sid !== null,
        ]);

        return $session;
    }

    /**
     * All live sessions of an IdP subject at this provider.
     *
     * @return list<Sw6OidcSession>
     */
    public function resolve(string $providerId, string $sub): array
    {
        return $this->resolveIndex($this->subIndexKey($providerId, $sub));
    }

    /**
     * @return list<Sw6OidcSession>
     */
    public function resolveBySid(string $providerId, string $sid): array
    {
        return $this->resolveIndex($this->sidIndexKey($providerId, $sid));
    }

    /**
     * All live OIDC sessions of a local account, newest last.
     *
     * @return list<Sw6OidcSession>
     */
    public function resolveByUser(string $userType, string $userId): array
    {
        return $this->resolveIndex($this->userIndexKey($userType, $userId));
    }

    /**
     * Removes the subject's sessions (all, or only the one IdP session `sid`
     * when given) and returns what was removed.
     *
     * @return list<Sw6OidcSession>
     */
    public function revoke(string $providerId, string $sub, ?string $sid = null): array
    {
        $sessions = $this->resolve($providerId, $sub);

        if ($sid !== null && $sid !== '') {
            $sessions = array_values(array_filter($sessions, static fn (Sw6OidcSession $session): bool => $session->sid === $sid));
        }

        array_walk($sessions, $this->remove(...));

        return $sessions;
    }

    /**
     * @return list<Sw6OidcSession>
     */
    public function revokeBySid(string $providerId, string $sid): array
    {
        $sessions = $this->resolveBySid($providerId, $sid);
        array_walk($sessions, $this->remove(...));

        return $sessions;
    }

    public function remove(Sw6OidcSession $session): void
    {
        $this->cache->deleteItem($this->sessionKey($session->id));

        foreach ($this->indexKeysFor($session) as $indexKey) {
            $this->removeFromIndex($indexKey, $session->id);
        }
    }

    public function get(string $id): ?Sw6OidcSession
    {
        $item = $this->cache->getItem($this->sessionKey($id));

        if (!$item->isHit() || !\is_string($item->get())) {
            return null;
        }

        try {
            $data = json_decode($item->get(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? Sw6OidcSession::fromArray($data) : null;
    }

    /**
     * @return list<Sw6OidcSession>
     */
    private function resolveIndex(string $indexKey): array
    {
        $sessions = [];

        foreach ($this->readIndex($indexKey) as $id) {
            $session = $this->get($id);

            if ($session instanceof Sw6OidcSession) {
                $sessions[] = $session;
            }
        }

        return $sessions;
    }

    /**
     * @return list<string>
     */
    private function indexKeysFor(Sw6OidcSession $session): array
    {
        $keys = [
            $this->subIndexKey($session->providerId, $session->sub),
            $this->userIndexKey($session->userType, $session->userId),
        ];

        if ($session->sid !== null) {
            $keys[] = $this->sidIndexKey($session->providerId, $session->sid);
        }

        return $keys;
    }

    private function addToIndex(string $indexKey, string $id, int $ttl): void
    {
        $ids = $this->readIndex($indexKey);
        $ids[] = $id;

        $this->writeIndex($indexKey, \array_slice(array_values(array_unique($ids)), -self::MAX_INDEX_ENTRIES), $ttl);
    }

    private function removeFromIndex(string $indexKey, string $id): void
    {
        $ids = array_values(array_filter($this->readIndex($indexKey), static fn (string $existing): bool => $existing !== $id));

        if ($ids === []) {
            $this->cache->deleteItem($indexKey);

            return;
        }

        $this->writeIndex($indexKey, $ids, self::DEFAULT_TTL_SECONDS);
    }

    /**
     * @return list<string>
     */
    private function readIndex(string $indexKey): array
    {
        $item = $this->cache->getItem($indexKey);
        $value = $item->isHit() ? $item->get() : null;

        return \is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * @param list<string> $ids
     */
    private function writeIndex(string $indexKey, array $ids, int $ttl): void
    {
        $item = $this->cache->getItem($indexKey);
        $item->set($ids);
        $item->expiresAfter($ttl);
        $this->cache->save($item);
    }

    private function sessionKey(string $id): string
    {
        return self::PREFIX . 'entry_' . $id;
    }

    // PSR-6 keys forbid characters sub/sid values may contain, so they are hashed.
    private function subIndexKey(string $providerId, string $sub): string
    {
        return self::PREFIX . 'sub_' . hash('sha256', $providerId . "\0" . $sub);
    }

    private function sidIndexKey(string $providerId, string $sid): string
    {
        return self::PREFIX . 'sid_' . hash('sha256', $providerId . "\0" . $sid);
    }

    private function userIndexKey(string $userType, string $userId): string
    {
        return self::PREFIX . 'user_' . hash('sha256', $userType . "\0" . $userId);
    }
}
