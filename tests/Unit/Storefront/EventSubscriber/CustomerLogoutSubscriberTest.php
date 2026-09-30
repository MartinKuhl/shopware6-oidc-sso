<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Storefront\EventSubscriber;

use MartinKuhl\Sw6Oidc\Storefront\EventSubscriber\CustomerLogoutSubscriber;
use MartinKuhl\Sw6Oidc\Storefront\Service\PendingLogoutRedirect;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(CustomerLogoutSubscriber::class)]
final class CustomerLogoutSubscriberTest extends TestCase
{
    public function testStorefrontLogoutIsRedirectedToIdp(): void
    {
        $event = $this->dispatch('frontend.account.logout.page', 'https://auth.example/logout?rd=x');

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('https://auth.example/logout?rd=x', $response->getTargetUrl());
    }

    public function testStoreApiLogoutKeepsJsonResponse(): void
    {
        $event = $this->dispatch('store-api.account.logout', 'https://auth.example/logout?rd=x', new JsonResponse(['contextToken' => 'x']));

        self::assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    public function testNoPendingUrlKeepsResponse(): void
    {
        $original = new RedirectResponse('/account/login');
        $event = $this->dispatch('frontend.account.logout.page', null, $original);

        self::assertSame($original, $event->getResponse());
    }

    private function dispatch(string $route, ?string $pendingUrl, ?Response $response = null): ResponseEvent
    {
        $pending = new PendingLogoutRedirect();
        $pending->set($pendingUrl);

        $request = new Request();
        $request->attributes->set('_route', $route);

        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response ?? new RedirectResponse('/account/login'),
        );

        (new CustomerLogoutSubscriber($pending))->onKernelResponse($event);

        self::assertNull($pending->pull(), 'pending URL is consumed on the main request');

        return $event;
    }
}
