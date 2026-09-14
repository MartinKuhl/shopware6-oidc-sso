<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes this plugin's own Vite-built Administration entry points to Twig,
 * read directly from its own entrypoints.json (the same file
 * Shopware\Administration\Framework\Twig\ViteFileAccessorDecorator reads
 * internally for any bundle). Pentatrion's public vite_entry_script_tags()
 * Twig function only resolves pre-registered Vite "build configs" - Shopware
 * only registers its own core "administration" one - so calling it with an
 * arbitrary plugin bundle name throws
 * Pentatrion\ViteBundle\Exception\UndefinedConfigNameException, even though
 * the underlying PHP-level lookup supports any bundle. Reading the manifest
 * ourselves sidesteps that restriction entirely.
 */
class AdminEntrypointsExtension extends AbstractExtension
{
    private const ENTRY_NAME = 'sw6-oidc';

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sw6oidc_admin_scripts', $this->getScripts(...)),
            new TwigFunction('sw6oidc_admin_styles', $this->getStyles(...)),
        ];
    }

    /**
     * @return string[]
     */
    public function getScripts(): array
    {
        return $this->readEntrypoint()['js'] ?? [];
    }

    /**
     * @return string[]
     */
    public function getStyles(): array
    {
        return $this->readEntrypoint()['css'] ?? [];
    }

    /**
     * @return array{js?: string[], css?: string[]}
     */
    private function readEntrypoint(): array
    {
        $manifestPath = __DIR__ . '/../Resources/public/administration/.vite/entrypoints.json';

        if (!is_file($manifestPath)) {
            return [];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        return $manifest['entryPoints'][self::ENTRY_NAME] ?? [];
    }
}
