<?php

declare(strict_types=1);

namespace carlcs\assetmetadata\tests\integration;

use carlcs\assetmetadata\events\MetadataEvent;
use carlcs\assetmetadata\fields\AssetMetadata;
use carlcs\assetmetadata\Plugin;
use carlcs\assetmetadata\services\Metadata;
use carlcs\assetmetadata\Settings;
use carlcs\assetmetadata\tests\support\CraftFixtures;
use carlcs\assetmetadata\tests\support\Fixtures;
use Craft;
use craft\elements\Asset;
use craft\models\Volume;
use PHPUnit\Framework\TestCase;
use yii\base\Event;

/**
 * Base class for the integration tests. Creates a disposable local volume (inside the Craft project’s
 * storage folder) with an Asset Metadata field, and removes everything again afterwards.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static string $suffix = '';
    protected static string $fixtureDir = '';
    protected static string $volumePath = '';
    protected static ?Volume $volume = null;
    protected static ?AssetMetadata $field = null;

    /** @var int Number of EVENT_AFTER_EXTRACT events seen so far */
    protected static int $extractions = 0;
    private static bool $listening = false;

    /** @var array<string, mixed> Plugin settings to restore after each test */
    private array $originalSettings = [];

    public static function setUpBeforeClass(): void
    {
        static::$suffix = substr(md5(static::class . microtime(true)), 0, 8);
        static::$fixtureDir = Fixtures::tempDir();
        static::$volumePath = Craft::getAlias('@storage') . '/asset-metadata-tests/vol' . static::$suffix;

        static::$volume = CraftFixtures::ensureLocalVolume('AM Test ' . static::$suffix, 'amTest' . static::$suffix, static::$volumePath);
        static::$field = CraftFixtures::ensureField('AM Field ' . static::$suffix, 'amField' . static::$suffix, CraftFixtures::demoSubfields(), static::fieldConfig());
        CraftFixtures::attachFieldToVolume(static::$field, static::$volume);

        if (!self::$listening) {
            Event::on(Metadata::class, Metadata::EVENT_AFTER_EXTRACT, static function(MetadataEvent $event): void {
                self::$extractions++;
            });
            self::$listening = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$volume !== null) {
            CraftFixtures::removeVolume(static::$volume, static::$volumePath);
            static::$volume = null;
        }

        if (static::$field !== null) {
            CraftFixtures::removeField(static::$field);
            static::$field = null;
        }

        Fixtures::removeDir(static::$fixtureDir);
    }

    protected function setUp(): void
    {
        $settings = $this->settings();
        $this->originalSettings = [
            'downloadChunkSize' => $settings->downloadChunkSize,
            'fakeCompleteFileSize' => $settings->fakeCompleteFileSize,
            'maxValueLength' => $settings->maxValueLength,
        ];
    }

    protected function tearDown(): void
    {
        $settings = $this->settings();

        foreach ($this->originalSettings as $name => $value) {
            $settings->$name = $value;
        }
    }

    /**
     * Field attributes for the test field (override per test class).
     *
     * @return array<string, mixed>
     */
    protected static function fieldConfig(): array
    {
        return [];
    }

    // Helpers
    // =========================================================================

    protected function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return $settings;
    }

    /**
     * Uploads a file into the test volume and returns the freshly loaded asset.
     */
    protected function upload(string $filename, string $path, ?Volume $volume = null): Asset
    {
        $asset = CraftFixtures::addAsset($volume ?? static::$volume, $path, $filename);
        self::assertNotNull($asset, "Asset $filename already exists");

        return $this->reload($asset);
    }

    /**
     * Loads a fresh instance of the asset from the database.
     */
    protected function reload(Asset $asset): Asset
    {
        $fresh = Asset::find()->id($asset->id)->status(null)->one();
        self::assertInstanceOf(Asset::class, $fresh, "Asset #$asset->id no longer exists");

        return $fresh;
    }

    /**
     * Returns the (normalized) value of the test field for an asset.
     *
     * @return array<string, string>
     */
    protected function value(Asset $asset, ?AssetMetadata $field = null): array
    {
        $value = $asset->getFieldValue(($field ?? static::$field)->handle);
        self::assertIsArray($value, 'The field value should be an array');

        return $value;
    }

    /**
     * Renders an element index table cell the way Craft does in this version.
     */
    protected function attributeHtml(Asset $asset, string $attribute): string
    {
        if (method_exists($asset, 'getAttributeHtml')) {
            // Craft 5
            return $asset->getAttributeHtml($attribute);
        }

        // Craft 4
        return $asset->getTableAttributeHtml($attribute);
    }

    protected function attributeKey(string $subfieldHandle, ?AssetMetadata $field = null): string
    {
        return 'field:' . ($field ?? static::$field)->handle . ':' . $subfieldHandle;
    }

    /**
     * Returns the plugin’s temporary files currently in Craft’s temp folder.
     *
     * @return string[]
     */
    protected function pluginTempFiles(): array
    {
        return glob(Craft::$app->getPath()->getTempPath() . '/assetmetadata-*') ?: [];
    }
}
