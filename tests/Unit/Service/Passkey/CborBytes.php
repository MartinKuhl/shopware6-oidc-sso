<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Passkey;

/**
 * @internal marks a CBOR byte string (vs. text string)
 */
final class CborBytes
{
    public function __construct(public readonly string $bytes)
    {
    }
}
