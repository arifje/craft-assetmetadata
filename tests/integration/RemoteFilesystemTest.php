<?php

declare(strict_types=1);

namespace carlcs\assetmetadata\tests\integration;

use carlcs\assetmetadata\tests\support\CraftFixtures;
use carlcs\assetmetadata\tests\support\FakeRemoteFs;
use carlcs\assetmetadata\tests\support\Fixtures;
use Craft;
use craft\elements\Asset;
use craft\models\Volume;

/**
 * Metadata extraction for assets on a non-local filesystem: partial downloads, faked file sizes,
 * download failures and temporary-file cleanup.
 */
final class RemoteFilesystemTest extends IntegrationTestCase
{
    /** 4000 frames ≈ 104.5 seconds of audio, ~1.6 MB */
    private const FRAMES = 4000;
    private const FULL_PLAYTIME = '1:44';

    private static ?Volume $remoteVolume = null;
    private static string $remotePath = '';
    private static ?Asset $song = null;

    protected static function fieldConfig(): array
    {
        return ['refreshOnElementSave' => true];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$remotePath = Craft::getAlias('@storage') . '/asset-metadata-tests/remote' . static::$suffix;
        $fs = new FakeRemoteFs([
            'name' => 'AM Remote FS ' . static::$suffix,
            'handle' => 'amRemote' . static::$suffix,
            'path' => self::$remotePath,
        ]);
        self::$remoteVolume = CraftFixtures::ensureVolume('AM Remote ' . static::$suffix, 'amRemote' . static::$suffix, $fs);
        CraftFixtures::attachFieldToVolume(static::$field, self::$remoteVolume);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$remoteVolume !== null) {
            CraftFixtures::removeVolume(self::$remoteVolume, self::$remotePath);
            self::$remoteVolume = null;
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        FakeRemoteFs::$failStreams = false;
        FakeRemoteFs::$streamCount = 0;

        if (self::$song === null) {
            $path = Fixtures::mp3(static::$fixtureDir . '/remote-song.mp3', ['TIT2' => 'Remote song'], self::FRAMES);
            self::$song = $this->upload('remote-song.mp3', $path, self::$remoteVolume);
            self::assertFalse(self::$song->getVolume()->getFs() instanceof \craft\base\LocalFsInterface);
        }
    }

    protected function tearDown(): void
    {
        FakeRemoteFs::$failStreams = false;
        parent::tearDown();
    }

    public function testAChunkedDownloadWithAFakedFileSizeYieldsTheFullPlaytime(): void
    {
        $this->settings()->downloadChunkSize = 256 * 1024;
        $this->settings()->fakeCompleteFileSize = [Asset::KIND_AUDIO];

        $this->resave();

        self::assertSame(self::FULL_PLAYTIME, $this->value($this->reload(self::$song))['duration']);
        self::assertSame('Remote song', $this->value($this->reload(self::$song))['title']);
        self::assertSame(1, FakeRemoteFs::$streamCount, 'The file is streamed exactly once');
        self::assertSame([], $this->pluginTempFiles(), 'Temporary files are removed');
    }

    public function testWithoutAFakedFileSizeOnlyTheDownloadedChunkIsAnalyzed(): void
    {
        $this->settings()->downloadChunkSize = 256 * 1024;
        $this->settings()->fakeCompleteFileSize = false;

        $this->resave();

        $duration = $this->value($this->reload(self::$song))['duration'];
        self::assertNotSame(self::FULL_PLAYTIME, $duration);
        self::assertMatchesRegularExpression('/^0:1\d$/', $duration, '256 KB at 128 kbps is about 16 seconds');
        self::assertSame([], $this->pluginTempFiles());
    }

    public function testDisablingChunkedDownloadsFetchesTheCompleteFile(): void
    {
        $this->settings()->downloadChunkSize = false;
        $tempFilesBefore = glob(Craft::$app->getPath()->getTempPath() . '/*') ?: [];

        $this->resave();

        self::assertSame(self::FULL_PLAYTIME, $this->value($this->reload(self::$song))['duration']);
        self::assertSame(1, FakeRemoteFs::$streamCount);
        self::assertSame($tempFilesBefore, glob(Craft::$app->getPath()->getTempPath() . '/*') ?: [], 'The complete temporary copy is removed');
    }

    public function testADownloadFailureKeepsTheStoredValueAndLeavesNoTempFiles(): void
    {
        $this->settings()->downloadChunkSize = 256 * 1024;
        $this->settings()->fakeCompleteFileSize = [Asset::KIND_AUDIO];
        $this->resave();
        self::assertSame(self::FULL_PLAYTIME, $this->value($this->reload(self::$song))['duration']);

        FakeRemoteFs::$failStreams = true;
        $before = self::$extractions;

        $this->resave();

        self::assertSame(self::FULL_PLAYTIME, $this->value($this->reload(self::$song))['duration']);
        self::assertSame($before, self::$extractions);
        self::assertSame([], $this->pluginTempFiles());
    }

    public function testRenderingIndexRowsNeverDownloadsFiles(): void
    {
        $before = self::$extractions;

        $html = $this->attributeHtml($this->reload(self::$song), $this->attributeKey('title'));

        self::assertSame('Remote song', $html);
        self::assertSame(0, FakeRemoteFs::$streamCount);
        self::assertSame($before, self::$extractions);
    }

    private function resave(): void
    {
        self::assertTrue(Craft::$app->getElements()->saveElement($this->reload(self::$song)));
    }
}
