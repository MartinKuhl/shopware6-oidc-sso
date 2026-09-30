<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning\Exception;

/**
 * An account with the IdP's email already exists but may not be linked
 * automatically (the provider doesn't allow linking, the email isn't
 * verified, the account is a superadmin, or it is bound to another subject).
 * The owner has to connect the provider from their own, logged-in account.
 */
class AccountLinkingRequiredException extends \RuntimeException
{
}
