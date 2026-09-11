<?php
/**
 * Makes sure the harness admin account exists and is active (idempotent). Used after a test run
 * removed it, or to reset its password: `php /scripts/ensure-admin.php`.
 */
declare(strict_types=1);

define('CRAFT_BASE_PATH', '/app');
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');
require CRAFT_VENDOR_PATH . '/autoload.php';
if (class_exists(Dotenv\Dotenv::class) && file_exists(CRAFT_BASE_PATH . '/.env')) {
    Dotenv\Dotenv::createUnsafeImmutable(CRAFT_BASE_PATH)->safeLoad();
}
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\User;

$username = getenv('DEV_ADMIN_USERNAME') ?: 'admin';
$password = getenv('DEV_ADMIN_PASSWORD') ?: 'password';
$email = getenv('DEV_ADMIN_EMAIL') ?: 'admin@example.com';

$user = User::find()->username($username)->status(null)->one() ?? new User();
$isNew = !$user->id;
$user->username = $username;
$user->email = $email;
$user->admin = true;
$user->newPassword = $password;

if (!Craft::$app->getElements()->saveElement($user)) {
    fwrite(STDERR, 'Could not save the admin user: ' . implode('; ', $user->getFirstErrors()) . PHP_EOL);
    exit(1);
}

if (!$user->active) {
    Craft::$app->getUsers()->activateUser($user);
}

echo ($isNew ? 'Created' : 'Updated') . " admin user “{$username}” (#{$user->id}).\n";
