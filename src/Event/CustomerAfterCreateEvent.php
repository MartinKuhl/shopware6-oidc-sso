<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Event;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\MappedProfile;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a customer was JIT-created from an OIDC login and bound
 * to the provider. Read-only notification.
 */
class CustomerAfterCreateEvent extends Event implements ShopwareEvent
{
    public function __construct(
        private readonly Sw6OidcProviderEntity $provider,
        private readonly MappedProfile $profile,
        private readonly CustomerEntity $customer,
        private readonly SalesChannelContext $salesChannelContext,
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

    public function getCustomer(): CustomerEntity
    {
        return $this->customer;
    }

    public function getSalesChannelContext(): SalesChannelContext
    {
        return $this->salesChannelContext;
    }

    public function getContext(): Context
    {
        return $this->salesChannelContext->getContext();
    }
}
