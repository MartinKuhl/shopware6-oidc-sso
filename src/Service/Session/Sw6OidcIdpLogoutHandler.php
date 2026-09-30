<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Session;

use MartinKuhl\Sw6Oidc\Service\Oidc\LogoutContextStore;
use Psr\Log\LoggerInterface;

/**
 * The shared "the IdP says this session is over" step behind Back- and
 * Front-Channel Logout: look the sessions up in the registry, remove them,
 * destroy the local sessions, and drop their now-pointless RP-initiated
 * logout contexts. Protocol validation (token signature, iss/sid query
 * parameters) stays in the controllers.
 */
class Sw6OidcIdpLogoutHandler
{
    public function __construct(
        private readonly Sw6OidcSessionRegistry $registry,
        private readonly Sw6OidcSessionDestructionService $destructionService,
        private readonly LogoutContextStore $logoutContextStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Ends the IdP session `sid` (when given), else every session of `sub`.
     * Idempotent: nothing registered (already logged out, unknown) is not an
     * error.
     *
     * @return list<Sw6OidcSession> the sessions that were ended
     */
    public function logout(string $providerId, ?string $sub, ?string $sid): array
    {
        if ($sid !== null && $sid !== '') {
            $sessions = $this->registry->resolveBySid($providerId, $sid);

            // When both are present they must agree (OIDC Back-Channel Logout §2.4).
            if ($sub !== null && $sub !== '') {
                $sessions = array_values(array_filter($sessions, static fn (Sw6OidcSession $session): bool => $session->sub === $sub));
            }
        } elseif ($sub !== null && $sub !== '') {
            $sessions = $this->registry->resolve($providerId, $sub);
        } else {
            return [];
        }

        $destroyedAdmins = [];

        foreach ($sessions as $session) {
            $this->registry->remove($session);

            if ($session->userType === Sw6OidcSession::USER_TYPE_ADMIN) {
                $this->logoutContextStore->consumeForAdmin($session->userId);

                // Admin destruction is per user anyway; once is enough.
                if (isset($destroyedAdmins[$session->userId])) {
                    continue;
                }

                $destroyedAdmins[$session->userId] = true;
            } else {
                $this->logoutContextStore->consume($session->sessionKey);
            }

            $this->destructionService->destroy($session);
        }

        $this->logger->info('sw6oidc: IdP-initiated logout processed.', [
            'providerId' => $providerId,
            'bySid' => $sid !== null && $sid !== '',
            'sessionsEnded' => \count($sessions),
        ]);

        return $sessions;
    }
}
