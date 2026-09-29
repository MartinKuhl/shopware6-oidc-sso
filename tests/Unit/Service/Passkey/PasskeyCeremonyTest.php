<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Passkey;

use MartinKuhl\Sw6Oidc\Service\Passkey\Exception\PasskeyCeremonyException;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyAuthenticationService;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyCredentialRepository;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyRegistrationService;
use MartinKuhl\Sw6Oidc\Service\Passkey\WebauthnCeremonyFactory;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\InMemoryAtomicCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Full registration + login ceremonies against the real webauthn-lib 5.x
 * validators, driven by an in-process software authenticator.
 */
#[CoversClass(PasskeyRegistrationService::class)]
#[CoversClass(PasskeyAuthenticationService::class)]
#[CoversClass(WebauthnCeremonyFactory::class)]
final class PasskeyCeremonyTest extends TestCase
{
    private InMemoryPasskeyCredentialStore $store;

    private PasskeyCredentialRepository $credentials;

    private PasskeyRegistrationService $registration;

    private PasskeyAuthenticationService $authentication;

    private SoftwareAuthenticator $authenticator;

    private string $userId;

    protected function setUp(): void
    {
        $factory = new WebauthnCeremonyFactory();
        $cache = new InMemoryAtomicCache();
        $this->store = new InMemoryPasskeyCredentialStore();
        $this->credentials = new PasskeyCredentialRepository($this->store->wire($this->createMock(EntityRepository::class)), $factory, new NullLogger());
        $this->registration = new PasskeyRegistrationService($factory, $this->credentials, $cache);
        $this->authentication = new PasskeyAuthenticationService($factory, $this->credentials, $cache);
        $this->authenticator = new SoftwareAuthenticator();
        $this->userId = Uuid::randomHex();
    }

    public function testCreationOptionsAreBase64UrlWithoutNulls(): void
    {
        $options = $this->creationOptions();

        self::assertSame(32, \strlen(SoftwareAuthenticator::unb64($options['challenge'])));
        self::assertSame(hash('sha256', 'admin:' . $this->userId, true), SoftwareAuthenticator::unb64($options['user']['id']));
        self::assertSame('shop.example', $options['rp']['id']);
        self::assertStringNotContainsString('null', json_encode($options, \JSON_THROW_ON_ERROR));
    }

    public function testRegistrationPersistsCredential(): void
    {
        $this->register();

        self::assertCount(1, $this->store->rows);
        $row = array_values($this->store->rows)[0];
        self::assertSame('admin', $row['userType']);
        self::assertSame($this->userId, $row['userId']);
        self::assertSame(base64_encode($this->authenticator->credentialId), $row['credentialId']);
        self::assertSame('My key', $row['nickname']);
    }

    public function testRegisteredCredentialIsExcludedFromNextRegistration(): void
    {
        $this->register();

        $options = $this->creationOptions();

        self::assertCount(1, $options['excludeCredentials']);
        self::assertSame($this->authenticator->credentialId, SoftwareAuthenticator::unb64($options['excludeCredentials'][0]['id']));
    }

    public function testUsernamelessLoginResolvesOwnerAndPersistsCounter(): void
    {
        $this->register();

        $result = $this->authentication->buildRequestOptions([], 'shop.example');
        $options = json_decode($result['optionsJson'], true, 512, \JSON_THROW_ON_ERROR);
        $assertion = $this->authenticator->assert($options, hash('sha256', 'admin:' . $this->userId, true));

        $resolved = $this->authentication->verifyAssertion($result['nonce'], $assertion, 'shop.example');

        self::assertSame('admin', $resolved['userType']);
        self::assertSame($this->userId, $resolved['userId']);
        self::assertSame(1, array_values($this->store->rows)[0]['signCount']);
    }

    public function testEmailScopedLoginWithAllowCredentials(): void
    {
        $this->register();
        $handle = hash('sha256', 'admin:' . $this->userId, true);
        $allow = array_map(static fn ($record) => $record->getPublicKeyCredentialDescriptor(), $this->credentials->findAllForUserHandle($handle));

        $result = $this->authentication->buildRequestOptions($allow, 'shop.example');
        $options = json_decode($result['optionsJson'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $options['allowCredentials']);

        $resolved = $this->authentication->verifyAssertion($result['nonce'], $this->authenticator->assert($options, $handle), 'shop.example');

        self::assertSame($this->userId, $resolved['userId']);
    }

    public function testLoginNonceIsSingleUse(): void
    {
        $this->register();
        $result = $this->authentication->buildRequestOptions([], 'shop.example');
        $options = json_decode($result['optionsJson'], true, 512, \JSON_THROW_ON_ERROR);
        $handle = hash('sha256', 'admin:' . $this->userId, true);

        $this->authentication->verifyAssertion($result['nonce'], $this->authenticator->assert($options, $handle), 'shop.example');

        $this->expectException(PasskeyCeremonyException::class);
        $this->authentication->verifyAssertion($result['nonce'], $this->authenticator->assert($options, $handle), 'shop.example');
    }

    public function testUnregisteredCredentialIsRejected(): void
    {
        $result = $this->authentication->buildRequestOptions([], 'shop.example');
        $options = json_decode($result['optionsJson'], true, 512, \JSON_THROW_ON_ERROR);

        $this->expectException(PasskeyCeremonyException::class);
        $this->expectExceptionMessage('not registered');
        $this->authentication->verifyAssertion($result['nonce'], $this->authenticator->assert($options, random_bytes(32)), 'shop.example');
    }

    public function testWrongHostIsRejected(): void
    {
        $this->register();
        $result = $this->authentication->buildRequestOptions([], 'shop.example');
        $options = json_decode($result['optionsJson'], true, 512, \JSON_THROW_ON_ERROR);
        $evil = new SoftwareAuthenticator('https://evil.example');

        $this->expectException(PasskeyCeremonyException::class);
        $this->authentication->verifyAssertion($result['nonce'], $evil->assert($options, random_bytes(32)), 'shop.example');
    }

    public function testUnknownRegistrationNonceIsRejected(): void
    {
        $this->expectException(PasskeyCeremonyException::class);
        $this->registration->verifyAndPersist('nope', '{}', 'shop.example', null);
    }

    /**
     * @return array<string, mixed>
     */
    private function creationOptions(?string &$nonce = null): array
    {
        $result = $this->registration->buildCreationOptions('admin', $this->userId, 'admin', 'Admin', 'shop.example', 'Shop');
        $nonce = $result['nonce'];

        return json_decode($result['optionsJson'], true, 512, \JSON_THROW_ON_ERROR);
    }

    private function register(): void
    {
        $options = $this->creationOptions($nonce);
        $this->registration->verifyAndPersist((string) $nonce, $this->authenticator->register($options), 'shop.example', 'My key');
    }
}
