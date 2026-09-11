<?php

declare(strict_types=1);

namespace carlcs\assetmetadata\tests\integration;

use carlcs\assetmetadata\fields\AssetMetadata;
use carlcs\assetmetadata\Plugin;
use carlcs\assetmetadata\tests\support\CraftFixtures;
use carlcs\assetmetadata\tests\support\Fixtures;
use Craft;
use craft\elements\Asset;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\models\Volume;
use GraphQL\Type\Definition\ObjectType;

/**
 * Saving, refreshing and rendering Asset Metadata fields inside a real Craft installation.
 */
final class FieldLifecycleTest extends IntegrationTestCase
{
    /** A second volume + field with “Refresh on element save” enabled */
    private static ?Volume $refreshVolume = null;
    private static ?AssetMetadata $refreshField = null;
    private static string $refreshVolumePath = '';
    private static ?AssetMetadata $hugeField = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$refreshVolumePath = Craft::getAlias('@storage') . '/asset-metadata-tests/refresh' . static::$suffix;
        self::$refreshVolume = CraftFixtures::ensureLocalVolume('AM Refresh ' . static::$suffix, 'amRefresh' . static::$suffix, self::$refreshVolumePath);
        self::$refreshField = CraftFixtures::ensureField('AM Refresh Field ' . static::$suffix, 'amRefreshField' . static::$suffix, CraftFixtures::demoSubfields(), ['refreshOnElementSave' => true]);
        CraftFixtures::attachFieldToVolume(self::$refreshField, self::$refreshVolume);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$refreshVolume !== null) {
            CraftFixtures::removeVolume(self::$refreshVolume, self::$refreshVolumePath);
            self::$refreshVolume = null;
        }

        parent::tearDownAfterClass();

        // Fields are removed last: Craft 4 keeps the (now stale) field layouts of this process in memory
        foreach ([self::$refreshField, self::$hugeField] as $field) {
            if ($field !== null) {
                CraftFixtures::removeField($field);
            }
        }

        self::$refreshField = null;
        self::$hugeField = null;
    }

    public function testUploadingAJpegWithExifStoresTheRenderedSubfields(): void
    {
        $before = self::$extractions;
        $asset = $this->upload('photo.jpg', Fixtures::jpeg(static::$fixtureDir . '/photo.jpg', [
            'Make' => 'Canon',
            'Model' => 'EOS <script>alert(1)</script>',
            'ImageDescription' => '<img src=x onerror=alert(1)>',
            'Orientation' => 6,
            'DateTimeOriginal' => '2024:05:06 07:08:09',
            'gps' => ['lat' => Fixtures::GPS_LAT, 'lon' => Fixtures::GPS_LON],
        ]));

        $value = $this->value($asset);

        self::assertSame('EOS <script>alert(1)</script>', $value['camera'], 'Metadata is stored verbatim; escaping happens when rendering');
        self::assertSame('2024-05-06 07:08:09', $value['taken']);
        self::assertSame('52°22\'45.1"N 4°53\'58.0"E', $value['location']);
        self::assertSame('6', $value['orientation']);
        self::assertSame('<img src=x onerror=alert(1)>', $value['description']);
        self::assertSame('', $value['title']);
        self::assertSame('', $value['duration']);
        self::assertSame('image/jpeg', $value['mimeType']);
        // Values are available under the subfield IDs and their handles
        self::assertSame($value['camera'], $value['col1']);
        self::assertSame(1, self::$extractions - $before, 'A new asset is analyzed exactly once');
    }

    public function testTableAttributesAreRegisteredAndRenderedEscaped(): void
    {
        $attributes = Asset::tableAttributes();
        $key = $this->attributeKey('description');

        self::assertArrayHasKey($key, $attributes);
        self::assertSame('Description', $attributes[$key]['label']);
        self::assertArrayHasKey($this->attributeKey('camera'), $attributes);

        $asset = $this->upload('escaped.jpg', Fixtures::jpeg(static::$fixtureDir . '/escaped.jpg', [
            'Model' => 'A & B <b>bold</b>',
            'ImageDescription' => '<img src=x onerror=alert(1)>',
        ]));
        $before = self::$extractions;

        $html = $this->attributeHtml($asset, $key);

        self::assertSame(Html::encode('<img src=x onerror=alert(1)>'), $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertSame('A &amp; B &lt;b&gt;bold&lt;/b&gt;', $this->attributeHtml($asset, $this->attributeKey('camera')));
        self::assertSame('', $this->attributeHtml($asset, $this->attributeKey('title')));
        self::assertSame($before, self::$extractions, 'Rendering index rows must not extract metadata');
        // Craft’s own attributes are untouched
        self::assertStringContainsString('escaped.jpg', $this->attributeHtml($asset, 'filename'));

        if (method_exists($asset, 'getInlineAttributeInputHtml')) {
            // Craft 5 inline-editing mode renders the same (escaped) content
            self::assertSame($html, $asset->getInlineAttributeInputHtml($key));
        }
    }

    public function testAnAssetWithoutTheFieldRendersAnEmptyCell(): void
    {
        // The refresh volume doesn’t have the main test field in its layout
        $asset = $this->upload('other.jpg', Fixtures::jpeg(static::$fixtureDir . '/other.jpg', ['Model' => 'X']), self::$refreshVolume);

        self::assertSame('', $this->attributeHtml($asset, $this->attributeKey('camera')));
    }

    public function testMp3TagsAndPlaytimeAreExtracted(): void
    {
        $asset = $this->upload('song.mp3', Fixtures::mp3(static::$fixtureDir . '/song.mp3', ['TIT2' => 'Fixture song €', 'TPE1' => 'Artist'], 100, ['title' => "Caf\xE9"]));

        $value = $this->value($asset);

        self::assertSame('Fixture song €', $value['title']);
        self::assertSame('0:03', $value['duration']);
        self::assertSame('audio/mpeg', $value['mimeType']);
        self::assertSame('', $value['camera']);
    }

    public function testUnsupportedAndCorruptFilesAreSavedWithEmptyValues(): void
    {
        // (A truncated image isn’t an option here: Craft itself refuses to save an image it can’t read.)
        $files = [
            'notes.txt' => Fixtures::text(static::$fixtureDir . '/notes.txt'),
            'garbage.pdf' => Fixtures::garbage(static::$fixtureDir . '/garbage.pdf'),
            'garbage.mp3' => Fixtures::garbage(static::$fixtureDir . '/garbage.mp3'),
            'truncated.mp3' => Fixtures::truncated(Fixtures::mp3(static::$fixtureDir . '/full.mp3'), static::$fixtureDir . '/truncated.mp3', 300),
        ];

        foreach ($files as $filename => $path) {
            $asset = $this->upload($filename, $path);
            $value = $this->value($asset);

            foreach (['camera', 'taken', 'location', 'orientation', 'description'] as $handle) {
                self::assertSame('', $value[$handle], "$filename: $handle");
            }

            if ($filename !== 'truncated.mp3') {
                self::assertSame('', $value['title'], "$filename: title");
                self::assertSame('', $value['duration'], "$filename: duration");
            } else {
                // The intact ID3 tag at the start of the file is still readable; the audio data isn’t
                self::assertSame('Fixture title', $value['title']);
            }
        }
    }

    public function testPlainResavesKeepTheStoredValues(): void
    {
        $asset = $this->upload('resave.jpg', Fixtures::jpeg(static::$fixtureDir . '/resave.jpg', ['Model' => 'Original']));
        // Change the file behind Craft’s back
        Fixtures::jpeg(static::$volumePath . '/resave.jpg', ['Model' => 'Changed']);
        $before = self::$extractions;

        self::assertTrue(Craft::$app->getElements()->saveElement($asset));

        self::assertSame('Original', $this->value($this->reload($asset))['camera']);
        self::assertSame($before, self::$extractions, 'No extraction without “Refresh on element save”');

        // An explicit refresh (what the “Refresh” button does) sees the new file
        $fresh = Plugin::getInstance()->getMetadata()->getFieldValue(static::$field, $asset);
        self::assertSame('Changed', $fresh['col1']);
    }

    public function testReplacingTheFileRefreshesTheMetadata(): void
    {
        $asset = $this->upload('replace.jpg', Fixtures::jpeg(static::$fixtureDir . '/replace.jpg', ['Model' => 'Before']));
        $replacement = Fixtures::jpeg(static::$fixtureDir . '/replacement.jpg', ['Model' => 'After']);

        Craft::$app->getAssets()->replaceAssetFile($asset, $replacement, 'replace.jpg');

        self::assertSame('After', $this->value($this->reload($asset))['camera']);
    }

    public function testRefreshOnSaveReExtractsEveryAssetOfABulkResave(): void
    {
        $a = $this->upload('bulk-a.jpg', Fixtures::jpeg(static::$fixtureDir . '/bulk-a.jpg', ['Model' => 'A1']), self::$refreshVolume);
        $b = $this->upload('bulk-b.jpg', Fixtures::jpeg(static::$fixtureDir . '/bulk-b.jpg', ['Model' => 'B1']), self::$refreshVolume);
        Fixtures::jpeg(self::$refreshVolumePath . '/bulk-a.jpg', ['Model' => 'A2']);
        Fixtures::jpeg(self::$refreshVolumePath . '/bulk-b.jpg', ['Model' => 'B2']);
        $before = self::$extractions;

        foreach ([$a, $b] as $asset) {
            self::assertTrue(Craft::$app->getElements()->saveElement($this->reload($asset)));
        }

        self::assertSame('A2', $this->value($this->reload($a), self::$refreshField)['camera']);
        self::assertSame('B2', $this->value($this->reload($b), self::$refreshField)['camera']);
        self::assertSame(2, self::$extractions - $before);
    }

    public function testAMissingFileKeepsTheStoredMetadata(): void
    {
        $asset = $this->upload('missing.jpg', Fixtures::jpeg(static::$fixtureDir . '/missing.jpg', ['Model' => 'Keep me']), self::$refreshVolume);
        unlink(self::$refreshVolumePath . '/missing.jpg');
        $before = self::$extractions;

        self::assertTrue(Craft::$app->getElements()->saveElement($this->reload($asset)));

        self::assertSame('Keep me', $this->value($this->reload($asset), self::$refreshField)['camera']);
        self::assertSame($before, self::$extractions, 'Nothing to extract from a missing file');
    }

    public function testOversizedValuesAreLimitedByTheSetting(): void
    {
        $this->settings()->maxValueLength = 12;
        $asset = $this->upload('long.jpg', Fixtures::jpeg(static::$fixtureDir . '/long.jpg', ['Model' => 'A very long camera model name']));

        self::assertSame('A very long ', $this->value($asset)['camera']);
    }

    public function testHugeTemplateOutputDoesNotBreakTheSave(): void
    {
        $this->settings()->maxValueLength = null;
        self::$hugeField = CraftFixtures::ensureField('AM Huge ' . static::$suffix, 'amHuge' . static::$suffix, [
            'col1' => ['name' => 'Huge', 'handle' => 'huge', 'template' => "{{ range(1, 20000)|join('') }}"],
            'col2' => ['name' => 'Type', 'handle' => 'type', 'template' => '{{ metadata.mime_type }}'],
        ]);
        CraftFixtures::attachFieldToVolume(self::$hugeField, self::$refreshVolume);

        $asset = $this->upload('huge.jpg', Fixtures::jpeg(static::$fixtureDir . '/huge.jpg'), self::$refreshVolume);
        $value = $this->value($asset, self::$hugeField);
        $serialized = Json::encode(self::$hugeField->serializeValue($value, $asset));

        self::assertSame('image/jpeg', $value['type']);

        if (Plugin::usesContentTable()) {
            // Craft 4: the value must fit the `text` content column
            self::assertLessThanOrEqual(65535, strlen($serialized));
            self::assertGreaterThan(10000, strlen($value['huge']), 'Only trimmed as much as necessary');
        } else {
            // Craft 5: JSON content has no such limit
            self::assertGreaterThan(65535, strlen($serialized));
        }
    }

    public function testGraphQlTypeExposesTheSubfields(): void
    {
        $type = static::$field->getContentGqlType();

        self::assertInstanceOf(ObjectType::class, $type);
        $fields = $type->getFields();
        self::assertArrayHasKey('camera', $fields);
        self::assertArrayHasKey('location', $fields);
    }

    public function testInvalidSubfieldHandlesAreRejected(): void
    {
        $field = new AssetMetadata([
            'name' => 'AM Invalid ' . static::$suffix,
            'handle' => 'amInvalid' . static::$suffix,
            'subfields' => ['col1' => ['name' => 'Bad', 'handle' => 'bad handle', 'template' => '']],
        ]);

        if (method_exists(Craft::$app->getFields(), 'getAllGroups')) {
            $field->groupId = Craft::$app->getFields()->getAllGroups()[0]->id;
        }

        self::assertFalse(Craft::$app->getFields()->saveField($field));
        self::assertNotEmpty($field->getErrors('subfields'));
    }

    public function testTheConsoleCommandPrintsExtractedMetadata(): void
    {
        // An MP3 rather than an image: Craft re-encodes uploaded images (and drops their EXIF data)
        // unless `preserveExifData` is enabled, and the console command reads the stored file.
        $asset = $this->upload('console.mp3', Fixtures::mp3(static::$fixtureDir . '/console.mp3', ['TIT2' => 'Console title']));
        // The harness runs as root, which the Craft CLI refuses without this variable
        $craft = 'CRAFT_ALLOW_SUPERUSER=1 php ' . escapeshellarg(CRAFT_BASE_PATH . '/craft');

        $output = (string)shell_exec("$craft asset-metadata/extract $asset->id --key=comments.title.0 2>&1");
        self::assertStringContainsString('Console title', $output);

        $output = (string)shell_exec("$craft asset-metadata/extract $asset->id 2>&1");
        self::assertStringContainsString('[fileformat] => mp3', $output);
        self::assertStringContainsString('[title] => Array', $output);

        $output = (string)shell_exec("$craft asset-metadata/extract 999999999 2>&1; echo \"exit:$?\"");
        self::assertStringContainsString('No asset exists', $output);
        self::assertStringNotContainsString('exit:0', $output);
    }
}
