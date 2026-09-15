<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Provisioning;

use MartinKuhl\Sw6Oidc\Core\Content\AttributeMapping\Sw6OidcAttributeMappingCollection;
use MartinKuhl\Sw6Oidc\Core\Content\AttributeMapping\Sw6OidcAttributeMappingDefinition as Attr;
use MartinKuhl\Sw6Oidc\Core\Content\AttributeMapping\Sw6OidcAttributeMappingEntity;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\AttributeMapper;
use MartinKuhl\Sw6Oidc\Service\Provisioning\GenderMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

#[CoversClass(AttributeMapper::class)]
final class AttributeMapperTest extends TestCase
{
    public function testMapsLocaleZoneinfoAndPictureUsingDefaultClaimKeys(): void
    {
        $mapper = new AttributeMapper($this->repositoryReturning([]), new GenderMapper());

        $profile = $mapper->map($this->provider(), [
            'email' => 'user@example.com',
            'locale' => 'de-DE',
            'zoneinfo' => 'Europe/Berlin',
            'picture' => 'https://idp.example.com/avatar.png',
        ], [], Context::createDefaultContext());

        self::assertSame('de-DE', $profile->locale);
        self::assertSame('Europe/Berlin', $profile->zoneinfo);
        self::assertSame('https://idp.example.com/avatar.png', $profile->picture);
    }

    public function testMapsLocaleZoneinfoAndPictureUsingConfiguredClaimKeyOverrides(): void
    {
        $providerId = Uuid::randomHex();

        $mapper = new AttributeMapper(
            $this->repositoryReturning([
                $this->mappingEntity($providerId, Attr::TYPE_LOCALE, 'user_locale'),
                $this->mappingEntity($providerId, Attr::TYPE_ZONEINFO, 'tz'),
                $this->mappingEntity($providerId, Attr::TYPE_PICTURE, 'avatar_url'),
            ]),
            new GenderMapper(),
        );

        $profile = $mapper->map($this->provider($providerId), [
            'email' => 'user@example.com',
            'user_locale' => 'en-GB',
            'tz' => 'America/New_York',
            'avatar_url' => 'https://idp.example.com/other-avatar.png',
        ], [], Context::createDefaultContext());

        self::assertSame('en-GB', $profile->locale);
        self::assertSame('America/New_York', $profile->zoneinfo);
        self::assertSame('https://idp.example.com/other-avatar.png', $profile->picture);
    }

    public function testLeavesLocaleZoneinfoAndPictureNullWhenClaimIsMissing(): void
    {
        $mapper = new AttributeMapper($this->repositoryReturning([]), new GenderMapper());

        $profile = $mapper->map($this->provider(), [
            'email' => 'user@example.com',
        ], [], Context::createDefaultContext());

        self::assertNull($profile->locale);
        self::assertNull($profile->zoneinfo);
        self::assertNull($profile->picture);
    }

    private function provider(?string $id = null): Sw6OidcProviderEntity
    {
        $provider = new Sw6OidcProviderEntity();
        $provider->setId($id ?? Uuid::randomHex());

        return $provider;
    }

    private function mappingEntity(string $providerId, string $attributeType, string $attributeName): Sw6OidcAttributeMappingEntity
    {
        $entity = new Sw6OidcAttributeMappingEntity();
        $entity->setId(Uuid::randomHex());
        $entity->setProviderId($providerId);
        $entity->setAttributeType($attributeType);
        $entity->setAttributeName($attributeName);

        return $entity;
    }

    /**
     * @param Sw6OidcAttributeMappingEntity[] $entities
     */
    private function repositoryReturning(array $entities): EntityRepository
    {
        $collection = new Sw6OidcAttributeMappingCollection($entities);
        $apiAlias = $entities === [] ? Attr::ENTITY_NAME : $entities[0]->getApiAlias();

        $result = new EntitySearchResult(
            $apiAlias,
            \count($entities),
            $collection,
            null,
            new Criteria(),
            Context::createDefaultContext(),
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }
}
