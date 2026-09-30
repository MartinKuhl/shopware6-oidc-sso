<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Subscriber;

use Doctrine\DBAL\Connection;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderDefinition;
use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Security\SsrfUrlValidator;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Save-time validation for every DAL write to sw6oidc_provider (Admin UI, API,
 * CLI import alike):
 *
 *  - SSRF: every URL the plugin will fetch server-side must pass
 *    SsrfUrlValidator (https, public address). `issuer` is only ever compared,
 *    never fetched, so it isn't checked. Outbound calls are additionally
 *    guarded at request time (Sw6OidcHttpClientFactory), since DNS can change
 *    after save.
 *  - Redirect URLs the browser is sent to (post_logout_url) must be absolute
 *    http(s) URLs — never fetched server-side, so no SSRF check, but no
 *    javascript:/data: or relative values either.
 *  - Lockout guard: disable_non_oidc_{admin,customer}_login can only be
 *    switched on once at least one account of that type is bound to *this*
 *    provider — otherwise enabling it would lock every user of that type out
 *    of password login with no working OIDC account to fall back on.
 *
 * Violations are reported against the camelCase property path, so the admin
 * form can show them on the right field.
 */
class Sw6OidcProviderWriteGuardSubscriber implements EventSubscriberInterface
{
    public const CODE_URL_BLOCKED = 'SW6OIDC_URL_BLOCKED';
    public const CODE_LOCKOUT_GUARD = 'SW6OIDC_LOCKOUT_GUARD';
    public const CODE_REDIRECT_URL_INVALID = 'SW6OIDC_REDIRECT_URL_INVALID';

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

    public function __construct(
        private readonly SsrfUrlValidator $urlValidator,
        private readonly Connection $connection,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [PreWriteValidationEvent::class => 'validate'];
    }

    public function validate(PreWriteValidationEvent $event): void
    {
        foreach ($event->getCommandsForEntity(Sw6OidcProviderDefinition::ENTITY_NAME) as $command) {
            if (!$command instanceof InsertCommand && !$command instanceof UpdateCommand) {
                continue;
            }

            $violations = new ConstraintViolationList();
            $this->validateUrls($command, $violations);
            $this->validateRedirectUrls($command, $violations);
            $this->validateLockout($command, $violations);

            if ($violations->count() > 0) {
                $event->getExceptions()->add(new WriteConstraintViolationException($violations, $command->getPath()));
            }
        }
    }

    private function validateUrls(WriteCommand $command, ConstraintViolationList $violations): void
    {
        $payload = $command->getPayload();

        foreach (self::FETCHED_URL_FIELDS as $storageName => $propertyName) {
            $url = $payload[$storageName] ?? null;

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

    private function validateLockout(WriteCommand $command, ConstraintViolationList $violations): void
    {
        $payload = $command->getPayload();
        $providerId = $command->getPrimaryKey()['id'] ?? null;

        foreach (self::LOCKOUT_FLAGS as $storageName => [$propertyName, $userType]) {
            if (!\array_key_exists($storageName, $payload) || !$payload[$storageName]) {
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
            }
        }
    }

    private function violation(string $message, string $propertyName, mixed $invalidValue, string $code): ConstraintViolation
    {
        return new ConstraintViolation($message, $message, [], null, '/' . $propertyName, $invalidValue, null, $code);
    }
}
