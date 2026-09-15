<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\CustomerProvisioningDeniedException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Finds-or-JIT-creates a Shopware customer from a MappedProfile, enforcing
 * per-user IdP binding and resolving group mapping — Shopware equivalent of
 * the Magento module's Model/Service/CustomerUserCreator.php.
 *
 * NOTE: field requirements here (customer + customer_address) are written
 * against the documented Shopware 6.7 DAL contract but have not been exercised
 * against a live instance in this environment — verify end-to-end per the
 * plan's Verification section before relying on this in production.
 */
class CustomerProvisioningService
{
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $salutationRepository,
        private readonly CountryResolver $countryResolver,
        private readonly GroupMappingResolver $groupMappingResolver,
        private readonly UserProviderBindingService $bindingService,
        private readonly NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws CustomerProvisioningDeniedException
     */
    public function findOrCreateCustomer(
        Sw6OidcProviderEntity $provider,
        MappedProfile $profile,
        SalesChannelContext $salesChannelContext,
    ): CustomerEntity {
        $context = $salesChannelContext->getContext();
        $existing = $this->findByEmail($profile->email, $salesChannelContext);

        if ($existing instanceof \Shopware\Core\Checkout\Customer\CustomerEntity) {
            $this->bindingService->assertNotBoundToDifferentProvider(
                Sw6OidcUserProviderEntity::USER_TYPE_CUSTOMER,
                $existing->getId(),
                $provider->getId(),
                $context,
            );
            $this->bindingService->bindIfUnbound(
                Sw6OidcUserProviderEntity::USER_TYPE_CUSTOMER,
                $existing->getId(),
                $provider->getId(),
                $context,
            );

            $this->syncExisting($provider, $existing, $profile, $salesChannelContext);

            return $existing;
        }

        if (!$provider->isAutoCreateCustomer()) {
            throw new CustomerProvisioningDeniedException(sprintf(
                'No customer account exists for "%s" and auto-creation is disabled for this provider.',
                $profile->email,
            ));
        }

        $customerId = $this->create($provider, $profile, $salesChannelContext);
        $this->bindingService->bindIfUnbound(Sw6OidcUserProviderEntity::USER_TYPE_CUSTOMER, $customerId, $provider->getId(), $context);

        $created = $this->customerRepository->search(new Criteria([$customerId]), $context)->first();
        \assert($created instanceof CustomerEntity);

        return $created;
    }

    private function findByEmail(string $email, SalesChannelContext $salesChannelContext): ?CustomerEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('email', $email));
        $criteria->addFilter(new EqualsFilter('guest', false));

        $customers = $this->customerRepository->search($criteria, $salesChannelContext->getContext())->getEntities();

        foreach ($customers as $customer) {
            \assert($customer instanceof CustomerEntity);

            $boundSalesChannelId = $customer->getBoundSalesChannelId();

            if ($boundSalesChannelId === null || $boundSalesChannelId === $salesChannelContext->getSalesChannelId()) {
                return $customer;
            }
        }

        return null;
    }

    private function create(Sw6OidcProviderEntity $provider, MappedProfile $profile, SalesChannelContext $salesChannelContext): string
    {
        $context = $salesChannelContext->getContext();
        $customerId = Uuid::randomHex();
        $billingAddressId = Uuid::randomHex();

        $groupId = $this->groupMappingResolver->resolveCustomerGroupId($provider, $profile->groups, $context)
            ?? $salesChannelContext->getCurrentCustomerGroup()->getId();

        $customerNumber = $this->numberRangeValueGenerator->getValue(
            'customer',
            $context,
            $salesChannelContext->getSalesChannelId(),
        );

        $billing = $profile->billingAddress;

        $billingCountryId = $this->countryResolver->resolveCountryId($billing?->country, $context)
            ?? $salesChannelContext->getSalesChannel()->getCountryId();

        // $billing is genuinely nullable (no billing address claim mapped) -
        // PHPStan's nullsafe.neverNull flags each `?->` below as
        // "unnecessary" even in a minimal repro where $billing is narrowed
        // to non-null immediately beforehand, so this looks like a rule
        // quirk rather than a real finding; following its suggested fix
        // (plain ->) would throw on a null $billing instead of falling
        // through to the '-' default.
        $addressPayload = [
            'id' => $billingAddressId,
            'customerId' => $customerId,
            'firstName' => $profile->firstName ?? $profile->email,
            'lastName' => $profile->lastName ?? '-',
            'street' => $billing?->street ?? '-', // @phpstan-ignore nullsafe.neverNull
            'zipcode' => $billing?->zipcode ?? '-', // @phpstan-ignore nullsafe.neverNull
            'city' => $billing?->city ?? '-', // @phpstan-ignore nullsafe.neverNull
            'phoneNumber' => $billing?->phone ?? $profile->phone, // @phpstan-ignore nullsafe.neverNull
            'countryId' => $billingCountryId,
            'countryStateId' => $this->countryResolver->resolveCountryStateId($billing?->state, $billingCountryId, $context),
        ];

        $customerPayload = [
            'id' => $customerId,
            'salesChannelId' => $salesChannelContext->getSalesChannelId(),
            'languageId' => $salesChannelContext->getLanguageId(),
            'groupId' => $groupId,
            'defaultPaymentMethodId' => $salesChannelContext->getPaymentMethod()->getId(),
            'salutationId' => $this->resolveSalutationId($profile->salutationTechnicalName, $context),
            'customerNumber' => $customerNumber,
            'firstName' => $profile->firstName ?? $profile->email,
            'lastName' => $profile->lastName ?? '-',
            'email' => $profile->email,
            'guest' => false,
            'active' => true,
            'password' => bin2hex(random_bytes(32)),
            'birthday' => $profile->birthday !== null ? new \DateTimeImmutable($profile->birthday) : null,
            'addresses' => [$addressPayload],
            'defaultBillingAddressId' => $billingAddressId,
            'defaultShippingAddressId' => $billingAddressId,
        ];

        if ($profile->shippingAddress instanceof \MartinKuhl\Sw6Oidc\Service\Provisioning\AddressProfile && !$profile->shippingAddress->isEmpty()) {
            $shippingAddressId = Uuid::randomHex();
            $shipping = $profile->shippingAddress;

            $shippingCountryId = $this->countryResolver->resolveCountryId($shipping->country, $context)
                ?? $salesChannelContext->getSalesChannel()->getCountryId();

            $customerPayload['addresses'][] = [
                'id' => $shippingAddressId,
                'customerId' => $customerId,
                'firstName' => $profile->firstName ?? $profile->email,
                'lastName' => $profile->lastName ?? '-',
                'street' => $shipping->street ?? '-',
                'zipcode' => $shipping->zipcode ?? '-',
                'city' => $shipping->city ?? '-',
                'phoneNumber' => $shipping->phone,
                'countryId' => $shippingCountryId,
                'countryStateId' => $this->countryResolver->resolveCountryStateId($shipping->state, $shippingCountryId, $context),
            ];
            $customerPayload['defaultShippingAddressId'] = $shippingAddressId;
        }

        $this->customerRepository->create([$customerPayload], $context);

        $this->logger->info('sw6oidc: JIT-created customer via OIDC.', [
            'providerId' => $provider->getId(),
            'customerId' => $customerId,
        ]);

        return $customerId;
    }

    /**
     * Re-applies mapped claims to an already-existing (bound) customer on
     * every login, per the provider's independent sync_* toggles. Each is
     * additive/partial-update only: a claim that isn't mapped (null on the
     * profile) or a group mapping that doesn't resolve is simply left
     * alone, never overwritten with a placeholder — this is a refresh of
     * whatever the IdP actually provided, not a reset to defaults. Address
     * sync only ever updates the customer's *existing* default billing/
     * shipping addresses in place (never creates one here) — this method
     * never grows to be a rerun of the full create() path, which is unaware
     * of the pre-existing customer_address invariants (customerId, all
     * Shopware-required fields) an update to a currently-nonexistent id
     * would need. Shipping is skipped entirely when it's the same address
     * row as billing (create() only ever splits them into two rows when a
     * distinct shipping address was actually mapped) — syncing it a second
     * time from separate shipping-claim data would otherwise overwrite that
     * one shared row with two different, possibly conflicting sets of
     * values in the same update call.
     */
    private function syncExisting(
        Sw6OidcProviderEntity $provider,
        CustomerEntity $existing,
        MappedProfile $profile,
        SalesChannelContext $salesChannelContext,
    ): void {
        $context = $salesChannelContext->getContext();
        $payload = ['id' => $existing->getId()];

        if ($provider->isSyncCustomerProfileOnSso()) {
            if ($profile->firstName !== null) {
                $payload['firstName'] = $profile->firstName;
            }

            if ($profile->lastName !== null) {
                $payload['lastName'] = $profile->lastName;
            }

            if ($profile->birthday !== null) {
                $payload['birthday'] = new \DateTimeImmutable($profile->birthday);
            }

            if ($profile->salutationTechnicalName !== null) {
                $salutationId = $this->resolveSalutationId($profile->salutationTechnicalName, $context);

                if ($salutationId !== null) {
                    $payload['salutationId'] = $salutationId;
                }
            }
        }

        if ($provider->isSyncCustomerGroupOnSso()) {
            $groupId = $this->groupMappingResolver->resolveCustomerGroupId($provider, $profile->groups, $context);

            if ($groupId !== null) {
                $payload['groupId'] = $groupId;
            }
        }

        if ($provider->isSyncCustomerAddressOnSso()) {
            $addressPayloads = [];

            $billingAddressId = $existing->getDefaultBillingAddressId();
            $billingPayload = $this->buildAddressSyncPayload($billingAddressId, $profile->billingAddress, $context);

            if ($billingPayload !== null) {
                $addressPayloads[] = $billingPayload;
            }

            $shippingAddressId = $existing->getDefaultShippingAddressId();

            if ($shippingAddressId !== $billingAddressId) {
                $shippingPayload = $this->buildAddressSyncPayload($shippingAddressId, $profile->shippingAddress, $context);

                if ($shippingPayload !== null) {
                    $addressPayloads[] = $shippingPayload;
                }
            }

            if ($addressPayloads !== []) {
                $payload['addresses'] = $addressPayloads;
            }
        }

        if (\count($payload) > 1) {
            $this->customerRepository->update([$payload], $context);
        }
    }

    /**
     * @return array<string, mixed>|null null if there's nothing mapped to sync
     */
    private function buildAddressSyncPayload(string $addressId, ?AddressProfile $address, Context $context): ?array
    {
        if (!$address instanceof AddressProfile || $address->isEmpty()) {
            return null;
        }

        $addressPayload = ['id' => $addressId];

        if ($address->street !== null) {
            $addressPayload['street'] = $address->street;
        }

        if ($address->zipcode !== null) {
            $addressPayload['zipcode'] = $address->zipcode;
        }

        if ($address->city !== null) {
            $addressPayload['city'] = $address->city;
        }

        if ($address->phone !== null) {
            $addressPayload['phoneNumber'] = $address->phone;
        }

        $countryId = $this->countryResolver->resolveCountryId($address->country, $context);

        if ($countryId !== null) {
            $addressPayload['countryId'] = $countryId;
        }

        // State can only be resolved once its country is known - if the
        // country claim isn't mapped/didn't resolve this login, the state
        // claim (if any) is simply left unsynced rather than guessed against
        // whatever country the address currently happens to have.
        if ($countryId !== null && $address->state !== null) {
            $countryStateId = $this->countryResolver->resolveCountryStateId($address->state, $countryId, $context);

            if ($countryStateId !== null) {
                $addressPayload['countryStateId'] = $countryStateId;
            }
        }

        return \count($addressPayload) > 1 ? $addressPayload : null;
    }

    private function resolveSalutationId(?string $technicalName, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->setLimit(1);
        $criteria->addFilter(new EqualsFilter('salutationKey', $technicalName ?? 'not_specified'));

        $id = $this->salutationRepository->searchIds($criteria, $context)->firstId();

        if ($id !== null) {
            return $id;
        }

        $fallbackCriteria = new Criteria();
        $fallbackCriteria->setLimit(1);
        $fallbackCriteria->addFilter(new EqualsFilter('salutationKey', 'not_specified'));

        return $this->salutationRepository->searchIds($fallbackCriteria, $context)->firstId();
    }
}
