<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Controller\Api;

use MartinKuhl\Sw6Oidc\Service\Security\LockoutConfirmationStore;
use MartinKuhl\Sw6Oidc\Service\Security\UserVerifiedScope;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Records the admin's explicit confirmation that switching Administration
 * password login off may lock out admins without an SSO binding (see
 * Sw6OidcProviderWriteGuardSubscriber, SW6OIDC_LOCKOUT_UNBOUND_USERS).
 *
 * The confirmation can lock out superadmins and end their sessions, so it
 * needs a freshly re-authenticated (`user-verified`) token, and it only
 * counts for the confirming admin's own next save (R3-M22).
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
    public function confirm(string $providerId, Request $request, Context $context): JsonResponse
    {
        if (!Uuid::isValid($providerId)) {
            return new JsonResponse(['error' => 'invalid_provider'], Response::HTTP_BAD_REQUEST);
        }

        $source = $context->getSource();
        $userId = $source instanceof AdminApiSource ? $source->getUserId() : null;

        if ($userId === null) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        if (!UserVerifiedScope::isPresent($request)) {
            return new JsonResponse(['error' => 'user_verification_required'], Response::HTTP_FORBIDDEN);
        }

        $this->confirmationStore->confirm($providerId, $userId);

        $this->logger->warning('sw6oidc: admin confirmed disabling Administration password login despite unbound admins.', [
            'providerId' => $providerId,
            'adminUserId' => $userId,
        ]);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
