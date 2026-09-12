<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Storefront\Controller;

use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyConfig;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyCredentialRepository;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Self-service Passkey register/delete under My Account - lists and manages
 * only the currently logged-in customer's own credentials. Registration
 * itself is handled by the existing, unauthenticated-by-id
 * Storefront/Controller/PasskeyController::registrationOptions()/
 * registrationVerify() actions (already scoped to the logged-in customer via
 * its own injected CustomerEntity argument); this controller only adds the
 * page to list/delete them, mirroring the Administration "My passkeys"
 * profile tab's same ownership-checked delete pattern.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
#[Package('storefront')]
class AccountPasskeyController extends StorefrontController
{
    public function __construct(
        private readonly PasskeyCredentialRepository $passkeyCredentialRepository,
        private readonly PasskeyConfig $passkeyConfig,
    ) {
    }

    #[Route(
        path: '/account/passkey',
        name: 'frontend.account.passkey.page',
        defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true, 'XmlHttpRequest' => false],
        methods: ['GET'],
    )]
    public function index(SalesChannelContext $context, CustomerEntity $customer): Response
    {
        $credentials = $this->passkeyCredentialRepository->findAllForOwner('customer', $customer->getId(), $context->getContext());

        return $this->renderStorefront('@Sw6Oidc/storefront/page/account/passkey/index.html.twig', [
            'sw6oidcCredentials' => $credentials,
            'sw6oidcPasskeyEnabled' => $this->passkeyConfig->isEnabledForCustomer($context->getSalesChannelId()),
        ]);
    }

    #[Route(
        path: '/account/passkey/delete/{credentialId}',
        name: 'frontend.account.passkey.delete',
        defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true, 'XmlHttpRequest' => false],
        methods: ['POST'],
    )]
    public function delete(string $credentialId, SalesChannelContext $context, CustomerEntity $customer): RedirectResponse
    {
        $deleted = $this->passkeyCredentialRepository->deleteOwnedByUser($credentialId, 'customer', $customer->getId(), $context->getContext());

        $this->addFlash($deleted ? self::SUCCESS : self::DANGER, $deleted
            ? $this->trans('sw6oidc.passkey.deleteSuccess')
            : $this->trans('sw6oidc.passkey.deleteError'));

        return $this->redirectToRoute('frontend.account.passkey.page');
    }
}
