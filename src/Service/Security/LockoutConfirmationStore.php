<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Security;

use Psr\Cache\CacheItemPoolInterface;

/**
 * The explicit "yes, lock out the admins without SSO binding" confirmation
 * for switching Administration password login off. The Administration sets
 * it through an API action right before re-saving the provider (a DAL save
 * can't carry extra request data); it is valid for five minutes and consumed
 * by the first write guard check.
 */
class LockoutConfirmationStore
{
    private const TTL_SECONDS = 300;
    private const PREFIX = 'sw6oidc_lockout_confirm_';

    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    public function confirm(string $providerId): void
    {
        $item = $this->cache->getItem(self::PREFIX . $providerId);
        $item->set(true);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    public function consume(string $providerId): bool
    {
        $key = self::PREFIX . $providerId;

        if (!$this->cache->getItem($key)->isHit()) {
            return false;
        }

        $this->cache->deleteItem($key);

        return true;
    }
}
