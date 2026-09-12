<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Controller\Api;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use MartinKuhl\Sw6Oidc\Service\AdminAuth\AdminOidcGrant;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyAuthenticationService;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyConfig;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyCredentialRepository;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyRegistrationService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\User\UserEntity;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Administration Passkey self-service registration + email-scoped login.
 * Registration requires a normal authenticated admin session (default
 * `auth_required: true`); login is anonymous but, unlike OIDC, needs no nonce
 * hand-off at all — WebAuthn is a same-page AJAX ceremony, so the verified
 * user id is set as a request attribute and fed straight into the same
 * in-process AdminOidcGrant/AuthorizationServer bridge the OIDC flow's
 * exchangeNonce() action uses. Mirrors the Magento module's
 * Controller/Adminhtml/Actions/Passkey/* controllers.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
#[Package('framework')]
class PasskeyAdminController extends AbstractController
{
    public function __construct(
        private readonly PasskeyRegistrationService $registrationService,
        private readonly PasskeyAuthenticationService $authenticationService,
        private readonly PasskeyCredentialRepository $passkeyCredentialRepository,
        private readonly PasskeyConfig $passkeyConfig,
        private readonly EntityRepository $userRepository,
        private readonly AuthorizationServer $adminAuthorizationServer,
        private readonly PsrHttpFactory $psrHttpFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/api/sw6oidc/admin/passkey/registration-options', name: 'api.action.sw6oidc.admin.passkey.registration-options', methods: ['POST'])]
    public function registrationOptions(Request $request, Context $context): JsonResponse
    {
        $user = $this->currentUser($context);

        $result = $this->registrationService->buildCreationOptions(
            'admin',
            $user->getId(),
            $user->getUsername(),
            trim($user->getFirstName() . ' ' . $user->getLastName()),
            $this->passkeyConfig->getRpId($request->getHost()),
            $this->passkeyConfig->getRpName('Shopware Administration'),
        );

        return new JsonResponse(['sessionId' => $result['nonce'], 'options' => json_decode($result['optionsJson'], true)]);
    }

    #[Route(path: '/api/sw6oidc/admin/passkey/registration-verify', name: 'api.action.sw6oidc.admin.passkey.registration-verify', methods: ['POST'])]
    public function registrationVerify(Request $request): JsonResponse
    {
        try {
            $this->registrationService->verifyAndPersist(
                (string) $request->request->get('sessionId'),
                (string) $request->request->get('credential'),
                $this->psrHttpFactory->createRequest($request),
                $request->request->get('nickname') !== null ? (string) $request->request->get('nickname') : null,
            );

            return new JsonResponse(['status' => true]);
        } catch (\Throwable $exception) {
            $this->logger->warning('sw6oidc: admin passkey registration failed.', ['exception' => $exception->getMessage()]);

            return new JsonResponse(['status' => false, 'message' => $exception->getMessage()], 400);
        }
    }

    #[Route(path: '/api/sw6oidc/admin/passkey/login-options', name: 'api.action.sw6oidc.admin.passkey.login-options', defaults: ['auth_required' => false], methods: ['POST'])]
    public function loginOptions(Request $request): JsonResponse
    {
        $context = Context::createDefaultContext();
        $email = $request->request->get('email');

        $allowCredentials = \is_string($email) && $email !== ''
            ? $this->allowCredentialsForEmail($email, $context)
            : [];

        $result = $this->authenticationService->buildRequestOptions($allowCredentials, $this->passkeyConfig->getRpId($request->getHost()));

        return new JsonResponse(['sessionId' => $result['nonce'], 'options' => json_decode($result['optionsJson'], true)]);
    }

    #[Route(path: '/api/sw6oidc/admin/passkey/login-verify', name: 'api.action.sw6oidc.admin.passkey.login-verify', defaults: ['auth_required' => false], methods: ['POST'])]
    public function loginVerify(Request $request): Response
    {
        try {
            $resolved = $this->authenticationService->verifyAssertion(
                (string) $request->request->get('sessionId'),
                (string) $request->request->get('credential'),
                $this->psrHttpFactory->createRequest($request),
            );

            if ($resolved['userType'] !== 'admin') {
                throw new \RuntimeException('This passkey is not registered to an Administration user.');
            }

            $request->attributes->set(AdminOidcGrant::REQUEST_ATTRIBUTE_USER_ID, $resolved['userId']);
            $request->request->set('grant_type', AdminOidcGrant::GRANT_IDENTIFIER);
            // League's AuthorizationServer validates client_id before our
            // grant's validateUser() ever runs - the OIDC nonce-exchange
            // flow's JS sends this explicitly, this endpoint never received
            // anything client_id-shaped at all, so League rejected the
            // request outright as malformed.
            $request->request->set('client_id', 'administration');

            $psrRequest = $this->psrHttpFactory->createRequest($request);
            $psrResponse = $this->psrHttpFactory->createResponse(new Response());

            $tokenResponse = $this->adminAuthorizationServer->respondToAccessTokenRequest($psrRequest, $psrResponse);

            return (new HttpFoundationFactory())->createResponse($tokenResponse);
        } catch (OAuthServerException $exception) {
            return $this->json(['error' => 'invalid_grant', 'error_description' => $exception->getMessage()], 400);
        } catch (\Throwable $exception) {
            $this->logger->warning('sw6oidc: admin passkey login failed.', ['exception' => $exception->getMessage()]);

            return $this->json(['status' => false, 'message' => $exception->getMessage()], 401);
        }
    }

    private function currentUser(Context $context): UserEntity
    {
        $source = $context->getSource();

        if (!$source instanceof AdminApiSource || $source->getUserId() === null) {
            throw new \RuntimeException('This action requires an authenticated Administration user.');
        }

        $user = $this->userRepository->search(new Criteria([$source->getUserId()]), $context)->first();

        if (!$user instanceof UserEntity) {
            throw new \RuntimeException('The authenticated Administration user could not be loaded.');
        }

        return $user;
    }

    /**
     * @return \Webauthn\PublicKeyCredentialDescriptor[]
     */
    private function allowCredentialsForEmail(string $email, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('email', $email));
        $criteria->setLimit(1);

        $user = $this->userRepository->search($criteria, $context)->first();

        if (!$user instanceof UserEntity) {
            return [];
        }

        // hash('admin:' . userId) is only the *opaque* WebAuthn user handle
        // used at registration time (see PasskeyRegistrationService) — it's
        // one-way by design, but findAllForUserEntity() only needs it to
        // look up already-registered credentials, never to reverse it.
        $userHandle = hash('sha256', 'admin:' . $user->getId(), true);
        $userEntity = new \Webauthn\PublicKeyCredentialUserEntity('', $userHandle, '');

        return array_map(
            static fn ($source) => $source->getPublicKeyCredentialDescriptor(),
            $this->passkeyCredentialRepository->findAllForUserEntity($userEntity),
        );
    }
}
