<?php
/**
 * PHPUnit bootstrap for the integration tests: boots the real Craft application of an installed Craft
 * project (CRAFT_BASE_PATH, default /app — the Docker harness) with the plugin installed.
 *
 * Run with the project's PHPUnit: `php /app/vendor/bin/phpunit -c /plugin/phpunit.integration.xml.dist`
 * (or `bin/dev integration4` / `bin/dev integration5`).
 */
declare(strict_types=1);

$basePath = getenv('CRAFT_BASE_PATH') ?: '/app';

if (!is_file("$basePath/vendor/autoload.php")) {
    fwrite(STDERR, "No Craft installation found in $basePath (set CRAFT_BASE_PATH).\n");
    exit(1);
}

define('CRAFT_BASE_PATH', $basePath);
define('CRAFT_VENDOR_PATH', $basePath . '/vendor');

require CRAFT_VENDOR_PATH . '/autoload.php';

if (class_exists(Dotenv\Dotenv::class) && file_exists(CRAFT_BASE_PATH . '/.env')) {
    Dotenv\Dotenv::createUnsafeImmutable(CRAFT_BASE_PATH)->safeLoad();
}

// The plugin's test classes aren't part of the Craft project's autoloader
spl_autoload_register(static function(string $class): void {
    $prefix = 'carlcs\\assetmetadata\\tests\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

if (!$app->getIsInstalled()) {
    fwrite(STDERR, "Craft isn’t installed in $basePath.\n");
    exit(1);
}

if (!$app->getPlugins()->isPluginEnabled('asset-metadata')) {
    fwrite(STDERR, "The asset-metadata plugin isn’t installed/enabled in $basePath.\n");
    exit(1);
}

fwrite(STDOUT, sprintf("Integration tests against Craft %s (%s), PHP %s\n", $app->getVersion(), $app->getDb()->getDriverName(), PHP_VERSION));

carlcs\assetmetadata\tests\support\CraftFixtures::removeLeftovers();
