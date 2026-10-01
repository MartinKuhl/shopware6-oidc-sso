<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Controller\Api;

use MartinKuhl\Sw6Oidc\Service\Security\LockoutConfirmationStore;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Records the admin's explicit confirmation that switching Administration
 * password login off may lock out admins without an SSO binding (see
 * Sw6OidcProviderWriteGuardSubscriber, SW6OIDC_LOCKOUT_UNBOUND_USERS).
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ProviderLockoutConfirmationController extends AbstractController
{
    public function __construct(
        private readonly LockoutConfirmationStore $confirmationStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/api/_action/sw6oidc/provider/{providerId}/confirm-lockout',
        name: 'api.action.sw6oidc.provider.confirm-lockout',
        defaults: ['_acl' => ['sw6oidc_provider:update']],
        methods: ['POST'],
    )]
    public function confirm(string $providerId, Context $context): JsonResponse
    {
        if (!Uuid::isValid($providerId)) {
            return new JsonResponse(['error' => 'invalid_provider'], Response::HTTP_BAD_REQUEST);
        }

        $this->confirmationStore->confirm($providerId);

        $source = $context->getSource();
        $this->logger->warning('sw6oidc: admin confirmed disabling Administration password login despite unbound admins.', [
            'providerId' => $providerId,
            'adminUserId' => $source instanceof AdminApiSource ? $source->getUserId() : null,
        ]);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
