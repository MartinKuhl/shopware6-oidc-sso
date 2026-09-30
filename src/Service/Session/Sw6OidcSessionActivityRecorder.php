<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Session;

use MartinKuhl\Sw6Oidc\Core\Content\SessionActivity\Sw6OidcSessionActivityDefinition;
use MartinKuhl\Sw6Oidc\Core\Content\SessionActivity\Sw6OidcSessionActivityEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * Writes the sw6oidc_session_activity log: one row per login, closed on
 * logout. Pure bookkeeping — every method swallows and logs its own errors,
 * so a failing audit write can never fail a login or logout.
 *
 * A logout is matched to its login row by the registry session id (OIDC
 * logins; the only thing Back-/Front-Channel Logout knows), else by the hash
 * of the local session key (context token / first access-token jti).
 */
class Sw6OidcSessionActivityRecorder
{
    public function __construct(
        private readonly EntityRepository $activityRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function recordLogin(
        string $userType,
        string $userId,
        string $loginMethod,
        string $sessionKey,
        ?Request $request,
        ?string $providerId = null,
        ?Sw6OidcSession $registrySession = null,
    ): void {
        try {
            $this->activityRepository->create([[
                'id' => Uuid::randomHex(),
                'providerId' => $providerId,
                'userType' => $userType,
                'userId' => $userId,
                'sub' => $registrySession?->sub,
                'sid' => $registrySession?->sid,
                'loginMethod' => $loginMethod,
                'sessionKeyHash' => $this->hash($sessionKey),
                'registrySessionId' => $registrySession?->id,
                'ipAddress' => $request?->getClientIp(),
                'userAgent' => $this->userAgent($request),
                'loggedInAt' => new \DateTimeImmutable(),
            ]], Context::createDefaultContext());
        } catch (\Throwable $exception) {
            $this->logger->warning('sw6oidc: could not record session login.', ['exception' => $exception->getMessage()]);
        }
    }

    /**
     * Closes the open login row of one session. With $fallbackToNewest, the
     * account's newest open row is closed when nothing matches (admin
     * logouts after a token refresh, when the jti is no longer the first one).
     */
    public function recordLogout(
        string $userType,
        string $userId,
        string $reason,
        ?string $sessionKey = null,
        ?string $registrySessionId = null,
        bool $fallbackToNewest = false,
    ): void {
        try {
            $open = $this->openActivities($userType, $userId);
            $keyHash = $sessionKey !== null ? $this->hash($sessionKey) : null;
            $match = null;

            foreach ($open as $activity) {
                $byRegistry = $registrySessionId !== null && $activity->getRegistrySessionId() === $registrySessionId;
                $byKey = $keyHash !== null && $activity->getSessionKeyHash() === $keyHash;

                if ($byRegistry || $byKey) {
                    $match = $activity;

                    break;
                }
            }

            if ($match === null && $fallbackToNewest) {
                $match = $open[0] ?? null;
            }

            if ($match !== null) {
                $this->close([$match], $reason);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('sw6oidc: could not record session logout.', ['exception' => $exception->getMessage()]);
        }
    }

    /**
     * Closes every open login row of the account (all of an admin's sessions
     * end together, see Sw6OidcSessionDestructionService).
     */
    public function recordLogoutOfAllSessions(string $userType, string $userId, string $reason): void
    {
        try {
            $this->close($this->openActivities($userType, $userId), $reason);
        } catch (\Throwable $exception) {
            $this->logger->warning('sw6oidc: could not record session logout.', ['exception' => $exception->getMessage()]);
        }
    }

    public function get(string $activityId): ?Sw6OidcSessionActivityEntity
    {
        $activity = $this->activityRepository->search(new Criteria([$activityId]), Context::createDefaultContext())->first();

        return $activity instanceof Sw6OidcSessionActivityEntity ? $activity : null;
    }

    /**
     * @return list<Sw6OidcSessionActivityEntity> newest first
     */
    private function openActivities(string $userType, string $userId): array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('userType', $userType))
            ->addFilter(new EqualsFilter('userId', $userId))
            ->addFilter(new EqualsFilter('loggedOutAt', null))
            ->addSorting(new FieldSorting('loggedInAt', FieldSorting::DESCENDING))
            ->setLimit(200);

        $activities = [];

        foreach ($this->activityRepository->search($criteria, Context::createDefaultContext())->getEntities() as $activity) {
            if ($activity instanceof Sw6OidcSessionActivityEntity) {
                $activities[] = $activity;
            }
        }

        return $activities;
    }

    /**
     * @param list<Sw6OidcSessionActivityEntity> $activities
     */
    private function close(array $activities, string $reason): void
    {
        if ($activities === []) {
            return;
        }

        $now = new \DateTimeImmutable();

        $this->activityRepository->update(array_map(
            static fn (Sw6OidcSessionActivityEntity $activity): array => ['id' => $activity->getId(), 'loggedOutAt' => $now, 'logoutReason' => $reason],
            $activities,
        ), Context::createDefaultContext());
    }

    private function userAgent(?Request $request): ?string
    {
        $userAgent = $request?->headers->get('User-Agent');

        return \is_string($userAgent) && $userAgent !== '' ? mb_substr($userAgent, 0, 512) : null;
    }

    private function hash(string $sessionKey): string
    {
        return hash('sha256', $sessionKey);
    }
}
