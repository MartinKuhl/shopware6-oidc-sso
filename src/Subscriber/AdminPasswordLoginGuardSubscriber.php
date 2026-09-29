<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Subscriber;

use MartinKuhl\Sw6Oidc\Service\Security\PasswordLoginPolicy;
use Shopware\Core\Framework\Context;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces disable_non_oidc_admin_login on core's /api/oauth/token: rejects
 * the `password` grant before the controller runs. Every other grant is left
 * alone — refresh_token (existing sessions keep renewing), client_credentials
 * (integrations), and the plugin's own OIDC/Passkey logins, which never hit
 * this endpoint with a password grant.
 */
class AdminPasswordLoginGuardSubscriber implements EventSubscriberInterface
{
    public const ERROR_CODE = 'SW6OIDC_PASSWORD_LOGIN_DISABLED';

    public function __construct(private readonly PasswordLoginPolicy $passwordLoginPolicy)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // After the router (32) so _route is set, before the controller.
        return [KernelEvents::REQUEST => ['onRequest', 8]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || $request->attributes->get('_route') !== 'api.oauth.token') {
            return;
        }

        if ($request->request->get('grant_type') !== 'password') {
            return;
        }

        if (!$this->passwordLoginPolicy->isPasswordLoginDisabled('admin', Context::createDefaultContext())) {
            return;
        }

        $message = 'Password login is disabled for the Administration. Please sign in with single sign-on or a passkey.';

        $event->setResponse(new JsonResponse([
            // RFC 6749 §5.2 shape for OAuth clients, plus Shopware's errors[] for the Admin UI.
            'error' => 'access_denied',
            'error_description' => $message,
            'errors' => [[
                'status' => (string) Response::HTTP_FORBIDDEN,
                'code' => self::ERROR_CODE,
                'title' => 'Forbidden',
                'detail' => $message,
            ]],
        ], Response::HTTP_FORBIDDEN));
    }
}
