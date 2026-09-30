<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Storefront\Service;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped hand-off between OidcLogoutRoute (which resolves the IdP
 * logout URL while the logout itself runs) and CustomerLogoutSubscriber
 * (which swaps it into the HTTP response afterwards).
 */
class PendingLogoutRedirect implements ResetInterface
{
    private ?string $url = null;

    public function set(?string $url): void
    {
        $this->url = $url;
    }

    public function pull(): ?string
    {
        $url = $this->url;
        $this->url = null;

        return $url;
    }

    public function reset(): void
    {
        $this->url = null;
    }
}
