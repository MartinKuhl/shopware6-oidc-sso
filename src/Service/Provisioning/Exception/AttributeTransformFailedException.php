<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning\Exception;

/**
 * A transform on an identity attribute (email, username) failed. Falling
 * back to the raw IdP value would change which account the login resolves
 * to, so the login is refused instead.
 */
class AttributeTransformFailedException extends \RuntimeException
{
}
