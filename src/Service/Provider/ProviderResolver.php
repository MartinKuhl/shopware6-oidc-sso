<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provider;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provider\Exception\ProviderNotFoundException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Resolves sw6oidc_provider rows for the current request — multi-provider
 * support from day one, mirroring the Magento module's Model/Provider/ProviderResolver.php.
 */
class ProviderResolver
{
    public function __construct(private readonly EntityRepository $providerRepository)
    {
    }

    /**
     * @throws ProviderNotFoundException
     */
    public function getActiveById(string $providerId, Context $context): Sw6OidcProviderEntity
    {
        $provider = $this->providerRepository->search(new Criteria([$providerId]), $context)->first();

        if (!$provider instanceof Sw6OidcProviderEntity || !$provider->isActive()) {
            throw new ProviderNotFoundException(sprintf('No active OIDC provider found for id "%s".', $providerId));
        }

        return $provider;
    }

    /**
     * @return Sw6OidcProviderEntity[]
     */
    public function getActiveProviders(string $loginType, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('isActive', true));
        $criteria->addFilter(new EqualsAnyFilter('loginType', [$loginType, 'both']));
        $criteria->addSorting(new FieldSorting('sortOrder', FieldSorting::ASCENDING));

        return array_values(iterator_to_array($this->providerRepository->search($criteria, $context)->getEntities()));
    }

    /**
     * SP-initiated login without an explicit ?provider_id= falls back to the
     * first active provider for this login type — matches the Magento module's
     * no-explicit-ID fallback in ProviderResolver::resolveActiveProvider().
     *
     * @throws ProviderNotFoundException
     */
    public function resolveDefault(string $loginType, Context $context): Sw6OidcProviderEntity
    {
        $providers = $this->getActiveProviders($loginType, $context);

        if ($providers === []) {
            throw new ProviderNotFoundException(sprintf('No active OIDC provider is configured for login type "%s".', $loginType));
        }

        return $providers[0];
    }
}
