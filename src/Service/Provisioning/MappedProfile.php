<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning;

/**
 * Claim values already resolved against a provider's sw6oidc_attribute_mapping
 * rows, ready to be written to a Shopware customer/user (+ address) entity.
 * Every field but email is optional — full attribute parity (Phase 1 decision)
 * without forcing every claim to be configured.
 */
final class MappedProfile
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $username = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        /** raw claim value, format expected YYYY-MM-DD */
        public readonly ?string $birthday = null,
        /** normalized via GenderMapper before this DTO is built */
        public readonly ?string $salutationTechnicalName = null,
        public readonly ?string $phone = null,
        public readonly ?AddressProfile $billingAddress = null,
        public readonly ?AddressProfile $shippingAddress = null,
        /** @var string[] */
        public readonly array $groups = [],
    ) {
    }
}
