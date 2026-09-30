<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Security\Exception;

use Shopware\Core\Checkout\Customer\Exception\CustomerOptinNotCompletedException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer password login is disabled because an active OIDC provider has
 * disable_non_oidc_customer_login set.
 *
 * Extends CustomerOptinNotCompletedException only because that is the one
 * exception the Storefront AuthController::login() turns into a custom
 * error message (via getSnippetKey()) instead of the generic "invalid
 * credentials" — message, error code and status are this plugin's own, so
 * Store API clients see an accurate error.
 */
class PasswordLoginDisabledException extends CustomerOptinNotCompletedException
{
    public const ERROR_CODE = 'SW6OIDC_PASSWORD_LOGIN_DISABLED';

    public function __construct()
    {
        parent::__construct('', Response::HTTP_FORBIDDEN, self::ERROR_CODE);

        $this->message = 'Password login is disabled for this shop. Please sign in with single sign-on.';
    }

    public function getSnippetKey(): string
    {
        return 'sw6oidc.login.passwordLoginDisabled';
    }
}
