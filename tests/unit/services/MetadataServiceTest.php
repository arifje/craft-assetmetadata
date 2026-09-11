<?php

declare(strict_types=1);

namespace carlcs\assetmetadata\tests\unit\services;

use carlcs\assetmetadata\errors\MetadataException;
use carlcs\assetmetadata\events\MetadataEvent;
use carlcs\assetmetadata\fields\AssetMetadata;
use carlcs\assetmetadata\services\Metadata;
use carlcs\assetmetadata\tests\support\Fixtures;
use carlcs\assetmetadata\tests\support\TestMetadataService;
use craft\elements\Asset;
use PHPUnit\Framework\TestCase;
use yii\base\Event;

final class MetadataServiceTest extends TestCase
{
    private string $dir;
    private TestMetadataService $service;

    protected function setUp(): void
    {
        $this->dir = Fixtures::tempDir();
        $this->service = new TestMetadataService();
    }

    protected function tearDown(): void
    {
        Fixtures::removeDir($this->dir);
        Event::offAll();
    }

    // analyzeFile(): formats, malformed and unsupported files
    // =========================================================================

    public function testAnalyzesJpegExifIncludingGpsOrientationAndDates(): void
    {
        $path = Fixtures::jpeg("$this->dir/photo.jpg", [
            'Make' => 'Canon',
            'Model' => 'EOS R5',
            'Orientation' => 6,
            'DateTimeOriginal' => '2024:05:06 07:08:09',
            'ExposureTime' => [1, 200],
            'FNumber' => [28, 10],
            'ISOSpeedRatings' => 400,
            'gps' => ['lat' => Fixtures::GPS_LAT, 'lon' => Fixtures::GPS_LON],
        ]);

        $metadata = $this->service->analyzeFile($path);

        self::assertSame('jpg', $metadata['fileformat']);
        self::assertSame('image/jpeg', $metadata['mime_type']);
        self::assertSame(64, $metadata['video']['resolution_x']);
        self::assertSame('Canon', $metadata['jpg']['exif']['IFD0']['Make']);
        self::assertSame('EOS R5', $metadata['jpg']['exif']['IFD0']['Model']);
        self::assertSame(6, $metadata['jpg']['exif']['IFD0']['Orientation']);
        self::assertSame('2024:05:06 07:08:09', $metadata['jpg']['exif']['EXIF']['DateTimeOriginal']);
        // getID3 converts EXIF rationals to decimals
        self::assertEqualsWithDelta(0.005, $metadata['jpg']['exif']['EXIF']['ExposureTime'], 0.0001);
        self::assertSame(400, $metadata['jpg']['exif']['EXIF']['ISOSpeedRatings']);
        self::assertSame('N', $metadata['jpg']['exif']['GPS']['GPSLatitudeRef']);
        self::assertSame(['52/1', '22/1', '4508/100'], $metadata['jpg']['exif']['GPS']['GPSLatitude']);
        self::assertEqualsWithDelta(Fixtures::GPS_LAT, $metadata['jpg']['exif']['GPS']['computed']['latitude'], 0.0001);
        self::assertEqualsWithDelta(Fixtures::GPS_LON, $metadata['jpg']['exif']['GPS']['computed']['longitude'], 0.0001);
        self::assertArrayNotHasKey('error', $metadata);
    }

    public function testAnalyzesMp3TagsAndPlaytime(): void
    {
        $path = Fixtures::mp3("$this->dir/song.mp3", ['TIT2' => 'Title <b>bold</b> €', 'TPE1' => 'Artist'], 100, ['title' => "Caf\xE9 v1", 'artist' => 'V1 artist']);

        $metadata = $this->service->analyzeFile($path);

        self::assertSame('mp3', $metadata['fileformat']);
        self::assertSame('audio/mpeg', $metadata['mime_type']);
        // All tag formats are merged into `comments`, ID3v2 first
        self::assertSame('Title <b>bold</b> €', $metadata['comments']['title'][0]);
        self::assertSame('Artist', $metadata['comments']['artist'][0]);
        // ID3v1 tags are ISO-8859-1 and get converted to UTF-8
        self::assertContains('Café v1', $metadata['comments']['title']);
        self::assertContains('Café v1', $metadata['tags']['id3v1']['title']);
        // 100 frames of 1152 samples at 44.1 kHz (getID3 rounds the playtime string up)
        self::assertEqualsWithDelta(2.6, $metadata['playtime_seconds'], 0.5);
        self::assertSame('0:03', $metadata['playtime_string']);
    }

    public function testAnalyzesPng(): void
    {
        $metadata = $this->service->analyzeFile(Fixtures::png("$this->dir/logo.png", 32, 20));

        self::assertSame('png', $metadata['fileformat']);
        self::assertSame(32, $metadata['video']['resolution_x']);
        self::assertSame(20, $metadata['video']['resolution_y']);
    }

    /**
     * @dataProvider unsupportedFileProvider
     */
    public function testUnsupportedOrCorruptFilesReportErrorsInsteadOfThrowing(callable $factory): void
    {
        $path = $factory($this->dir);

        $metadata = $this->service->analyzeFile($path);

        self::assertIsArray($metadata);
        self::assertArrayHasKey('filesize', $metadata);
        self::assertTrue(isset($metadata['error']) || isset($metadata['warning']), 'getID3 should report a problem');
    }

    /**
     * @return iterable<string, array{0: callable}>
     */
    public static function unsupportedFileProvider(): iterable
    {
        yield 'plain text' => [static fn(string $dir) => Fixtures::text("$dir/notes.txt")];
        yield 'random bytes with a jpg extension' => [static fn(string $dir) => Fixtures::garbage("$dir/garbage.jpg")];
        yield 'empty file' => [static fn(string $dir) => Fixtures::text("$dir/empty.jpg", '')];
        yield 'truncated jpeg' => [static function(string $dir) {
            $full = Fixtures::jpeg("$dir/full.jpg", ['Make' => 'Canon']);
            return Fixtures::truncated($full, "$dir/truncated.jpg", 120);
        }];
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(MetadataException::class);
        $this->service->analyzeFile("$this->dir/does-not-exist.jpg");
    }

    public function testOriginalFilenameIsPassedToGetId3(): void
    {
        $path = Fixtures::jpeg("$this->dir/tmpcopy");

        $metadata = $this->service->analyzeFile($path, 'original.jpg');

        self::assertSame('jpg', $metadata['fileformat']);
    }

    public function testGetId3OptionsFromSettingsAreApplied(): void
    {
        $this->service->testSettings->getId3 = ['option_tags_html' => true, 'not_a_real_option' => true];

        $metadata = $this->service->analyzeFile(Fixtures::mp3("$this->dir/song.mp3"));

        self::assertArrayHasKey('comments_html', $metadata);
    }

    // renderSubfields(): templates, errors, encodings, sizes
    // =========================================================================

    public function testRendersSubfieldTemplatesKeyedBySubfieldId(): void
    {
        $field = $this->field([
            'col1' => ['name' => 'Camera', 'handle' => 'camera', 'template' => '{{ metadata.jpg.exif.IFD0.Model }}'],
            'col2' => ['name' => 'Taken', 'handle' => 'taken', 'template' => "{{ metadata.jpg.exif.EXIF.DateTimeOriginal|convertExifDate|date('Y-m-d') }}"],
            'col3' => ['name' => 'Location', 'handle' => 'location', 'template' => '{{ metadata.jpg.exif.GPS|convertExifGpsCoordinates }}'],
            'col4' => ['name' => 'Static', 'handle' => 'static', 'template' => 'fixed value'],
            'col5' => ['name' => 'Empty', 'handle' => 'empty', 'template' => ''],
        ]);
        $metadata = $this->service->analyzeFile(Fixtures::jpeg("$this->dir/photo.jpg", [
            'Model' => 'EOS R5',
            'DateTimeOriginal' => '2024:05:06 07:08:09',
            'gps' => ['lat' => Fixtures::GPS_LAT, 'lon' => Fixtures::GPS_LON],
        ]));

        $value = $this->service->renderSubfields($field, $this->asset(), $metadata);

        self::assertSame([
            'col1' => 'EOS R5',
            'col2' => '2024-05-06',
            'col3' => '52°22\'45.1"N 4°53\'58.0"E',
            'col4' => 'fixed value',
            'col5' => '',
        ], $value);
    }

    public function testTemplateErrorsOnlyAffectTheSubfieldInQuestion(): void
    {
        $field = $this->field([
            'col1' => ['name' => 'Taken', 'handle' => 'taken', 'template' => "{{ metadata.jpg.exif.EXIF.DateTimeOriginal|convertExifDate|date('Y-m-d') }}"],
            'col2' => ['name' => 'Broken', 'handle' => 'broken', 'template' => '{{ this is not twig'],
            'col3' => ['name' => 'Type', 'handle' => 'type', 'template' => '{{ metadata.mime_type }}'],
        ]);
        // No EXIF at all: the date filter throws, which used to abort the whole element save with a TypeError
        $metadata = $this->service->analyzeFile(Fixtures::jpeg("$this->dir/plain.jpg"));

        $value = $this->service->renderSubfields($field, $this->asset(), $metadata);

        self::assertSame(['col1' => '', 'col2' => '', 'col3' => 'image/jpeg'], $value);
    }

    public function testRenderedValuesAreValidUtf8WithoutNulBytes(): void
    {
        $field = $this->field([
            'col1' => ['name' => 'Model', 'handle' => 'model', 'template' => '{{ metadata.jpg.exif.IFD0.Model }}'],
            'col2' => ['name' => 'Nul', 'handle' => 'nul', 'template' => "before\x00after"],
        ]);
        // A Latin-1 byte straight from the file (EXIF strings have no declared encoding)
        $metadata = $this->service->analyzeFile(Fixtures::jpeg("$this->dir/latin1.jpg", ['Model' => "Caf\xE9"]));

        $value = $this->service->renderSubfields($field, $this->asset(), $metadata);

        self::assertTrue(mb_check_encoding($value['col1'], 'UTF-8'));
        self::assertStringStartsWith('Caf', $value['col1']);
        self::assertNotFalse(json_encode($value));
        self::assertSame('beforeafter', $value['col2']);
    }

    public function testOversizedValuesAreTruncatedToTheConfiguredLength(): void
    {
        $field = $this->field([
            'col1' => ['name' => 'Long', 'handle' => 'long', 'template' => "{{ range(1, 5000)|join(',') }}"],
        ]);

        $this->service->testSettings->maxValueLength = 100;
        $value = $this->service->renderSubfields($field, $this->asset(), []);
        self::assertSame(100, mb_strlen($value['col1']));

        $this->service->testSettings->maxValueLength = null;
        $value = $this->service->renderSubfields($field, $this->asset(), []);
        self::assertGreaterThan(20000, mb_strlen($value['col1']));
    }

    public function testFieldsWithoutSubfieldsRenderToAnEmptyArray(): void
    {
        self::assertSame([], $this->service->renderSubfields($this->field(null), $this->asset(), ['mime_type' => 'x']));
    }

    // extract() / getFieldValue(): file resolution and events
    // =========================================================================

    public function testExtractsFromTheTempFileOfAnAssetBeingUploaded(): void
    {
        $asset = $this->asset(['tempFilePath' => Fixtures::jpeg("$this->dir/upload.jpg", ['Make' => 'Canon'])]);

        self::assertSame('Canon', $this->service->extract($asset, 'jpg.exif.IFD0.Make'));
        self::assertNull($this->service->extract($asset, 'jpg.exif.IFD0.Nope'));
        self::assertSame('jpg', $this->service->extract($asset)['fileformat']);
    }

    public function testAfterExtractEventCanModifyTheMetadata(): void
    {
        $asset = $this->asset(['tempFilePath' => Fixtures::jpeg("$this->dir/upload.jpg")]);
        $seen = [];

        Event::on(Metadata::class, Metadata::EVENT_AFTER_EXTRACT, static function(MetadataEvent $event) use (&$seen, $asset) {
            $seen[] = $event->asset === $asset;
            $event->metadata['custom'] = 'added by listener';
        });

        $metadata = $this->service->extract($asset);

        self::assertSame([true], $seen);
        self::assertSame('added by listener', $metadata['custom']);
    }

    public function testExtractThrowsWhenTheUploadedFileIsGone(): void
    {
        $this->expectException(MetadataException::class);
        $this->service->extract($this->asset(['tempFilePath' => "$this->dir/gone.jpg"]));
    }

    public function testGetFieldValueReturnsNullWhenTheFileCannotBeAccessed(): void
    {
        $field = $this->field(['col1' => ['name' => 'Type', 'handle' => 'type', 'template' => '{{ metadata.mime_type }}']]);

        self::assertNull($this->service->getFieldValue($field, $this->asset(['tempFilePath' => "$this->dir/gone.jpg"])));
        // An asset without a temp file and without a (reachable) volume can’t be analyzed either
        self::assertNull($this->service->getFieldValue($field, $this->asset()));
    }

    public function testGetFieldValueRendersTheSubfields(): void
    {
        $field = $this->field(['col1' => ['name' => 'Type', 'handle' => 'type', 'template' => '{{ metadata.mime_type }}']]);
        $asset = $this->asset(['tempFilePath' => Fixtures::png("$this->dir/logo.png")]);

        self::assertSame(['col1' => 'image/png'], $this->service->getFieldValue($field, $asset));
    }

    // Helpers
    // =========================================================================

    private function field(?array $subfields): AssetMetadata
    {
        return new AssetMetadata(['handle' => 'metadata', 'subfields' => $subfields]);
    }

    private function asset(array $config = []): Asset
    {
        return new Asset(array_merge(['filename' => 'fixture.jpg', 'kind' => Asset::KIND_IMAGE], $config));
    }
}
