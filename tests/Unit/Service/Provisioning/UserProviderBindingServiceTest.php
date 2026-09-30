<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Provisioning;

use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderCollection;
use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderDefinition;
use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\ProviderMismatchException;
use MartinKuhl\Sw6Oidc\Service\Provisioning\UserProviderBindingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

#[CoversClass(UserProviderBindingService::class)]
final class UserProviderBindingServiceTest extends TestCase
{
    private const USER_TYPE = Sw6OidcUserProviderEntity::USER_TYPE_ADMIN;

    public function testGetBoundProviderIdReturnsNullWhenUnbound(): void
    {
        $service = new UserProviderBindingService($this->repositoryReturning(null));

        self::assertNull($service->getBoundProviderId(self::USER_TYPE, Uuid::randomHex(), Context::createDefaultContext()));
    }

    public function testGetBoundProviderIdReturnsBoundProviderAndFiltersByUserTypeAndUserId(): void
    {
        $userId = Uuid::randomHex();
        $providerId = Uuid::randomHex();
        $capturedCriteria = null;

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use (&$capturedCriteria, $userId, $providerId): EntitySearchResult {
                $capturedCriteria = $criteria;

                return $this->searchResult($this->binding($userId, $providerId), $criteria, $context);
            });

        $service = new UserProviderBindingService($repository);

        self::assertSame($providerId, $service->getBoundProviderId(Sw6OidcUserProviderEntity::USER_TYPE_CUSTOMER, $userId, Context::createDefaultContext()));
        self::assertInstanceOf(Criteria::class, $capturedCriteria);
        self::assertEquals([
            new EqualsFilter('userType', Sw6OidcUserProviderEntity::USER_TYPE_CUSTOMER),
            new EqualsFilter('userId', $userId),
        ], $capturedCriteria->getFilters());
    }

    public function testAssertNotBoundToDifferentProviderPassesWhenUnbound(): void
    {
        $service = new UserProviderBindingService($this->repositoryReturning(null));

        $service->assertNotBoundToDifferentProvider(self::USER_TYPE, Uuid::randomHex(), Uuid::randomHex(), Context::createDefaultContext());

        $this->addToAssertionCount(1);
    }

    public function testAssertNotBoundToDifferentProviderPassesWhenBoundToSameProvider(): void
    {
        $userId = Uuid::randomHex();
        $providerId = Uuid::randomHex();
        $service = new UserProviderBindingService($this->repositoryReturning($this->binding($userId, $providerId)));

        $service->assertNotBoundToDifferentProvider(self::USER_TYPE, $userId, $providerId, Context::createDefaultContext());

        $this->addToAssertionCount(1);
    }

    public function testAssertNotBoundToDifferentProviderThrowsWhenBoundToDifferentProvider(): void
    {
        $userId = Uuid::randomHex();
        $service = new UserProviderBindingService($this->repositoryReturning($this->binding($userId, Uuid::randomHex())));

        $this->expectException(ProviderMismatchException::class);
        $this->expectExceptionMessage('This admin account was created with a different identity provider.');

        $service->assertNotBoundToDifferentProvider(self::USER_TYPE, $userId, Uuid::randomHex(), Context::createDefaultContext());
    }

    public function testBindIfUnboundCreatesBindingWhenUnbound(): void
    {
        $userId = Uuid::randomHex();
        $providerId = Uuid::randomHex();
        $context = Context::createDefaultContext();

        $repository = $this->repositoryReturning(null);
        $repository->expects(self::once())
            ->method('create')
            ->with(
                self::callback(static function (array $payload) use ($userId, $providerId): bool {
                    self::assertCount(1, $payload);
                    self::assertTrue(Uuid::isValid($payload[0]['id']));
                    unset($payload[0]['id']);
                    self::assertSame([
                        'userType' => self::USER_TYPE,
                        'userId' => $userId,
                        'providerId' => $providerId,
                    ], $payload[0]);

                    return true;
                }),
                $context,
            );

        (new UserProviderBindingService($repository))->bindIfUnbound(self::USER_TYPE, $userId, $providerId, $context);
    }

    public function testBindIfUnboundDoesNotWriteWhenAlreadyBoundToSameProvider(): void
    {
        $userId = Uuid::randomHex();
        $providerId = Uuid::randomHex();

        $repository = $this->repositoryReturning($this->binding($userId, $providerId));
        $repository->expects(self::never())->method('create');

        (new UserProviderBindingService($repository))->bindIfUnbound(self::USER_TYPE, $userId, $providerId, Context::createDefaultContext());
    }

    public function testBindIfUnboundDoesNotOverwriteBindingToDifferentProvider(): void
    {
        $userId = Uuid::randomHex();

        $repository = $this->repositoryReturning($this->binding($userId, Uuid::randomHex()));
        $repository->expects(self::never())->method('create');

        (new UserProviderBindingService($repository))->bindIfUnbound(self::USER_TYPE, $userId, Uuid::randomHex(), Context::createDefaultContext());
    }

    public function testUnbindDeletesAllBindingRowsForUser(): void
    {
        $userId = Uuid::randomHex();
        $ids = [Uuid::randomHex(), Uuid::randomHex()];
        $context = Context::createDefaultContext();

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('searchIds')
            ->willReturnCallback(function (Criteria $criteria, Context $ctx) use ($ids, $userId): IdSearchResult {
                self::assertEquals([
                    new EqualsFilter('userType', self::USER_TYPE),
                    new EqualsFilter('userId', $userId),
                ], $criteria->getFilters());

                return $this->idSearchResult($ids, $criteria, $ctx);
            });
        $repository->expects(self::once())
            ->method('delete')
            ->with([['id' => $ids[0]], ['id' => $ids[1]]], $context);

        (new UserProviderBindingService($repository))->unbind(self::USER_TYPE, $userId, $context);
    }

    public function testUnbindIsNoOpWhenUnbound(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('searchIds')
            ->willReturnCallback(fn (Criteria $criteria, Context $ctx): IdSearchResult => $this->idSearchResult([], $criteria, $ctx));
        $repository->expects(self::never())->method('delete');

        (new UserProviderBindingService($repository))->unbind(self::USER_TYPE, Uuid::randomHex(), Context::createDefaultContext());
    }

    private function binding(string $userId, string $providerId): Sw6OidcUserProviderEntity
    {
        $entity = new Sw6OidcUserProviderEntity();
        $entity->setId(Uuid::randomHex());
        $entity->setUserType(self::USER_TYPE);
        $entity->setUserId($userId);
        $entity->setProviderId($providerId);

        return $entity;
    }

    private function repositoryReturning(?Sw6OidcUserProviderEntity $binding): EntityRepository&\PHPUnit\Framework\MockObject\MockObject
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')
            ->willReturnCallback(fn (Criteria $criteria, Context $context): EntitySearchResult => $this->searchResult($binding, $criteria, $context));

        return $repository;
    }

    private function searchResult(?Sw6OidcUserProviderEntity $binding, Criteria $criteria, Context $context): EntitySearchResult
    {
        $entities = $binding === null ? [] : [$binding];

        return new EntitySearchResult(
            Sw6OidcUserProviderDefinition::ENTITY_NAME,
            \count($entities),
            new Sw6OidcUserProviderCollection($entities),
            null,
            $criteria,
            $context,
        );
    }

    /**
     * @param string[] $ids
     */
    private function idSearchResult(array $ids, Criteria $criteria, Context $context): IdSearchResult
    {
        return new IdSearchResult(
            \count($ids),
            array_map(static fn (string $id): array => ['primaryKey' => $id, 'data' => []], $ids),
            $criteria,
            $context,
        );
    }
}
