<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Support;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

/**
 * Signs test id_tokens with a throwaway RSA key and exposes the matching JWKS.
 */
final class JwtTestSigner
{
    public readonly JWK $key;

    public function __construct(string $kid = 'test-key')
    {
        $this->key = JWKFactory::createRSAKey(2048, ['kid' => $kid, 'alg' => 'RS256', 'use' => 'sig']);
    }

    public function jwksJson(): string
    {
        return json_encode(new JWKSet([$this->key->toPublic()]), \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function sign(array $claims): string
    {
        $jws = (new JWSBuilder(new AlgorithmManager([new RS256()])))
            ->create()
            ->withPayload(json_encode($claims, \JSON_THROW_ON_ERROR))
            ->addSignature($this->key, ['alg' => 'RS256', 'kid' => $this->key->get('kid')])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function signHs256(array $claims): string
    {
        $key = JWKFactory::createOctKey(256, ['alg' => 'HS256']);
        $jws = (new JWSBuilder(new AlgorithmManager([new HS256()])))
            ->create()
            ->withPayload(json_encode($claims, \JSON_THROW_ON_ERROR))
            ->addSignature($key, ['alg' => 'HS256'])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }
}
