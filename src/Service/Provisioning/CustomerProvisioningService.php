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
            'countryId' => $this->countryResolver->resolveCountryId($billing?->country, $context)
                ?? $salesChannelContext->getSalesChannel()->getCountryId(),
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

            $customerPayload['addresses'][] = [
                'id' => $shippingAddressId,
                'customerId' => $customerId,
                'firstName' => $profile->firstName ?? $profile->email,
                'lastName' => $profile->lastName ?? '-',
                'street' => $shipping->street ?? '-',
                'zipcode' => $shipping->zipcode ?? '-',
                'city' => $shipping->city ?? '-',
                'phoneNumber' => $shipping->phone,
                'countryId' => $this->countryResolver->resolveCountryId($shipping->country, $context)
                    ?? $salesChannelContext->getSalesChannel()->getCountryId(),
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
