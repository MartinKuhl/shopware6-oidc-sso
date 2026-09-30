<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Controller\Api;

use MartinKuhl\Sw6Oidc\Controller\Api\OidcAdminAuthController;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Oidc\LogoutContextStore;
use MartinKuhl\Sw6Oidc\Service\Provider\Exception\ProviderNotFoundException;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\BuildsOidcAdminAuthController;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\InMemoryAtomicCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionRegistry;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(OidcAdminAuthController::class)]
final class OidcAdminAuthControllerLogoutTest extends TestCase
{
    use BuildsOidcAdminAuthController;

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

    public function testRegistrySessionOfTheCurrentTokenWinsOverTheFallback(): void
    {
        $registry = new Sw6OidcSessionRegistry(new ArrayAdapter(), new NullLogger());
        $registry->register('provider-1', 'sub', 'sid-old', 'admin', self::USER_ID, 'jti-old', null, 'old-id-token');
        $current = $registry->register('provider-1', 'sub', 'sid-cur', 'admin', self::USER_ID, 'jti-current', null, 'current-id-token');
        $registry->register('provider-1', 'sub', 'sid-new', 'admin', self::USER_ID, 'jti-new', null, 'new-id-token');
        $this->store->rememberForAdmin(self::USER_ID, 'provider-1', 'fallback-id-token');

        $request = new Request();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_OAUTH_ACCESS_TOKEN_ID, 'jti-current');
        $provider = $this->provider();
        $provider->setEndSessionEndpoint('https://idp.example/oidc/end-session');

        $body = $this->logout($provider, $registry, $request);

        parse_str((string) parse_url((string) $body['logoutUrl'], PHP_URL_QUERY), $query);
        self::assertSame('current-id-token', $query['id_token_hint']);
        self::assertNull($registry->get($current->id), 'the ended session leaves the registry');
        self::assertCount(2, $registry->resolveByUser('admin', self::USER_ID));
        self::assertNull($this->store->consumeForAdmin(self::USER_ID), 'fallback store is consumed too');
    }

    public function testNewestRegistrySessionIsUsedWhenTheTokenWasRefreshed(): void
    {
        $registry = new Sw6OidcSessionRegistry(new ArrayAdapter(), new NullLogger());
        $registry->register('provider-1', 'sub', 'sid-old', 'admin', self::USER_ID, 'jti-old', null, 'old-id-token');
        $registry->register('provider-1', 'sub', 'sid-new', 'admin', self::USER_ID, 'jti-new', null, 'new-id-token');
        $provider = $this->provider();
        $provider->setEndSessionEndpoint('https://idp.example/oidc/end-session');

        $body = $this->logout($provider, $registry, new Request());

        parse_str((string) parse_url((string) $body['logoutUrl'], PHP_URL_QUERY), $query);
        self::assertSame('new-id-token', $query['id_token_hint']);
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
    private function logout(?Sw6OidcProviderEntity $provider, ?Sw6OidcSessionRegistry $registry = null, ?Request $request = null): array
    {
        $resolver = $this->createMock(ProviderResolver::class);
        if ($provider === null) {
            $resolver->method('getActiveById')->willThrowException(new ProviderNotFoundException('gone'));
        } else {
            $resolver->method('getActiveById')->willReturn($provider);
        }

        $controller = $this->buildAdminAuthController(array_filter([
            'providerResolver' => $resolver,
            'logoutContextStore' => $this->store,
            'sessionRegistry' => $registry,
        ]));

        $response = $controller->logout($request ?? new Request(), new Context(new AdminApiSource(self::USER_ID)));

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
