<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Storefront\Controller;

use MartinKuhl\Sw6Oidc\Service\Oidc\AuthorizationRequestBuilder;
use MartinKuhl\Sw6Oidc\Service\Provider\Exception\ProviderNotFoundException;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Step 1 of the Storefront customer OIDC flow: redirects to the IdP. Mirrors
 * the Magento module's Controller/Actions/SendAuthorizationRequest.php.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
#[Package('storefront')]
class SendAuthorizationRequestController extends StorefrontController
{
    public function __construct(
        private readonly ProviderResolver $providerResolver,
        private readonly AuthorizationRequestBuilder $requestBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/sw6oidc/login',
        name: 'frontend.sw6oidc.login',
        defaults: ['XmlHttpRequest' => true, '_loginRequired' => false],
        methods: ['GET'],
    )]
    public function login(Request $request, SalesChannelContext $context): RedirectResponse
    {
        $providerId = $request->query->get('providerId');
        $relayState = (string) $request->query->get('redirectTo', $this->generateUrl('frontend.account.home.page'));

        try {
            $provider = $providerId !== null
                ? $this->providerResolver->getActiveById((string) $providerId, $context->getContext())
                : $this->providerResolver->resolveDefault('customer', $context->getContext());
        } catch (ProviderNotFoundException $exception) {
            $this->logger->warning('sw6oidc: SSO login requested but no active provider is configured.', [
                'exception' => $exception->getMessage(),
            ]);

            $this->addFlash(self::DANGER, $this->trans('sw6oidc.login.providerUnavailable'));

            return new RedirectResponse($this->generateUrl('frontend.account.login.page'));
        }

        $redirectUri = $this->generateUrl('frontend.sw6oidc.callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $authorizeUrl = $this->requestBuilder->build($provider, 'customer', $relayState, $redirectUri);

        return new RedirectResponse($authorizeUrl);
    }
}
