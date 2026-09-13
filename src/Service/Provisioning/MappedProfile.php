<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning;

/**
 * Claim values already resolved against a provider's sw6oidc_attribute_mapping
 * rows, ready to be written to a Shopware customer/user (+ address) entity.
 * Every field but email is optional — full attribute parity (Phase 1 decision)
 * without forcing every claim to be configured.
 */
final readonly class MappedProfile
{
    public function __construct(
        public string $email,
        public ?string $username = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        /** raw claim value, format expected YYYY-MM-DD */
        public ?string $birthday = null,
        /** normalized via GenderMapper before this DTO is built */
        public ?string $salutationTechnicalName = null,
        public ?string $phone = null,
        public ?AddressProfile $billingAddress = null,
        public ?AddressProfile $shippingAddress = null,
        /** @var string[] */
        public array $groups = [],
    ) {
    }
}
