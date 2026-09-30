<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Subscriber;

use MartinKuhl\Sw6Oidc\Service\Security\LockoutGuard;
use MartinKuhl\Sw6Oidc\Service\Security\PasswordLoginPolicy;
use MartinKuhl\Sw6Oidc\Subscriber\AdminLockoutGuardSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\User\UserDefinition;

#[CoversClass(AdminLockoutGuardSubscriber::class)]
final class AdminLockoutGuardSubscriberTest extends TestCase
{
    public function testDeletingTheLastSsoAdminIsRefusedUnderSsoOnlyMode(): void
    {
        self::assertCount(1, $this->validate($this->command(DeleteCommand::class, []), policyOn: true, remainsPossible: false));
    }

    public function testDeactivatingTheLastSsoAdminIsRefusedUnderSsoOnlyMode(): void
    {
        self::assertCount(1, $this->validate($this->command(UpdateCommand::class, ['active' => 0]), policyOn: true, remainsPossible: false));
    }

    public function testOtherAdminsKeepAccess(): void
    {
        self::assertCount(0, $this->validate($this->command(DeleteCommand::class, []), policyOn: true, remainsPossible: true));
    }

    public function testNothingIsCheckedWithoutSsoOnlyMode(): void
    {
        self::assertCount(0, $this->validate($this->command(DeleteCommand::class, []), policyOn: false, remainsPossible: false));
    }

    public function testUnrelatedUpdatesPass(): void
    {
        self::assertCount(0, $this->validate($this->command(UpdateCommand::class, ['first_name' => 'x']), policyOn: true, remainsPossible: false));
    }

    /**
     * @return array<\Throwable>
     */
    private function validate(WriteCommand $command, bool $policyOn, bool $remainsPossible): array
    {
        $guard = $this->createStub(LockoutGuard::class);
        $guard->method('adminLoginRemainsPossible')->willReturn($remainsPossible);
        $policy = $this->createStub(PasswordLoginPolicy::class);
        $policy->method('isPasswordLoginDisabled')->willReturn($policyOn);

        $event = new PreWriteValidationEvent(WriteContext::createFromContext(Context::createDefaultContext()), [$command]);
        (new AdminLockoutGuardSubscriber($guard, $policy))->validate($event);

        return $event->getExceptions()->getExceptions();
    }

    /**
     * @param class-string<WriteCommand> $class
     * @param array<string, mixed> $payload
     */
    private function command(string $class, array $payload): WriteCommand
    {
        $command = $this->createMock($class);
        $command->method('getEntityName')->willReturn(UserDefinition::ENTITY_NAME);
        $command->method('getPayload')->willReturn($payload);
        $command->method('getPrimaryKey')->willReturn(['id' => Uuid::randomBytes()]);
        $command->method('getPath')->willReturn('/0');

        return $command;
    }
}
