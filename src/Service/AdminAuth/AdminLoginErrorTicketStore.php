<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\AdminAuth;

use MartinKuhl\Sw6Oidc\Service\Cache\AtomicCacheInterface;

/**
 * Hands an admin-configured error message (access-control denial) from the
 * full-page admin OIDC callback to the pre-auth `sw-login` screen without
 * putting free text into the redirect URL (browser history, proxy logs): the
 * callback stores it under a random ticket, the SPA redeems the ticket once.
 */
class AdminLoginErrorTicketStore
{
    private const TTL_SECONDS = 60;
    private const CACHE_PREFIX = 'sw6oidc_admin_login_error_';

    public function __construct(private readonly AtomicCacheInterface $cache)
    {
    }

    public function create(string $message): string
    {
        $ticket = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

        $this->cache->save(self::CACHE_PREFIX . $ticket, $message, self::TTL_SECONDS);

        return $ticket;
    }

    public function redeem(?string $ticket): ?string
    {
        if ($ticket === null || preg_match('/^[A-Za-z0-9_-]{16,64}$/', $ticket) !== 1) {
            return null;
        }

        return $this->cache->getAndDelete(self::CACHE_PREFIX . $ticket);
    }
}
