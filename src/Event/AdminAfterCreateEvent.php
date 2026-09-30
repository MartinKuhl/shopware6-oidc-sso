<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Event;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\MappedProfile;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareEvent;
use Shopware\Core\System\User\UserEntity;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after an Administration user was JIT-created from an OIDC login
 * and bound to the provider. Read-only notification.
 */
class AdminAfterCreateEvent extends Event implements ShopwareEvent
{
    public function __construct(
        private readonly Sw6OidcProviderEntity $provider,
        private readonly MappedProfile $profile,
        private readonly UserEntity $user,
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

    public function getUser(): UserEntity
    {
        return $this->user;
    }

    public function getContext(): Context
    {
        return $this->context;
    }
}
