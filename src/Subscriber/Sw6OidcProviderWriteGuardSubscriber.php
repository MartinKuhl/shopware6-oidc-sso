<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Subscriber;

use Doctrine\DBAL\Connection;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderDefinition;
use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Security\LockoutConfirmationStore;
use MartinKuhl\Sw6Oidc\Service\Security\LockoutGuard;
use MartinKuhl\Sw6Oidc\Service\Security\PasswordSessionRevoker;
use MartinKuhl\Sw6Oidc\Service\Security\SsrfUrlValidator;
use MartinKuhl\Sw6Oidc\Service\Security\Sw6OidcEncryptor;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Save-time validation for every DAL write to sw6oidc_provider (Admin UI, API,
 * CLI import alike):
 *
 *  - SSRF: every URL the plugin will fetch server-side must pass
 *    SsrfUrlValidator (https, public address). `issuer` is only ever compared,
 *    never fetched, so it isn't checked. Outbound calls are additionally
 *    guarded at request time (Sw6OidcHttpClientFactory), since DNS can change
 *    after save.
 *  - The health-alert webhook URL is fetched too, so it is SSRF-checked as
 *    well; it is encrypted by the time this event runs, so it is decrypted
 *    here first.
 *  - Redirect URLs the browser is sent to (post_logout_url) must be absolute
 *    http(s) URLs — never fetched server-side, so no SSRF check, but no
 *    javascript:/data: or relative values either.
 *  - Lockout guard: disable_non_oidc_{admin,customer}_login can only be
 *    switched on once at least one account of that type is bound to *this*
 *    provider — otherwise enabling it would lock every user of that type out
 *    of password login with no working OIDC account to fall back on. For
 *    admins, switching it on while other active admins have no SSO binding
 *    at all needs an explicit confirmation (LockoutConfirmationStore, set via
 *    the confirm-lockout API action; CLI writes count as confirmed), and a
 *    provider can't be deactivated,
 *    re-scoped or deleted while SSO-only mode stays on if that leaves no
 *    admin able to log in (see LockoutGuard). The break-glass
 *    SW6OIDC_ALLOW_PASSWORD_LOGIN=1 always remains.
 *  - The flag can only be switched on where the login page still shows an
 *    SSO button (this provider's or another's).
 *  - When a flag flips on, sessions that were started with a password by
 *    accounts without any SSO binding are ended (refresh tokens/contexts),
 *    so they don't keep renewing under SSO-only mode.
 *
 * Violations are reported against the camelCase property path, so the admin
 * form can show them on the right field.
 */
class Sw6OidcProviderWriteGuardSubscriber implements EventSubscriberInterface, ResetInterface
{
    public const CODE_URL_BLOCKED = 'SW6OIDC_URL_BLOCKED';
    public const CODE_LOCKOUT_GUARD = 'SW6OIDC_LOCKOUT_GUARD';
    public const CODE_REDIRECT_URL_INVALID = 'SW6OIDC_REDIRECT_URL_INVALID';
    public const CODE_LOCKOUT_UNBOUND_USERS = 'SW6OIDC_LOCKOUT_UNBOUND_USERS';
    public const CODE_NO_VISIBLE_LOGIN = 'SW6OIDC_NO_VISIBLE_LOGIN';

    /** storage name => SSO button visibility column for the flag's login type */
    private const LOCKOUT_FLAG_VISIBILITY = [
        'disable_non_oidc_admin_login' => 'show_admin_link',
        'disable_non_oidc_customer_login' => 'show_customer_link',
    ];

    /** @var array<string, list<string>> provider id (hex) => user types whose flag just flipped on */
    private array $pendingRevocations = [];

    /** storage name => property name */
    private const REDIRECT_URL_FIELDS = [
        'post_logout_url' => 'postLogoutUrl',
    ];

    /** storage name => property name */
    private const FETCHED_URL_FIELDS = [
        'well_known_config_url' => 'wellKnownConfigUrl',
        'authorize_endpoint' => 'authorizeEndpoint',
        'access_token_endpoint' => 'accessTokenEndpoint',
        'user_info_endpoint' => 'userInfoEndpoint',
        'jwks_endpoint' => 'jwksEndpoint',
        'end_session_endpoint' => 'endSessionEndpoint',
        'revocation_endpoint' => 'revocationEndpoint',
    ];

    /** storage name => [property name, bound user type] */
    private const LOCKOUT_FLAGS = [
        'disable_non_oidc_admin_login' => ['disableNonOidcAdminLogin', Sw6OidcUserProviderEntity::USER_TYPE_ADMIN],
        'disable_non_oidc_customer_login' => ['disableNonOidcCustomerLogin', Sw6OidcUserProviderEntity::USER_TYPE_CUSTOMER],
    ];

    /** Encrypted fetched URLs: storage name => property name */
    private const ENCRYPTED_FETCHED_URL_FIELDS = [
        'health_alert_webhook_url' => 'healthAlertWebhookUrl',
    ];

    public function __construct(
        private readonly SsrfUrlValidator $urlValidator,
        private readonly Connection $connection,
        private readonly Sw6OidcEncryptor $encryptor,
        private readonly LockoutGuard $lockoutGuard,
        private readonly PasswordSessionRevoker $sessionRevoker,
        private readonly RequestStack $requestStack,
        private readonly LockoutConfirmationStore $confirmationStore,
        private readonly bool $breakGlassAllowPasswordLogin = false,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PreWriteValidationEvent::class => 'validate',
            Sw6OidcProviderDefinition::ENTITY_NAME . '.written' => 'onProviderWritten',
        ];
    }

    public function reset(): void
    {
        $this->pendingRevocations = [];
    }

    public function validate(PreWriteValidationEvent $event): void
    {
        foreach ($event->getCommandsForEntity(Sw6OidcProviderDefinition::ENTITY_NAME) as $command) {
            $violations = new ConstraintViolationList();

            if ($command instanceof DeleteCommand) {
                $this->validateRemovalKeepsAdminAccess($command, null, $violations);
            }

            if (!$command instanceof InsertCommand && !$command instanceof UpdateCommand) {
                if ($violations->count() > 0) {
                    $event->getExceptions()->add(new WriteConstraintViolationException($violations, $command->getPath()));
                }

                continue;
            }

            $current = $command instanceof UpdateCommand ? $this->currentRow($command) : null;

            $this->validateUrls($command, $violations);
            $this->validateRedirectUrls($command, $violations);
            $this->validateLockout($command, $current, $violations);

            if ($command instanceof UpdateCommand) {
                $this->validateRemovalKeepsAdminAccess($command, $current, $violations);
            }

            if ($violations->count() > 0) {
                $event->getExceptions()->add(new WriteConstraintViolationException($violations, $command->getPath()));
            }
        }
    }

    private function validateUrls(WriteCommand $command, ConstraintViolationList $violations): void
    {
        $payload = $command->getPayload();

        $urls = [];

        foreach (self::FETCHED_URL_FIELDS as $storageName => $propertyName) {
            $urls[$propertyName] = $payload[$storageName] ?? null;
        }

        foreach (self::ENCRYPTED_FETCHED_URL_FIELDS as $storageName => $propertyName) {
            $value = $payload[$storageName] ?? null;
            $urls[$propertyName] = \is_string($value) ? $this->encryptor->decrypt($value) : null;
        }

        foreach ($urls as $propertyName => $url) {
            if (!\is_string($url) || $url === '') {
                continue;
            }

            $result = $this->urlValidator->validate($url);

            if ($result['blocked']) {
                $violations->add($this->violation(implode(' ', $result['warnings']), $propertyName, $url, self::CODE_URL_BLOCKED));
            }
        }
    }

    private function validateRedirectUrls(WriteCommand $command, ConstraintViolationList $violations): void
    {
        $payload = $command->getPayload();

        foreach (self::REDIRECT_URL_FIELDS as $storageName => $propertyName) {
            $url = $payload[$storageName] ?? null;

            if (!\is_string($url) || $url === '') {
                continue;
            }

            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $host = parse_url($url, PHP_URL_HOST);

            if (!\in_array($scheme, ['https', 'http'], true) || !\is_string($host) || $host === '') {
                $violations->add($this->violation('Must be an absolute http(s) URL.', $propertyName, $url, self::CODE_REDIRECT_URL_INVALID));
            }
        }
    }

    /**
     * Ends password-started sessions of unbound accounts once the write that
     * flipped a flag on has actually been committed.
     */
    public function onProviderWritten(EntityWrittenEvent $event): void
    {
        foreach ($event->getIds() as $id) {
            if (!\is_string($id) || !isset($this->pendingRevocations[$id])) {
                continue;
            }

            foreach ($this->pendingRevocations[$id] as $userType) {
                $userType === Sw6OidcUserProviderEntity::USER_TYPE_ADMIN
                    ? $this->sessionRevoker->revokeUnboundAdminSessions()
                    : $this->sessionRevoker->revokeUnboundCustomerSessions();
            }

            unset($this->pendingRevocations[$id]);
        }
    }

    /**
     * @param array<string, mixed>|null $current the stored row (update) or null (insert)
     */
    private function validateLockout(WriteCommand $command, ?array $current, ConstraintViolationList $violations): void
    {
        $payload = $command->getPayload();
        $providerId = $command->getPrimaryKey()['id'] ?? null;

        foreach (self::LOCKOUT_FLAGS as $storageName => [$propertyName, $userType]) {
            if (!\array_key_exists($storageName, $payload) || !$payload[$storageName]) {
                continue;
            }

            $flipsOn = !(bool) ($current[$storageName] ?? false);

            if (!$flipsOn) {
                continue;
            }

            $showColumn = self::LOCKOUT_FLAG_VISIBILITY[$storageName];
            $showsButton = (bool) ($payload[$showColumn] ?? $current[$showColumn] ?? true);

            if (!$showsButton && \is_string($providerId) && !$this->lockoutGuard->otherVisibleProviderExists(Uuid::fromBytesToHex($providerId), $userType)) {
                $violations->add($this->violation(
                    'Password login can only be disabled while the login page shows at least one SSO button for these accounts.',
                    $propertyName,
                    true,
                    self::CODE_NO_VISIBLE_LOGIN,
                ));

                continue;
            }

            $hasBoundAccount = \is_string($providerId) && $this->connection->fetchOne(
                'SELECT 1 FROM `sw6oidc_user_provider` WHERE `provider_id` = :providerId AND `user_type` = :userType LIMIT 1',
                ['providerId' => $providerId, 'userType' => $userType],
            ) !== false;

            if (!$hasBoundAccount) {
                $violations->add($this->violation(
                    sprintf(
                        'Password login for %s accounts can only be disabled once at least one %s account has signed in through this provider — otherwise nobody could log in.',
                        $userType,
                        $userType,
                    ),
                    $propertyName,
                    true,
                    self::CODE_LOCKOUT_GUARD,
                ));

                continue;
            }

            // $hasBoundAccount implies a string id from here on.
            if ($userType === Sw6OidcUserProviderEntity::USER_TYPE_ADMIN) {
                $unbound = \count($this->lockoutGuard->unboundActiveAdminIds([Uuid::fromBytesToHex($providerId)]));

                if ($unbound > 0 && !$this->lockoutConfirmed(Uuid::fromBytesToHex($providerId))) {
                    $violations->add($this->violation(
                        sprintf(
                            '%d active admin account(s) have no single sign-on binding and would be locked out. Confirm to disable password login anyway.',
                            $unbound,
                        ),
                        $propertyName,
                        true,
                        self::CODE_LOCKOUT_UNBOUND_USERS,
                    ));

                    continue;
                }
            }

            $this->pendingRevocations[Uuid::fromBytesToHex($providerId)][] = $userType;
        }
    }

    /**
     * Deleting, deactivating or re-scoping (to customers only) a provider
     * must not leave SSO-only mode on with no admin able to log in.
     *
     * @param array<string, mixed>|null $current
     */
    private function validateRemovalKeepsAdminAccess(WriteCommand $command, ?array $current, ConstraintViolationList $violations): void
    {
        $providerId = $command->getPrimaryKey()['id'] ?? null;

        if (!\is_string($providerId) || $this->breakGlassAllowPasswordLogin) {
            return;
        }

        if ($command instanceof UpdateCommand) {
            $payload = $command->getPayload();
            $deactivates = \array_key_exists('is_active', $payload) && !$payload['is_active'] && (bool) ($current['is_active'] ?? false);
            $leavesAdmin = \array_key_exists('login_type', $payload) && $payload['login_type'] === 'customer'
                && \in_array($current['login_type'] ?? null, ['admin', 'both'], true);

            if (!$deactivates && !$leavesAdmin) {
                return;
            }
        }

        $hexId = Uuid::fromBytesToHex($providerId);

        if (!$this->adminPolicyStaysOnWithout($providerId) || $this->lockoutGuard->adminLoginRemainsPossible(excludedProviderIds: [$hexId])) {
            return;
        }

        $violations->add($this->violation(
            'Administration password login is disabled and no other admin could log in through an active provider after this change.',
            'isActive',
            false,
            self::CODE_LOCKOUT_GUARD,
        ));
    }

    /**
     * Whether another active admin-serving provider keeps SSO-only mode on.
     */
    private function adminPolicyStaysOnWithout(string $providerIdBytes): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM `sw6oidc_provider` WHERE `is_active` = 1 AND `login_type` IN (\'admin\', \'both\') AND `disable_non_oidc_admin_login` = 1 AND `id` <> :id LIMIT 1',
            ['id' => $providerIdBytes],
        ) !== false;
    }

    private function lockoutConfirmed(string $providerId): bool
    {
        // CLI (config import) runs without a request: the operator is explicit.
        return !$this->requestStack->getMainRequest() instanceof \Symfony\Component\HttpFoundation\Request
            || $this->confirmationStore->consume($providerId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentRow(UpdateCommand $command): ?array
    {
        $providerId = $command->getPrimaryKey()['id'] ?? null;

        if (!\is_string($providerId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT `is_active`, `login_type`, `disable_non_oidc_admin_login`, `disable_non_oidc_customer_login`, `show_admin_link`, `show_customer_link`
             FROM `sw6oidc_provider` WHERE `id` = :id',
            ['id' => $providerId],
        );

        return $row === false ? null : $row;
    }

    private function violation(string $message, string $propertyName, mixed $invalidValue, string $code): ConstraintViolation
    {
        return new ConstraintViolation($message, $message, [], null, '/' . $propertyName, $invalidValue, null, $code);
    }
}
