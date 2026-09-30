<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Controller\Api;

use League\OAuth2\Server\AuthorizationServer;
use MartinKuhl\Sw6Oidc\Controller\Api\OidcAdminAuthController;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\AdminAuth\AdminLoginErrorTicketStore;
use MartinKuhl\Sw6Oidc\Service\AdminAuth\AdminLoginNonceService;
use MartinKuhl\Sw6Oidc\Service\Http\OidcHttpClient;
use MartinKuhl\Sw6Oidc\Service\Oidc\AuthorizationRequestBuilder;
use MartinKuhl\Sw6Oidc\Service\Oidc\LogoutContextStore;
use MartinKuhl\Sw6Oidc\Service\Oidc\OidcCallbackProcessor;
use MartinKuhl\Sw6Oidc\Service\Oidc\RpInitiatedLogoutService;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyConfig;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyCredentialRepository;
use MartinKuhl\Sw6Oidc\Service\Provider\Exception\ProviderNotFoundException;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use MartinKuhl\Sw6Oidc\Service\Provisioning\AdminProvisioningService;
use MartinKuhl\Sw6Oidc\Service\Provisioning\UserProviderBindingService;
use MartinKuhl\Sw6Oidc\Service\Security\PasswordLoginPolicy;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\InMemoryAtomicCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

#[CoversClass(OidcAdminAuthController::class)]
final class OidcAdminAuthControllerLogoutTest extends TestCase
{
    private const USER_ID = '0190a1b2c3d4e5f60718293a4b5c6d7e';

    private LogoutContextStore $store;

    protected function setUp(): void
    {
        $this->store = new LogoutContextStore(new InMemoryAtomicCache());
    }

    public function testReturnsIdpLogoutUrlForOidcAdmin(): void
    {
        $this->store->rememberForAdmin(self::USER_ID, 'provider-1', 'id-token');

        $body = $this->logout($this->provider());

        self::assertSame(['logoutUrl' => 'https://auth.example/logout?rd=' . rawurlencode('https://shop.example/admin/')], $body);
        self::assertNull($this->store->consumeForAdmin(self::USER_ID), 'logout context is single-use');
    }

    public function testReturnsNullWithoutLogoutContext(): void
    {
        self::assertSame(['logoutUrl' => null], $this->logout($this->provider()));
    }

    public function testReturnsNullForInactiveProvider(): void
    {
        $this->store->rememberForAdmin(self::USER_ID, 'provider-1', 'id-token');

        self::assertSame(['logoutUrl' => null], $this->logout(null));
    }

    /**
     * @return array<string, mixed>
     */
    private function logout(?Sw6OidcProviderEntity $provider): array
    {
        $resolver = $this->createMock(ProviderResolver::class);
        if ($provider === null) {
            $resolver->method('getActiveById')->willThrowException(new ProviderNotFoundException('gone'));
        } else {
            $resolver->method('getActiveById')->willReturn($provider);
        }

        $controller = new OidcAdminAuthController(
            $resolver,
            $this->createMock(AuthorizationRequestBuilder::class),
            $this->createMock(OidcCallbackProcessor::class),
            $this->createMock(AdminProvisioningService::class),
            $this->createMock(AdminLoginNonceService::class),
            $this->createMock(AuthorizationServer::class),
            $this->createMock(PsrHttpFactory::class),
            'https://shop.example/admin',
            new NullLogger(),
            $this->createMock(PasskeyConfig::class),
            $this->createMock(PasskeyCredentialRepository::class),
            $this->createMock(UserProviderBindingService::class),
            $this->createMock(PasswordLoginPolicy::class),
            $this->store,
            new RpInitiatedLogoutService($this->createMock(OidcHttpClient::class), new NullLogger()),
            new AdminLoginErrorTicketStore(new InMemoryAtomicCache()),
        );

        $response = $controller->logout(new Context(new AdminApiSource(self::USER_ID)));

        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function provider(): Sw6OidcProviderEntity
    {
        $provider = new Sw6OidcProviderEntity();
        $provider->setId('provider-1');
        $provider->setEndSessionEndpoint('https://auth.example/logout');

        return $provider;
    }
}
