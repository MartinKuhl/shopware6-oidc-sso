<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Subscriber;

use Doctrine\DBAL\Connection;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderDefinition;
use MartinKuhl\Sw6Oidc\Service\Security\LockoutConfirmationStore;
use MartinKuhl\Sw6Oidc\Service\Security\LockoutGuard;
use MartinKuhl\Sw6Oidc\Service\Security\PasswordSessionRevoker;
use MartinKuhl\Sw6Oidc\Service\Security\SsrfUrlValidator;
use MartinKuhl\Sw6Oidc\Service\Security\Sw6OidcEncryptor;
use MartinKuhl\Sw6Oidc\Subscriber\Sw6OidcProviderWriteGuardSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;

#[CoversClass(Sw6OidcProviderWriteGuardSubscriber::class)]
final class Sw6OidcProviderWriteGuardSubscriberTest extends TestCase
{
    private const APP_SECRET = 'test-app-secret';

    /** @var array<string, mixed> */
    private array $currentRow = [
        'is_active' => 1,
        'login_type' => 'both',
        'disable_non_oidc_admin_login' => 0,
        'disable_non_oidc_customer_login' => 0,
        'show_admin_link' => 1,
        'show_customer_link' => 1,
    ];

    /** @var list<string> */
    private array $unboundAdmins = [];

    private bool $otherVisibleProvider = true;

    private bool $adminLoginRemainsPossible = true;

    private bool $confirmed = false;

    private PasswordSessionRevoker&MockObject $revoker;

    private Sw6OidcProviderWriteGuardSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->revoker = $this->createMock(PasswordSessionRevoker::class);
    }

    public function testEncryptedWebhookUrlIsDecryptedAndSsrfChecked(): void
    {
        $encrypted = (new Sw6OidcEncryptor(self::APP_SECRET))->encrypt('https://hooks.internal.example/T000/B000/xyz');

        $violation = $this->singleViolation($this->validateCommands([$this->command(UpdateCommand::class, ['health_alert_webhook_url' => $encrypted])], privateIps: true));
        self::assertSame('/healthAlertWebhookUrl', $violation->getPropertyPath());
        self::assertSame(Sw6OidcProviderWriteGuardSubscriber::CODE_URL_BLOCKED, $violation->getCode());

        self::assertCount(0, $this->validateCommands([$this->command(UpdateCommand::class, ['health_alert_webhook_url' => $encrypted])])->getExceptions()->getExceptions());
    }

    public function testBlockedEndpointAddsFieldScopedViolation(): void
    {
        $event = $this->validateCommands([$this->command(UpdateCommand::class, ['jwks_endpoint' => 'https://internal.example/jwks'])], privateIps: true);

        $violation = $this->singleViolation($event);
        self::assertSame('/jwksEndpoint', $violation->getPropertyPath());
        self::assertSame(Sw6OidcProviderWriteGuardSubscriber::CODE_URL_BLOCKED, $violation->getCode());
    }

    public function testPublicEndpointsPass(): void
    {
        $event = $this->validateCommands([$this->command(InsertCommand::class, [
            'authorize_endpoint' => 'https://idp.example/authorize',
            'access_token_endpoint' => 'https://idp.example/token',
            'well_known_config_url' => 'https://idp.example/.well-known/openid-configuration',
        ])]);

        self::assertCount(0, $event->getExceptions()->getExceptions());
    }

    public function testIssuerIsNotValidated(): void
    {
        $event = $this->validateCommands([$this->command(UpdateCommand::class, ['issuer' => 'http://127.0.0.1'])], privateIps: true);

        self::assertCount(0, $event->getExceptions()->getExceptions());
    }

    public function testWritesWithoutUrlFieldsAreIgnored(): void
    {
        $event = $this->validateCommands([$this->command(UpdateCommand::class, ['last_test_status' => 'passed'])], privateIps: true);

        self::assertCount(0, $event->getExceptions()->getExceptions());
    }

    public function testPostLogoutUrlMustBeAnAbsoluteHttpUrl(): void
    {
        foreach (['javascript:alert(1)', '/account/login', 'data:text/html,x', 'https://'] as $bad) {
            $violation = $this->singleViolation($this->validateCommands([$this->command(UpdateCommand::class, ['post_logout_url' => $bad])]));
            self::assertSame('/postLogoutUrl', $violation->getPropertyPath(), $bad);
            self::assertSame(Sw6OidcProviderWriteGuardSubscriber::CODE_REDIRECT_URL_INVALID, $violation->getCode());
        }
    }

    public function testPostLogoutUrlIsNotSsrfChecked(): void
    {
        $event = $this->validateCommands([$this->command(UpdateCommand::class, ['post_logout_url' => 'http://localhost/sw6oidc/postlogout'])], privateIps: true);

        self::assertCount(0, $event->getExceptions()->getExceptions());
    }

    public function testEnablingDisableFlagWithoutBoundAccountIsRejected(): void
    {
        $event = $this->validateCommands([$this->command(UpdateCommand::class, ['disable_non_oidc_admin_login' => 1])], boundAccount: false);

        $violation = $this->singleViolation($event);
        self::assertSame('/disableNonOidcAdminLogin', $violation->getPropertyPath());
        self::assertSame(Sw6OidcProviderWriteGuardSubscriber::CODE_LOCKOUT_GUARD, $violation->getCode());
    }

    public function testEnablingDisableFlagWithBoundAccountPasses(): void
    {
        $event = $this->validateCommands([$this->command(UpdateCommand::class, ['disable_non_oidc_customer_login' => 1])], boundAccount: true);

        self::assertCount(0, $event->getExceptions()->getExceptions());
    }

    public function testDisablingTheFlagNeverNeedsABinding(): void
    {
        $event = $this->validateCommands([$this->command(UpdateCommand::class, ['disable_non_oidc_admin_login' => 0])], boundAccount: false);

        self::assertCount(0, $event->getExceptions()->getExceptions());
    }

    public function testDeletesAreIgnored(): void
    {
        $event = $this->validateCommands([$this->command(DeleteCommand::class, [])], privateIps: true);

        self::assertCount(0, $event->getExceptions()->getExceptions());
    }

    public function testUnboundAdminsNeedAnExplicitConfirmation(): void
    {
        $this->unboundAdmins = ['a', 'b'];

        $violation = $this->singleViolation($this->validateCommands([$this->command(UpdateCommand::class, ['disable_non_oidc_admin_login' => 1])], boundAccount: true));

        self::assertSame(Sw6OidcProviderWriteGuardSubscriber::CODE_LOCKOUT_UNBOUND_USERS, $violation->getCode());
        self::assertStringContainsString('2 active admin', (string) $violation->getMessage());
    }

    public function testConfirmedLockoutPassesAndSchedulesSessionRevocation(): void
    {
        $this->unboundAdmins = ['a'];
        $this->confirmed = true;
        $this->revoker->expects(self::once())->method('revokeUnboundAdminSessions');

        $command = $this->command(UpdateCommand::class, ['disable_non_oidc_admin_login' => 1]);
        $event = $this->validateCommands([$command], boundAccount: true);

        self::assertCount(0, $event->getExceptions()->getExceptions());

        $providerId = $command->getPrimaryKey()['id'];
        \assert(\is_string($providerId));
        $this->subscriber->onProviderWritten(new EntityWrittenEvent(
            Sw6OidcProviderDefinition::ENTITY_NAME,
            [new EntityWriteResult(Uuid::fromBytesToHex($providerId), [], Sw6OidcProviderDefinition::ENTITY_NAME, EntityWriteResult::OPERATION_UPDATE)],
            Context::createDefaultContext(),
        ));
    }

    public function testFlagThatIsAlreadyOnIsNotRevalidated(): void
    {
        $this->currentRow['disable_non_oidc_admin_login'] = 1;
        $this->unboundAdmins = ['a'];

        $event = $this->validateCommands([$this->command(UpdateCommand::class, ['disable_non_oidc_admin_login' => 1])], boundAccount: false);

        self::assertCount(0, $event->getExceptions()->getExceptions());
    }

    public function testFlagNeedsAVisibleSsoButton(): void
    {
        $this->otherVisibleProvider = false;

        $violation = $this->singleViolation($this->validateCommands([$this->command(UpdateCommand::class, [
            'disable_non_oidc_customer_login' => 1,
            'show_customer_link' => 0,
        ])], boundAccount: true));

        self::assertSame(Sw6OidcProviderWriteGuardSubscriber::CODE_NO_VISIBLE_LOGIN, $violation->getCode());
    }

    public function testDeactivatingTheLastAdminProviderUnderSsoOnlyModeIsRejected(): void
    {
        // boundAccount: fetchOne() also answers "another provider keeps the admin policy on".
        $this->adminLoginRemainsPossible = false;

        $violation = $this->singleViolation($this->validateCommands([$this->command(UpdateCommand::class, ['is_active' => 0])], boundAccount: true));

        self::assertSame(Sw6OidcProviderWriteGuardSubscriber::CODE_LOCKOUT_GUARD, $violation->getCode());
    }

    public function testDeletingTheLastAdminProviderUnderSsoOnlyModeIsRejected(): void
    {
        $this->adminLoginRemainsPossible = false;

        $violation = $this->singleViolation($this->validateCommands([$this->command(DeleteCommand::class, [])], boundAccount: true));

        self::assertSame(Sw6OidcProviderWriteGuardSubscriber::CODE_LOCKOUT_GUARD, $violation->getCode());
    }

    /**
     * @param list<WriteCommand> $commands
     */
    private function validateCommands(array $commands, bool $privateIps = false, bool $boundAccount = false): PreWriteValidationEvent
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($boundAccount ? '1' : false);
        $connection->method('fetchAssociative')->willReturn($this->currentRow);

        $lockoutGuard = $this->createStub(LockoutGuard::class);
        $lockoutGuard->method('unboundActiveAdminIds')->willReturn($this->unboundAdmins);
        $lockoutGuard->method('otherVisibleProviderExists')->willReturn($this->otherVisibleProvider);
        $lockoutGuard->method('adminLoginRemainsPossible')->willReturn($this->adminLoginRemainsPossible);

        $confirmations = $this->createStub(LockoutConfirmationStore::class);
        $confirmations->method('consume')->willReturn($this->confirmed);

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $validator = new SsrfUrlValidator(false, static fn (): array => [$privateIps ? '10.0.0.1' : '93.184.215.14']);
        $event = new PreWriteValidationEvent(WriteContext::createFromContext(Context::createDefaultContext()), $commands);

        $this->subscriber = new Sw6OidcProviderWriteGuardSubscriber(
            $validator,
            $connection,
            new Sw6OidcEncryptor(self::APP_SECRET),
            $lockoutGuard,
            $this->revoker,
            $requestStack,
            $confirmations,
        );
        $this->subscriber->validate($event);

        return $event;
    }

    /**
     * @param class-string<WriteCommand> $class
     * @param array<string, mixed> $payload
     */
    private function command(string $class, array $payload): WriteCommand
    {
        $command = $this->createMock($class);
        $command->method('getEntityName')->willReturn(Sw6OidcProviderDefinition::ENTITY_NAME);
        $command->method('getPayload')->willReturn($payload);
        $command->method('getPrimaryKey')->willReturn(['id' => Uuid::randomBytes()]);
        $command->method('getPath')->willReturn('/0');

        return $command;
    }

    private function singleViolation(PreWriteValidationEvent $event): \Symfony\Component\Validator\ConstraintViolationInterface
    {
        $exceptions = $event->getExceptions()->getExceptions();
        self::assertCount(1, $exceptions);
        $exception = array_values($exceptions)[0];
        self::assertInstanceOf(WriteConstraintViolationException::class, $exception);
        self::assertCount(1, $exception->getViolations());

        return $exception->getViolations()->get(0);
    }
}
