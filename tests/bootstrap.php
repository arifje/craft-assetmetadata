<?php
/**
 * PHPUnit bootstrap for the unit tests: a minimal Yii console application with the Craft services the
 * plugin code touches (config, path, i18n, view). No Craft installation and no database are required.
 *
 * Set ASSET_METADATA_VENDOR_DIR to run the same tests against another dependency tree, e.g. the
 * Craft 4 tree in vendor-craft4/ (see bin/dev phpunit4).
 */
declare(strict_types=1);

$vendor = getenv('ASSET_METADATA_VENDOR_DIR') ?: __DIR__ . '/../vendor';
$vendor = rtrim($vendor, '/');

if (!is_file($vendor . '/autoload.php')) {
    fwrite(STDERR, "No Composer autoloader found in $vendor. Run `composer install` first.\n");
    exit(1);
}

require $vendor . '/autoload.php';
require $vendor . '/yiisoft/yii2/Yii.php';
require $vendor . '/craftcms/cms/src/Craft.php';

// The plugin's own test classes (the autoloader of an alternative vendor dir may not know them)
spl_autoload_register(static function(string $class): void {
    $prefix = 'carlcs\\assetmetadata\\tests\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('UTC');

Yii::setAlias('@craft', $vendor . '/craftcms/cms/src');
Yii::setAlias('@carlcs/assetmetadata', dirname(__DIR__) . '/src');

$rootPath = sys_get_temp_dir() . '/asset-metadata-tests/app';
@mkdir($rootPath . '/config', 0777, true);
@mkdir($rootPath . '/storage', 0777, true);
$srcPath = $vendor . '/craftcms/cms/src';

new carlcs\assetmetadata\tests\support\TestApplication([
    'id' => 'asset-metadata-tests',
    // Like Craft itself, the application’s base path (and the `@app` alias) is the CMS source directory
    'basePath' => $srcPath,
    'runtimePath' => $rootPath . '/storage/runtime',
    'vendorPath' => $vendor,
    'components' => [
        'cache' => ['class' => yii\caching\ArrayCache::class],
        'config' => ['class' => craft\services\Config::class, 'configDir' => $rootPath . '/config'],
        'path' => ['class' => craft\services\Path::class],
        'log' => ['targets' => []],
        'i18n' => [
            'translations' => [
                'asset-metadata' => [
                    'class' => yii\i18n\PhpMessageSource::class,
                    'basePath' => dirname(__DIR__) . '/src/translations',
                    'forceTranslation' => true,
                ],
                'app' => [
                    'class' => yii\i18n\PhpMessageSource::class,
                    'basePath' => $vendor . '/craftcms/cms/src/translations',
                    'forceTranslation' => true,
                ],
                'site' => [
                    'class' => yii\i18n\PhpMessageSource::class,
                    'basePath' => $rootPath . '/translations',
                    'forceTranslation' => true,
                ],
            ],
        ],
    ],
]);

// The aliases Craft’s own bootstrap defines (see craftcms/cms/bootstrap/bootstrap.php)
Craft::setAlias('@root', $rootPath);
Craft::setAlias('@lib', $vendor . '/craftcms/cms/lib');
Craft::setAlias('@config', $rootPath . '/config');
Craft::setAlias('@storage', $rootPath . '/storage');
Craft::setAlias('@templates', $rootPath . '/templates');
Craft::setAlias('@translations', $rootPath . '/translations');
Craft::setAlias('@webroot', $rootPath . '/web');
Craft::setAlias('@web', 'http://localhost');
