<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Storefront\EventSubscriber;

use MartinKuhl\Sw6Oidc\Service\Oidc\LogoutContextStore;
use MartinKuhl\Sw6Oidc\Service\Oidc\RpInitiatedLogoutService;
use MartinKuhl\Sw6Oidc\Service\Provider\Exception\ProviderNotFoundException;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\Event\CustomerLogoutEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * RP-Initiated Logout for Storefront customers: redirects to the IdP's
 * end_session_endpoint after a customer logs out — mirrors the Magento
 * module's Observer/OAuthLogoutObserver.php.
 *
 * Two-step because a domain event listener (CustomerLogoutEvent) cannot
 * replace the controller's HTTP response: onCustomerLogout() runs first
 * (while the pre-logout context/token are still available) and only
 * *resolves* the IdP logout URL into a request-scoped property; onKernelResponse()
 * runs afterwards, for the same request, and rewrites the logout controller's
 * redirect target to that URL — the same "capture before destroy, redirect
 * after" shape as the Magento observer, expressed with Symfony's two separate
 * hook points instead of Magento's single postdispatch hook.
 *
 * NOTE: relies on CustomerLogoutEvent still carrying the pre-invalidation
 * context token when dispatched, and on Shopware's logout route producing a
 * RedirectResponse this listener can safely overwrite — verify both against
 * a live Shopware 6.7 checkout before shipping (see plan's Verification
 * section).
 */
class CustomerLogoutSubscriber implements EventSubscriberInterface
{
    private ?string $pendingLogoutUrl = null;

    public function __construct(
        private readonly LogoutContextStore $logoutContextStore,
        private readonly ProviderResolver $providerResolver,
        private readonly RpInitiatedLogoutService $rpInitiatedLogoutService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CustomerLogoutEvent::class => 'onCustomerLogout',
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onCustomerLogout(CustomerLogoutEvent $event): void
    {
        $logoutContext = $this->logoutContextStore->consume($event->getSalesChannelContext()->getToken());

        if (!$logoutContext instanceof \MartinKuhl\Sw6Oidc\Service\Oidc\LogoutContext) {
            return;
        }

        try {
            $provider = $this->providerResolver->getActiveById($logoutContext->providerId, $event->getSalesChannelContext()->getContext());
        } catch (ProviderNotFoundException $exception) {
            $this->logger->warning('sw6oidc: RP-initiated logout skipped, provider no longer active.', [
                'providerId' => $logoutContext->providerId,
                'exception' => $exception->getMessage(),
            ]);

            return;
        }

        $this->rpInitiatedLogoutService->revokeToken($provider, null);

        $this->pendingLogoutUrl = $this->rpInitiatedLogoutService->buildLogoutUrl(
            $provider,
            $logoutContext->idToken,
            $this->postLogoutRedirectUri(),
            'customer:',
        );
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if ($this->pendingLogoutUrl === null || !$event->isMainRequest()) {
            return;
        }

        $logoutUrl = $this->pendingLogoutUrl;
        $this->pendingLogoutUrl = null;

        $event->setResponse(new RedirectResponse($logoutUrl));
    }

    private function postLogoutRedirectUri(): string
    {
        return rtrim((string) getenv('APP_URL'), '/') . '/account/login';
    }
}
