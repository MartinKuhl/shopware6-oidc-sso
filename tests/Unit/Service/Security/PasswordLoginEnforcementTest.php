<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Security;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use MartinKuhl\Sw6Oidc\Service\Security\Exception\PasswordLoginDisabledException;
use MartinKuhl\Sw6Oidc\Service\Security\PasswordLoginPolicy;
use MartinKuhl\Sw6Oidc\Storefront\Service\PasswordLoginGuardLoginRoute;
use MartinKuhl\Sw6Oidc\Subscriber\AdminPasswordLoginGuardSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractLoginRoute;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\ContextTokenResponse;
use Shopware\Core\Test\Generator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(PasswordLoginPolicy::class)]
#[CoversClass(PasswordLoginGuardLoginRoute::class)]
#[CoversClass(AdminPasswordLoginGuardSubscriber::class)]
#[CoversClass(PasswordLoginDisabledException::class)]
final class PasswordLoginEnforcementTest extends TestCase
{
    public function testPolicyIsOnWhenAnyActiveProviderSetsTheFlag(): void
    {
        $policy = new PasswordLoginPolicy($this->resolverWith([$this->provider(false, false), $this->provider(true, false)]));

        self::assertTrue($policy->isPasswordLoginDisabled('admin', Generator::generateSalesChannelContext()->getContext()));
        self::assertFalse($policy->isPasswordLoginDisabled('customer', Generator::generateSalesChannelContext()->getContext()));
    }

    public function testBreakGlassOverridesThePolicy(): void
    {
        $policy = new PasswordLoginPolicy($this->resolverWith([$this->provider(true, true)]), true);

        self::assertFalse($policy->isPasswordLoginDisabled('admin', Generator::generateSalesChannelContext()->getContext()));
    }

    public function testStorefrontPasswordLoginIsRejectedWhenDisabled(): void
    {
        $inner = $this->createMock(AbstractLoginRoute::class);
        $inner->expects(self::never())->method('login');
        $route = new PasswordLoginGuardLoginRoute($inner, new PasswordLoginPolicy($this->resolverWith([$this->provider(false, true)])));

        try {
            $route->login(new RequestDataBag(), Generator::generateSalesChannelContext());
            self::fail('Expected PasswordLoginDisabledException');
        } catch (PasswordLoginDisabledException $exception) {
            self::assertSame(403, $exception->getStatusCode());
            self::assertSame(PasswordLoginDisabledException::ERROR_CODE, $exception->getErrorCode());
            self::assertStringContainsString('single sign-on', $exception->getMessage());
            self::assertSame('sw6oidc.login.passwordLoginDisabled', $exception->getSnippetKey());
        }
    }

    public function testStorefrontPasswordLoginPassesThroughWhenEnabled(): void
    {
        $inner = $this->createMock(AbstractLoginRoute::class);
        $inner->expects(self::once())->method('login')->willReturn(new ContextTokenResponse('token'));
        $route = new PasswordLoginGuardLoginRoute($inner, new PasswordLoginPolicy($this->resolverWith([$this->provider(true, false)])));

        self::assertSame('token', $route->login(new RequestDataBag(), Generator::generateSalesChannelContext())->getToken());
        self::assertSame($inner, $route->getDecorated());
    }

    public function testAdminPasswordGrantIsRejectedWhenDisabled(): void
    {
        $event = $this->tokenRequest('password');
        (new AdminPasswordLoginGuardSubscriber(new PasswordLoginPolicy($this->resolverWith([$this->provider(true, false)]))))->onRequest($event);

        self::assertNotNull($event->getResponse());
        self::assertSame(403, $event->getResponse()->getStatusCode());
        self::assertStringContainsString(AdminPasswordLoginGuardSubscriber::ERROR_CODE, (string) $event->getResponse()->getContent());
    }

    public function testAdminRefreshGrantIsNotAffected(): void
    {
        $event = $this->tokenRequest('refresh_token');
        (new AdminPasswordLoginGuardSubscriber(new PasswordLoginPolicy($this->resolverWith([$this->provider(true, false)]))))->onRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testOtherRoutesAreNotAffected(): void
    {
        $event = $this->tokenRequest('password', 'api.info.config');
        (new AdminPasswordLoginGuardSubscriber(new PasswordLoginPolicy($this->resolverWith([$this->provider(true, false)]))))->onRequest($event);

        self::assertNull($event->getResponse());
    }

    private function tokenRequest(string $grantType, string $route = 'api.oauth.token'): RequestEvent
    {
        $request = new Request([], ['grant_type' => $grantType]);
        $request->attributes->set('_route', $route);

        return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    /**
     * @param list<Sw6OidcProviderEntity> $providers
     */
    private function resolverWith(array $providers): ProviderResolver
    {
        $resolver = $this->createMock(ProviderResolver::class);
        $resolver->method('getActiveProviders')->willReturn($providers);

        return $resolver;
    }

    private function provider(bool $disableAdmin, bool $disableCustomer): Sw6OidcProviderEntity
    {
        $provider = new Sw6OidcProviderEntity();
        $provider->setDisableNonOidcAdminLogin($disableAdmin);
        $provider->setDisableNonOidcCustomerLogin($disableCustomer);

        return $provider;
    }
}
