<?php
/**
 * Seeds the dev Craft install with an isolated local volume (under storage/), an Asset Metadata field
 * attached to that volume, and generated sample assets (JPEG with EXIF/GPS, MP3 with ID3 tags, PNG, text).
 * Idempotent: re-running does nothing for things that already exist.
 *
 * Nothing here touches any real project; the volume lives in /app/storage/asset-metadata-demo.
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

require '/plugin/tests/support/Fixtures.php';
require '/plugin/tests/support/CraftFixtures.php';

use carlcs\assetmetadata\tests\support\CraftFixtures;
use carlcs\assetmetadata\tests\support\Fixtures;

$volume = CraftFixtures::ensureLocalVolume('Asset Metadata Demo', 'assetMetadataDemo', '/app/storage/asset-metadata-demo');
$field = CraftFixtures::ensureField('Metadata', 'metadata', CraftFixtures::demoSubfields(), ['refreshOnElementSave' => false]);
CraftFixtures::attachFieldToVolume($field, $volume);

$tmp = Fixtures::tempDir();
$samples = [
    'photo-with-exif.jpg' => Fixtures::jpeg("$tmp/photo-with-exif.jpg", [
        'Make' => 'Canon',
        'Model' => 'EOS R5 <b>demo</b>',
        'ImageDescription' => 'Demo photo with EXIF, GPS and HTML in a tag',
        'Orientation' => 6,
        'DateTimeOriginal' => '2024:05:06 07:08:09',
        'ExposureTime' => [1, 200],
        'FNumber' => [28, 10],
        'ISOSpeedRatings' => 400,
        'gps' => ['lat' => Fixtures::GPS_LAT, 'lon' => Fixtures::GPS_LON],
    ], 640, 480),
    'photo-without-exif.jpg' => Fixtures::jpeg("$tmp/photo-without-exif.jpg", [], 320, 240),
    'song.mp3' => Fixtures::mp3("$tmp/song.mp3", ['TIT2' => 'Demo song €', 'TPE1' => 'Demo artist', 'TALB' => 'Demo album'], 400, ['title' => 'Demo song v1', 'artist' => 'Demo artist']),
    'logo.png' => Fixtures::png("$tmp/logo.png", 120, 80),
    'notes.txt' => Fixtures::text("$tmp/notes.txt"),
];

foreach ($samples as $filename => $path) {
    $asset = CraftFixtures::addAsset($volume, $path, $filename);
    echo $asset ? "  + $filename (#{$asset->id})\n" : "  = $filename (exists or failed)\n";
}

Fixtures::removeDir($tmp);
Craft::$app->getProjectConfig()->saveModifiedConfigData();
echo "Seed complete.\n";
