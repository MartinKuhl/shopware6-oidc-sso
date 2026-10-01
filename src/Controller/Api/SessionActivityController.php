<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Controller\Api;

use MartinKuhl\Sw6Oidc\Core\Content\SessionActivity\Sw6OidcSessionActivityDefinition;
use MartinKuhl\Sw6Oidc\Core\Content\SessionActivity\Sw6OidcSessionActivityEntity;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSession;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionActivityRecorder;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionDestructionService;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Force logout" for the session activity module (sw6oidc-sessions).
 *
 * A customer OIDC session still in the registry is ended exactly; everything
 * else — Passkey logins (not in the registry), and admins (whose sessions can
 * only be ended together) — ends **all** sessions of that account, and every
 * open activity row of the account is closed as `forced`.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class SessionActivityController extends AbstractController
{
    public function __construct(
        private readonly Sw6OidcSessionActivityRecorder $activityRecorder,
        private readonly Sw6OidcSessionRegistry $sessionRegistry,
        private readonly Sw6OidcSessionDestructionService $destructionService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/api/_action/sw6oidc/session-activity/{activityId}/force-logout',
        name: 'api.action.sw6oidc.session-activity.force-logout',
        defaults: ['_acl' => ['sw6oidc_session_activity:force_logout']],
        methods: ['POST'],
    )]
    public function forceLogout(string $activityId): JsonResponse
    {
        $activity = $this->activityRecorder->get($activityId);

        if (!$activity instanceof Sw6OidcSessionActivityEntity) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        if ($activity->getLoggedOutAt() instanceof \DateTimeInterface) {
            return new JsonResponse(['alreadyLoggedOut' => true, 'endedAllSessions' => false]);
        }

        $userType = $activity->getUserType();
        $userId = $activity->getUserId();
        $registrySession = $activity->getRegistrySessionId() !== null ? $this->sessionRegistry->get($activity->getRegistrySessionId()) : null;

        if ($registrySession instanceof Sw6OidcSession && $userType === Sw6OidcSession::USER_TYPE_CUSTOMER) {
            $this->sessionRegistry->remove($registrySession);
            $this->destructionService->destroy($registrySession);
            $this->activityRecorder->recordLogout($userType, $userId, Sw6OidcSessionActivityDefinition::LOGOUT_REASON_FORCED, null, $registrySession->id);
            $endedAll = false;
        } else {
            $this->destructionService->destroyAllForUser($userType, $userId);

            $this->sessionRegistry->removeAllForUser($userType, $userId);

            $this->activityRecorder->recordLogoutOfAllSessions($userType, $userId, Sw6OidcSessionActivityDefinition::LOGOUT_REASON_FORCED);
            $endedAll = true;
        }

        $this->logger->info('sw6oidc: session force-logged-out by an administrator.', [
            'activityId' => $activityId,
            'userType' => $userType,
            'userId' => $userId,
            'endedAllSessions' => $endedAll,
        ]);

        return new JsonResponse(['alreadyLoggedOut' => false, 'endedAllSessions' => $endedAll]);
    }
}
