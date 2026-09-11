<?php
/**
 * DEV HARNESS ONLY — auto-login for browser-based testing without typing credentials.
 * Copied into the Craft project's config/ by the entrypoint. Active only when
 * DEV_AUTOLOGIN=1 is set in the container environment and Craft runs in devMode.
 */
use craft\elements\User;

return [
    'bootstrap' => [
        function(\craft\web\Application $app) {
            if (getenv('DEV_AUTOLOGIN') !== '1' || !$app->getConfig()->getGeneral()->devMode) {
                return;
            }
            $app->on(\craft\web\Application::EVENT_INIT, function() use ($app) {
                if ($app->getRequest()->getIsConsoleRequest() || !$app->getIsInstalled()) {
                    return;
                }
                // The integration tests exercise real authentication; they opt out with this header.
                if ($app->getRequest()->getHeaders()->has('X-Asset-Metadata-Test')) {
                    return;
                }
                $user = $app->getUser();
                if ($user->getIsGuest() && $app->getRequest()->getIsCpRequest()) {
                    $login = $app->getRequest()->getQueryParam('devLogin') ?: getenv('DEV_ADMIN_USERNAME') ?: 'admin';
                    $identity = User::find()->username($login)->one();
                    if ($identity) {
                        $user->login($identity);
                    }
                }
            });
        },
    ],
];
