<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Http\OidcHttpClient;

class UserInfoService
{
    public function __construct(private readonly OidcHttpClient $httpClient)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchClaims(Sw6OidcProviderEntity $provider, string $accessToken): array
    {
        if ($provider->getUserInfoEndpoint() === null) {
            return [];
        }

        return $this->httpClient->getWithBearerToken(
            $provider->getUserInfoEndpoint(),
            $accessToken,
            $provider->getHttpTimeout(),
        );
    }
}
