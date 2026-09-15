<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning;

/**
 * Validates a `zoneinfo` claim against PHP's known IANA identifiers — the
 * same set Shopware's own TimeZoneFieldSerializer validates against —
 * pure/DI-free like GenderMapper, so a malformed or missing claim can be
 * checked without letting the DAL write fail the login.
 */
class TimeZoneValidator
{
    public function isValid(?string $zoneinfoClaim): bool
    {
        return $zoneinfoClaim !== null && \in_array($zoneinfoClaim, \DateTimeZone::listIdentifiers(), true);
    }
}
