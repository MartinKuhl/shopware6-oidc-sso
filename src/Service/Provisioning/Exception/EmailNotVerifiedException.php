<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning\Exception;

/**
 * The provider requires `email_verified: true` and the IdP didn't assert it
 * (a missing claim counts as unverified).
 */
class EmailNotVerifiedException extends \RuntimeException
{
}
