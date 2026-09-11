<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning\Exception;

/**
 * Thrown when a user tries to log in via an OIDC provider other than the one
 * that first authenticated (or created) their account — per-user IdP binding,
 * mirroring the Magento module's OAuthMessages::PROVIDER_MISMATCH.
 */
class ProviderMismatchException extends \RuntimeException
{
}
