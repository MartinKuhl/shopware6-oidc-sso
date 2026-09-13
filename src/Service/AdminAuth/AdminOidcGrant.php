<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\AdminAuth;

use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\RequestAccessTokenEvent;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\RequestRefreshTokenEvent;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;
use Shopware\Core\Framework\Api\OAuth\User\User as ShopwareOAuthUser;

/**
 * A custom `league/oauth2-server` grant that trusts a pre-verified Shopware
 * admin user id instead of a password. The id is carried as a PSR-7 request
 * attribute (see REQUEST_ATTRIBUTE_USER_ID), set by
 * Controller/Api/OidcAdminAuthController::exchangeNonce() immediately after
 * redeeming a one-time OIDC/Passkey login nonce.
 *
 * This is the crux of the whole Administration bridge (see the plan's
 * "bridging pattern" section): registering this grant on an AuthorizationServer
 * built from Shopware's own real ClientRepository/AccessTokenRepository/
 * ScopeRepository/FakeCryptKey (see AdminAuthorizationServerFactory) means the
 * token this grant issues is a genuine Shopware access/refresh token pair,
 * indistinguishable from a normal password-grant login to the rest of the
 * system — never a faked or parallel token format.
 */
class AdminOidcGrant extends AbstractGrant
{
    public const GRANT_IDENTIFIER = 'sw6oidc_admin';
    public const REQUEST_ATTRIBUTE_USER_ID = 'sw6oidc_user_id';

    public function __construct(RefreshTokenRepositoryInterface $refreshTokenRepository)
    {
        // AuthorizationServer::enableGrantType() never sets this - League
        // only wires up client/access-token/scope repositories, default
        // scope, private key and the emitter there. Every stock grant that
        // issues refresh tokens (PasswordGrant, RefreshTokenGrant, ...) sets
        // its own via setRefreshTokenRepository() in its constructor; without
        // it, AbstractGrant::issueRefreshToken() below throws "Typed property
        // ...::$refreshTokenRepository must not be accessed before
        // initialization" the first time a token is actually issued.
        $this->setRefreshTokenRepository($refreshTokenRepository);
        $this->refreshTokenTTL = new \DateInterval('P1M');
    }

    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        \DateInterval $accessTokenTTL,
    ): ResponseTypeInterface {
        $client = $this->validateClient($request);
        $scopes = $this->validateScopes($this->getRequestParameter('scope', $request, $this->defaultScope));
        $user = $this->validateUser($request);

        $finalizedScopes = $this->scopeRepository->finalizeScopes($scopes, $this->getIdentifier(), $client, $user->getIdentifier());

        $accessToken = $this->issueAccessToken($accessTokenTTL, $client, $user->getIdentifier(), $finalizedScopes);
        $this->getEmitter()->emit(new RequestAccessTokenEvent(RequestEvent::ACCESS_TOKEN_ISSUED, $request, $accessToken));
        $responseType->setAccessToken($accessToken);

        $refreshToken = $this->issueRefreshToken($accessToken);

        if ($refreshToken instanceof \League\OAuth2\Server\Entities\RefreshTokenEntityInterface) {
            $this->getEmitter()->emit(new RequestRefreshTokenEvent(RequestEvent::REFRESH_TOKEN_ISSUED, $request, $refreshToken));
            $responseType->setRefreshToken($refreshToken);
        }

        return $responseType;
    }

    public function getIdentifier(): string
    {
        return self::GRANT_IDENTIFIER;
    }

    private function validateUser(ServerRequestInterface $request): UserEntityInterface
    {
        $userId = $request->getAttribute(self::REQUEST_ATTRIBUTE_USER_ID);

        if (!\is_string($userId) || $userId === '') {
            throw OAuthServerException::invalidRequest(self::REQUEST_ATTRIBUTE_USER_ID);
        }

        return new ShopwareOAuthUser($userId);
    }
}
