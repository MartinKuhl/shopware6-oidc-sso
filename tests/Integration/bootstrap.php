<?php declare(strict_types=1);

/*
 * Boots a real Shopware 6.7 test kernel with this plugin installed and
 * active. SHOPWARE_PROJECT_ROOT must point at a Shopware installation whose
 * Composer setup requires this plugin (path repository), see README.md.
 * Shopware's TestBootstrapper creates and uses a separate `<db>_test`
 * database, so the shop's own data is never touched.
 */

use Shopware\Core\TestBootstrapper;

$projectRoot = getenv('SHOPWARE_PROJECT_ROOT');

if (!\is_string($projectRoot) || $projectRoot === '' || !is_file($projectRoot . '/vendor/shopware/core/TestBootstrapper.php')) {
    fwrite(\STDERR, "SHOPWARE_PROJECT_ROOT must point at a Shopware 6.7 installation that requires this plugin.\n");

    exit(1);
}

$loader = require $projectRoot . '/vendor/autoload.php';
$loader->addPsr4('MartinKuhl\\Sw6Oidc\\Tests\\', __DIR__ . '/..');

(new TestBootstrapper())
    ->setProjectDir($projectRoot)
    ->setClassLoader($loader)
    ->setLoadEnvFile(true)
    ->setForceInstallPlugins(true)
    ->addActivePlugins('Sw6Oidc')
    ->bootstrap();
