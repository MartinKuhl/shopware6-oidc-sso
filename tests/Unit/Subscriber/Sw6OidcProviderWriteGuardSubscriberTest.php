<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Subscriber;

use Doctrine\DBAL\Connection;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderDefinition;
use MartinKuhl\Sw6Oidc\Service\Security\SsrfUrlValidator;
use MartinKuhl\Sw6Oidc\Service\Security\Sw6OidcEncryptor;
use MartinKuhl\Sw6Oidc\Subscriber\Sw6OidcProviderWriteGuardSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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

    /**
     * @param list<WriteCommand> $commands
     */
    private function validateCommands(array $commands, bool $privateIps = false, bool $boundAccount = false): PreWriteValidationEvent
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($boundAccount ? '1' : false);

        $validator = new SsrfUrlValidator(false, static fn (): array => [$privateIps ? '10.0.0.1' : '93.184.215.14']);
        $event = new PreWriteValidationEvent(WriteContext::createFromContext(Context::createDefaultContext()), $commands);

        (new Sw6OidcProviderWriteGuardSubscriber($validator, $connection, new Sw6OidcEncryptor(self::APP_SECRET)))->validate($event);

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
