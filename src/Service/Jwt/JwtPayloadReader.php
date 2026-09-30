<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Jwt;

/**
 * Reads a JWT's payload **without verifying it**. Only for tokens whose
 * signature is checked elsewhere (a Shopware access token this plugin just
 * minted in the same request, or an IdP token right before
 * JwtVerifier::verify() checks it) — never as a trust decision on its own.
 */
final class JwtPayloadReader
{
    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $jwt): ?array
    {
        $segments = explode('.', $jwt);

        if (\count($segments) !== 3) {
            return null;
        }

        $payloadSegment = strtr($segments[1], '-_', '+/');
        $payloadSegment .= str_repeat('=', (4 - \strlen($payloadSegment) % 4) % 4);
        $decoded = base64_decode($payloadSegment, true);

        if ($decoded === false) {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($payload) ? $payload : null;
    }

    public static function stringClaim(string $jwt, string $claim): ?string
    {
        $value = self::decode($jwt)[$claim] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
