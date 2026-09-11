<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Resolves a free-text country claim (ISO-2, ISO-3, or a display name in any
 * shop language) to a Shopware `country` entity id — ISO passthrough first,
 * then a translated-name lookup, memoized per request. Mirrors the Magento
 * module's unified Model/Attribute/CountryResolver.php.
 */
class CountryResolver
{
    /** @var array<string, string|null> */
    private array $memoized = [];

    public function __construct(private readonly EntityRepository $countryRepository)
    {
    }

    public function resolveCountryId(?string $countryClaim, Context $context): ?string
    {
        if ($countryClaim === null || trim($countryClaim) === '') {
            return null;
        }

        $key = mb_strtolower(trim($countryClaim));

        if (\array_key_exists($key, $this->memoized)) {
            return $this->memoized[$key];
        }

        return $this->memoized[$key] = $this->lookup(trim($countryClaim), $context);
    }

    private function lookup(string $countryClaim, Context $context): ?string
    {
        $length = mb_strlen($countryClaim);

        if ($length === 2 || $length === 3) {
            $isoField = $length === 2 ? 'iso' : 'iso3';
            $criteria = new Criteria();
            $criteria->setLimit(1);
            $criteria->addFilter(new EqualsFilter($isoField, mb_strtoupper($countryClaim)));

            $id = $this->countryRepository->searchIds($criteria, $context)->firstId();

            if ($id !== null) {
                return $id;
            }
        }

        $nameCriteria = new Criteria();
        $nameCriteria->setLimit(1);
        $nameCriteria->addFilter(new EqualsFilter('translations.name', $countryClaim));

        return $this->countryRepository->searchIds($nameCriteria, $context)->firstId();
    }
}
