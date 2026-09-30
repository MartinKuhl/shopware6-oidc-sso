<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\AdminAuth;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mints a real Administration OAuth token response for a user the caller has
 * already verified (OIDC login nonce, passkey assertion, step-up), through
 * the plugin's AuthorizationServer and AdminOidcGrant. The one place that
 * builds the grant request, so the client, grant type and scope handling
 * can't drift between callers.
 */
class AdminTokenIssuer
{
    public function __construct(
        private readonly AuthorizationServer $adminAuthorizationServer,
        private readonly PsrHttpFactory $psrHttpFactory,
    ) {
    }

    /**
     * @param bool $stepUp a fresh re-authentication: short-lived `user-verified` token, no refresh token
     *
     * @throws OAuthServerException
     */
    public function issue(Request $request, string $userId, bool $stepUp = false): Response
    {
        $request->attributes->set(AdminOidcGrant::REQUEST_ATTRIBUTE_USER_ID, $userId);
        $request->attributes->set(AdminOidcGrant::REQUEST_ATTRIBUTE_STEP_UP, $stepUp);
        $request->request->set('grant_type', AdminOidcGrant::GRANT_IDENTIFIER);
        // League validates the client before the grant runs.
        $request->request->set('client_id', 'administration');

        $psrRequest = $this->psrHttpFactory->createRequest($request)
            ->withAttribute(AdminOidcGrant::REQUEST_ATTRIBUTE_USER_ID, $userId)
            ->withAttribute(AdminOidcGrant::REQUEST_ATTRIBUTE_STEP_UP, $stepUp);
        $psrResponse = $this->psrHttpFactory->createResponse(new Response());

        $tokenResponse = $this->adminAuthorizationServer->respondToAccessTokenRequest($psrRequest, $psrResponse);

        return (new HttpFoundationFactory())->createResponse($tokenResponse);
    }
}
