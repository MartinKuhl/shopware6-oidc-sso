<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Provider;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderCollection;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderDefinition;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

#[CoversClass(ProviderResolver::class)]
final class ProviderResolverTest extends TestCase
{
    public function testFindByIssuerFiltersActiveProvidersByExactIssuer(): void
    {
        $provider = new Sw6OidcProviderEntity();
        $provider->setId('p1');

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('search')->willReturnCallback(static function (Criteria $criteria, Context $context) use ($provider): EntitySearchResult {
            $filters = array_map(static fn (EqualsFilter $filter): array => [$filter->getField(), $filter->getValue()], $criteria->getFilters());
            self::assertSame([['isActive', true], ['issuer', 'https://idp.example']], $filters);

            return new EntitySearchResult(Sw6OidcProviderDefinition::ENTITY_NAME, 1, new Sw6OidcProviderCollection([$provider]), null, $criteria, $context);
        });

        self::assertSame([$provider], (new ProviderResolver($repository))->findByIssuer('https://idp.example', Context::createDefaultContext()));
    }

    public function testEmptyIssuerNeverQueries(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('search');

        self::assertSame([], (new ProviderResolver($repository))->findByIssuer('', Context::createDefaultContext()));
    }
}
