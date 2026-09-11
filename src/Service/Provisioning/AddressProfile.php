<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning;

/**
 * Billing/shipping address claims, mapped separately from MappedProfile because
 * Shopware stores addresses as their own `customer_address` entities rather than
 * inline customer fields (see plan: "Address mapping nuance vs. Magento").
 */
final class AddressProfile
{
    public function __construct(
        public readonly ?string $street = null,
        public readonly ?string $zipcode = null,
        public readonly ?string $city = null,
        /** free-text state/region claim value, resolved to a Shopware country-state id where possible */
        public readonly ?string $state = null,
        /** ISO-2 code or display name, resolved to a Shopware country id by CountryResolver */
        public readonly ?string $country = null,
        public readonly ?string $phone = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->street === null
            && $this->zipcode === null
            && $this->city === null
            && $this->state === null
            && $this->country === null
            && $this->phone === null;
    }
}
