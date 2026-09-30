<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning\Exception;

/**
 * Explicit "Connect SSO": the IdP subject is already bound to a different
 * account of the same type.
 */
class SubjectAlreadyLinkedException extends \RuntimeException
{
}
