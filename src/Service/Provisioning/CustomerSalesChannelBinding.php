<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning;

use Shopware\Core\Checkout\Customer\CustomerEntity;

/**
 * Shopware's "bind customers to sales channel" setting: a customer with a
 * boundSalesChannelId may only log in to that sales channel. One place for
 * the rule, used by provisioning (email lookup) and the passwordless login.
 */
final class CustomerSalesChannelBinding
{
    public static function allows(CustomerEntity $customer, string $salesChannelId): bool
    {
        $boundSalesChannelId = $customer->getBoundSalesChannelId();

        return $boundSalesChannelId === null || $boundSalesChannelId === $salesChannelId;
    }
}
