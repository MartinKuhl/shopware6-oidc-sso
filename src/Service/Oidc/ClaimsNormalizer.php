<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

use MartinKuhl\Sw6Oidc\Service\Oidc\Exception\ClaimsTooComplexException;

/**
 * Flattens nested OIDC claim responses into dot-notation keys and normalizes
 * IdP-specific quirks — ported from the Magento module's
 * Model/Service/OidcAuthenticationService.php, in particular its Zitadel
 * handling (claim_encoding=base64, nested role objects) called out as in-scope
 * for Phase 1.
 */
class ClaimsNormalizer
{
    private const MAX_RECURSION_DEPTH = 5;
    private const MAX_FLATTENED_KEYS = 2000;

    /**
     * @param array<string, mixed> $claims
     *
     * @return array<string, mixed> dot-notation flattened claims
     *
     * @throws ClaimsTooComplexException
     */
    public function flatten(array $claims, string $claimEncoding = 'none'): array
    {
        $flattened = [];
        $this->flattenRecursive($claims, '', 0, $claimEncoding, $flattened);

        return $flattened;
    }

    /**
     * @param array<string, mixed> $target
     */
    private function flattenRecursive(array $claims, string $prefix, int $depth, string $claimEncoding, array &$target): void
    {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            return;
        }

        foreach ($claims as $key => $value) {
            $flatKey = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (\count($target) > self::MAX_FLATTENED_KEYS) {
                throw new ClaimsTooComplexException('OIDC claims response is too large or deeply nested to process safely.');
            }

            if (\is_array($value)) {
                $this->flattenRecursive($value, $flatKey, $depth + 1, $claimEncoding, $target);

                continue;
            }

            $target[$flatKey] = $this->maybeDecode($value, $claimEncoding);
        }
    }

    private function maybeDecode(mixed $value, string $claimEncoding): mixed
    {
        if ($claimEncoding !== 'base64' || !\is_string($value) || $value === '') {
            return $value;
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false || !mb_check_encoding($decoded, 'UTF-8')) {
            return $value;
        }

        return $decoded;
    }

    /**
     * Normalizes the group/role claim into a flat list of group name strings.
     * Handles three shapes seen across real IdPs:
     *  - a single string ("Engineering")
     *  - a flat array (["Engineering", "Developers"])
     *  - Zitadel's nested role-object shape ({"Engineering": {"orgId": "..."}}),
     *    where the parent keys are the group names.
     */
    public function normalizeGroups(mixed $groupsClaim): array
    {
        if (\is_string($groupsClaim)) {
            return $groupsClaim === '' ? [] : [$groupsClaim];
        }

        if (!\is_array($groupsClaim)) {
            return [];
        }

        $isList = array_is_list($groupsClaim);

        if ($isList) {
            return array_values(array_filter(array_map(
                static fn (mixed $item): ?string => \is_string($item) ? $item : null,
                $groupsClaim,
            )));
        }

        // Associative: Zitadel-style nested role object — parent keys are the groups.
        return array_map(strval(...), array_keys($groupsClaim));
    }

    /**
     * @param array<string, mixed> $flattened
     */
    public function extractEmail(array $flattened, string $emailAttribute): ?string
    {
        if (isset($flattened[$emailAttribute]) && \is_string($flattened[$emailAttribute])) {
            return $flattened[$emailAttribute];
        }

        foreach ($flattened as $key => $value) {
            if (\is_string($value) && str_contains($key, 'email') && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }

        foreach ($flattened as $value) {
            if (\is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }

        return null;
    }
}
