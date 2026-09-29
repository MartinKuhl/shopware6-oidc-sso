<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Subscriber;

use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\UserProviderBindingService;
use MartinKuhl\Sw6Oidc\Subscriber\UserProviderCleanupSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\Uuid\Uuid;

#[CoversClass(UserProviderCleanupSubscriber::class)]
final class UserProviderCleanupSubscriberTest extends TestCase
{
    public function testCustomerDeletionRemovesItsBindingsInSystemScope(): void
    {
        $firstId = Uuid::randomHex();
        $secondId = Uuid::randomHex();
        $unbound = [];

        $bindingService = $this->createMock(UserProviderBindingService::class);
        $bindingService->expects(self::exactly(2))
            ->method('unbind')
            ->willReturnCallback(static function (string $userType, string $userId, Context $context) use (&$unbound): void {
                self::assertSame(Context::SYSTEM_SCOPE, $context->getScope());
                $unbound[] = [$userType, $userId];
            });

        (new UserProviderCleanupSubscriber($bindingService))->onCustomerDeleted($this->deletedEvent('customer', [$firstId, $secondId]));

        self::assertSame([
            [Sw6OidcUserProviderEntity::USER_TYPE_CUSTOMER, $firstId],
            [Sw6OidcUserProviderEntity::USER_TYPE_CUSTOMER, $secondId],
        ], $unbound);
    }

    public function testUserDeletionUsesAdminUserType(): void
    {
        $id = Uuid::randomHex();

        $bindingService = $this->createMock(UserProviderBindingService::class);
        $bindingService->expects(self::once())
            ->method('unbind')
            ->with(Sw6OidcUserProviderEntity::USER_TYPE_ADMIN, $id, self::isInstanceOf(Context::class));

        (new UserProviderCleanupSubscriber($bindingService))->onUserDeleted($this->deletedEvent('user', [$id]));
    }

    /**
     * @param list<string> $ids
     */
    private function deletedEvent(string $entityName, array $ids): EntityDeletedEvent
    {
        $results = array_map(
            static fn (string $id): EntityWriteResult => new EntityWriteResult($id, [], $entityName, EntityWriteResult::OPERATION_DELETE),
            $ids,
        );

        return new EntityDeletedEvent($entityName, $results, Context::createDefaultContext());
    }
}
