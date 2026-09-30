<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Storefront\Service;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Http\OidcHttpClient;
use MartinKuhl\Sw6Oidc\Service\Oidc\LogoutContextStore;
use MartinKuhl\Sw6Oidc\Service\Oidc\RpInitiatedLogoutService;
use MartinKuhl\Sw6Oidc\Service\Provider\Exception\ProviderNotFoundException;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use MartinKuhl\Sw6Oidc\Storefront\Service\OidcLogoutRoute;
use MartinKuhl\Sw6Oidc\Storefront\Service\PendingLogoutRedirect;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\InMemoryAtomicCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractLogoutRoute;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\ContextTokenResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(OidcLogoutRoute::class)]
final class OidcLogoutRouteTest extends TestCase
{
    private const PRE_LOGOUT_TOKEN = 'pre-logout-token';
    private const LOGIN_URL = 'https://shop.example/account/login';

    private LogoutContextStore $store;

    private PendingLogoutRedirect $pending;

    protected function setUp(): void
    {
        $this->store = new LogoutContextStore(new InMemoryAtomicCache());
        $this->pending = new PendingLogoutRedirect();
    }

    public function testResolvesLogoutUrlFromPreLogoutToken(): void
    {
        $this->store->remember(self::PRE_LOGOUT_TOKEN, 'provider-1', 'id-token');

        $response = $this->route($this->provider('https://auth.example/logout'))->logout($this->context(), new RequestDataBag());

        self::assertSame('fresh-token', $response->getToken());
        self::assertSame('https://auth.example/logout?rd=' . rawurlencode(self::LOGIN_URL), $this->pending->pull());
        self::assertNull($this->store->consume(self::PRE_LOGOUT_TOKEN), 'logout context is single-use');
    }

    public function testNoLogoutContextLeavesNoPendingRedirect(): void
    {
        $this->route($this->provider('https://auth.example/logout'))->logout($this->context(), new RequestDataBag());

        self::assertNull($this->pending->pull());
    }

    public function testInactiveProviderLeavesNoPendingRedirect(): void
    {
        $this->store->remember(self::PRE_LOGOUT_TOKEN, 'provider-1', 'id-token');

        $this->route(null)->logout($this->context(), new RequestDataBag());

        self::assertNull($this->pending->pull());
    }

    public function testFailedInnerLogoutKeepsLogoutContext(): void
    {
        $this->store->remember(self::PRE_LOGOUT_TOKEN, 'provider-1', 'id-token');

        $decorated = $this->createMock(AbstractLogoutRoute::class);
        $decorated->method('logout')->willThrowException(new \RuntimeException('boom'));

        try {
            $this->route($this->provider('https://auth.example/logout'), $decorated)->logout($this->context(), new RequestDataBag());
            self::fail('Expected the inner logout exception to propagate.');
        } catch (\RuntimeException) {
        }

        self::assertNotNull($this->store->consume(self::PRE_LOGOUT_TOKEN));
        self::assertNull($this->pending->pull());
    }

    private function route(?Sw6OidcProviderEntity $provider, ?AbstractLogoutRoute $decorated = null): OidcLogoutRoute
    {
        if ($decorated === null) {
            $decorated = $this->createMock(AbstractLogoutRoute::class);
            $decorated->method('logout')->willReturn(new ContextTokenResponse('fresh-token'));
        }

        $resolver = $this->createMock(ProviderResolver::class);
        if ($provider === null) {
            $resolver->method('getActiveById')->willThrowException(new ProviderNotFoundException('gone'));
        } else {
            $resolver->method('getActiveById')->willReturn($provider);
        }

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->with('frontend.account.login.page')->willReturn(self::LOGIN_URL);

        return new OidcLogoutRoute(
            $decorated,
            $this->store,
            $resolver,
            new RpInitiatedLogoutService($this->createMock(OidcHttpClient::class), new NullLogger()),
            $this->pending,
            $urlGenerator,
            new NullLogger(),
        );
    }

    private function provider(string $endSessionEndpoint): Sw6OidcProviderEntity
    {
        $provider = new Sw6OidcProviderEntity();
        $provider->setId('provider-1');
        $provider->setEndSessionEndpoint($endSessionEndpoint);

        return $provider;
    }

    private function context(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getToken')->willReturn(self::PRE_LOGOUT_TOKEN);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
