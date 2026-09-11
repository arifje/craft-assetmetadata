<?php

declare(strict_types=1);

namespace carlcs\assetmetadata\tests\support;

use yii\console\Application;

/**
 * A minimal application standing in for `craft\console\Application` in the unit tests.
 *
 * It provides the handful of application methods Craft’s element and field classes call while being
 * constructed, without a database or an installed Craft instance.
 */
final class TestApplication extends Application
{
    /**
     * Craft is never “installed” in unit tests, so elements don’t look up sites and users.
     */
    public function getIsInstalled(bool $refresh = false): bool
    {
        return false;
    }

    public function getIsInitialized(): bool
    {
        return true;
    }

    public function getVersion(): string
    {
        return \Craft::$app->has('cms-version') ? (string)\Craft::$app->get('cms-version') : '0.0.0';
    }

    /**
     * The Craft services registered in the bootstrap are exposed the way Craft exposes them.
     */
    public function getConfig(): \craft\services\Config
    {
        /** @var \craft\services\Config $config */
        $config = $this->get('config');
        return $config;
    }

    public function getPath(): \craft\services\Path
    {
        /** @var \craft\services\Path $path */
        $path = $this->get('path');
        return $path;
    }
}
