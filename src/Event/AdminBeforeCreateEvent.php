<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Event;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\MappedProfile;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareEvent;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched right before an Administration user is JIT-created from an OIDC
 * login. Listeners may change the user.repository create payload; `id` is
 * fixed and any change to it is ignored.
 *
 * Security note: the payload carries `admin` (superadmin) and `aclRoles` —
 * a listener changing those bypasses the plugin's own two-gate superadmin
 * rule, so only do that deliberately.
 */
class AdminBeforeCreateEvent extends Event implements ShopwareEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly Sw6OidcProviderEntity $provider,
        private readonly MappedProfile $profile,
        private array $payload,
        private readonly Context $context,
    ) {
    }

    public function getProvider(): Sw6OidcProviderEntity
    {
        return $this->provider;
    }

    public function getProfile(): MappedProfile
    {
        return $this->profile;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function getContext(): Context
    {
        return $this->context;
    }
}
