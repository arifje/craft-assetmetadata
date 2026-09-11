<?php

declare(strict_types=1);

namespace carlcs\assetmetadata\tests\support;

use carlcs\assetmetadata\fields\AssetMetadata;
use Craft;
use craft\base\FieldInterface;
use craft\base\FsInterface;
use craft\elements\Asset;
use craft\fieldlayoutelements\CustomField;
use craft\fs\Local;
use craft\models\FieldLayoutTab;
use craft\models\Volume;
use RuntimeException;

/**
 * Creates and removes disposable Craft fixtures (filesystems, volumes, fields, assets) inside a real
 * Craft installation. Works with Craft 4 and Craft 5; the few API differences are isolated here.
 *
 * Used by the Docker seed script and the integration tests. Requires a bootstrapped Craft application.
 */
final class CraftFixtures
{
    /**
     * Subfield definitions covering the metadata formats the plugin handles.
     *
     * @return array<string, array{name: string, handle: string, template: string}>
     */
    public static function demoSubfields(): array
    {
        return [
            'col1' => ['name' => 'Camera', 'handle' => 'camera', 'template' => "{{ metadata.jpg.exif.IFD0.Model ?? '' }}"],
            'col2' => ['name' => 'Date taken', 'handle' => 'taken', 'template' => "{{ metadata.jpg.exif.EXIF.DateTimeOriginal|convertExifDate|date('Y-m-d H:i:s') }}"],
            'col3' => ['name' => 'Location', 'handle' => 'location', 'template' => "{{ metadata.jpg.exif.GPS|convertExifGpsCoordinates }}"],
            'col4' => ['name' => 'Orientation', 'handle' => 'orientation', 'template' => "{{ metadata.jpg.exif.IFD0.Orientation ?? '' }}"],
            'col5' => ['name' => 'Description', 'handle' => 'description', 'template' => "{{ metadata.jpg.exif.IFD0.ImageDescription ?? '' }}"],
            'col6' => ['name' => 'Title', 'handle' => 'title', 'template' => "{{ metadata.comments.title[0] ?? '' }}"],
            'col7' => ['name' => 'Duration', 'handle' => 'duration', 'template' => "{{ metadata.playtime_string ?? '' }}"],
            'col8' => ['name' => 'MIME type', 'handle' => 'mimeType', 'template' => "{{ metadata.mime_type ?? '' }}"],
        ];
    }

    /**
     * Creates (or returns) a volume backed by a local filesystem rooted at `$path`, without public URLs.
     */
    public static function ensureLocalVolume(string $name, string $handle, string $path): Volume
    {
        $fs = Craft::$app->getFs()->getFilesystemByHandle($handle);

        if (!$fs) {
            $fs = new Local([
                'name' => "$name FS",
                'handle' => $handle,
                'path' => $path,
                'hasUrls' => false,
            ]);
        }

        return self::ensureVolume($name, $handle, $fs);
    }

    /**
     * Creates (or returns) a volume for the given filesystem, saving the filesystem first if it is new.
     */
    public static function ensureVolume(string $name, string $handle, FsInterface $fs): Volume
    {
        $fsService = Craft::$app->getFs();

        if (!$fsService->getFilesystemByHandle($fs->handle)) {
            if (!$fsService->saveFilesystem($fs)) {
                throw new RuntimeException("Could not save filesystem {$fs->handle}: " . print_r($fs->getErrors(), true));
            }

            self::saveProjectConfig();
        }

        $volumes = Craft::$app->getVolumes();

        if ($existing = $volumes->getVolumeByHandle($handle)) {
            return $existing;
        }

        $volume = new Volume([
            'name' => $name,
            'handle' => $handle,
            'fsHandle' => $fs->handle,
        ]);

        if (!$volumes->saveVolume($volume)) {
            throw new RuntimeException("Could not save volume $handle: " . print_r($volume->getErrors(), true));
        }

        self::saveProjectConfig();

        $volume = $volumes->getVolumeByHandle($handle);

        if (!$volume) {
            throw new RuntimeException("Volume $handle disappeared after saving.");
        }

        return $volume;
    }

    /**
     * Creates (or returns) an Asset Metadata field.
     *
     * @param array<string, array{name: string, handle: string, template: string}> $subfields
     * @param array<string, mixed> $config Additional field attributes (`refreshOnElementSave`, `readOnly`)
     */
    public static function ensureField(string $name, string $handle, array $subfields, array $config = []): AssetMetadata
    {
        $fieldsService = Craft::$app->getFields();
        $existing = $fieldsService->getFieldByHandle($handle);

        if ($existing instanceof AssetMetadata) {
            return $existing;
        }

        if ($existing !== null) {
            throw new RuntimeException("A field with the handle $handle exists but isn’t an Asset Metadata field.");
        }

        $field = new AssetMetadata(array_merge([
            'name' => $name,
            'handle' => $handle,
            'subfields' => $subfields,
        ], $config));

        // Craft 4 organizes fields in groups; Craft 5 dropped them
        if (method_exists($fieldsService, 'getAllGroups')) {
            $groups = $fieldsService->getAllGroups();

            if ($groups === []) {
                throw new RuntimeException('No field group exists to put the field in.');
            }

            $field->groupId = $groups[0]->id;
        }

        if (!$fieldsService->saveField($field)) {
            throw new RuntimeException("Could not save field $handle: " . print_r($field->getErrors(), true));
        }

        self::saveProjectConfig();

        $field = $fieldsService->getFieldByHandle($handle);

        if (!$field instanceof AssetMetadata) {
            throw new RuntimeException("Field $handle disappeared after saving.");
        }

        return $field;
    }

    /**
     * Adds a field to a volume’s field layout, unless it is already part of it.
     */
    public static function attachFieldToVolume(FieldInterface $field, Volume $volume): void
    {
        $layout = $volume->getFieldLayout();

        if ($layout->getFieldByHandle($field->handle) !== null) {
            return;
        }

        $tabs = $layout->getTabs();

        if ($tabs === []) {
            $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
            $tab->setElements([new CustomField($field)]);
            $tabs = [$tab];
        } else {
            $tab = $tabs[0];
            $elements = $tab->getElements();
            $elements[] = new CustomField($field);
            $tab->setElements($elements);
        }

        $layout->setTabs($tabs);
        $volume->setFieldLayout($layout);

        if (!Craft::$app->getVolumes()->saveVolume($volume)) {
            throw new RuntimeException("Could not save volume {$volume->handle}: " . print_r($volume->getErrors(), true));
        }

        self::saveProjectConfig();
    }

    /**
     * Uploads a local file as a new asset in the volume’s root folder. Returns `null` if an asset with that
     * filename already exists in the volume.
     */
    public static function addAsset(Volume $volume, string $tmpFile, string $filename): ?Asset
    {
        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);

        if (!$folder) {
            throw new RuntimeException("Volume {$volume->handle} has no root folder.");
        }

        if (Asset::find()->volumeId($volume->id)->filename($filename)->one()) {
            return null;
        }

        $asset = new Asset();
        $asset->tempFilePath = $tmpFile;
        $asset->filename = $filename;
        $asset->newFolderId = $folder->id;
        $asset->setVolumeId($volume->id);
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new RuntimeException("Could not save asset $filename: " . implode('; ', $asset->getFirstErrors()));
        }

        return $asset;
    }

    /**
     * Deletes a volume with all of its assets, its filesystem, and the filesystem’s directory (local only).
     */
    public static function removeVolume(Volume $volume, ?string $localPath = null): void
    {
        foreach (Asset::find()->volumeId($volume->id)->status(null)->all() as $asset) {
            Craft::$app->getElements()->deleteElement($asset, true);
        }

        $fs = $volume->getFs();
        Craft::$app->getVolumes()->deleteVolume($volume);
        Craft::$app->getFs()->removeFilesystem($fs);
        self::saveProjectConfig();

        if ($localPath !== null) {
            Fixtures::removeDir($localPath);
        }
    }

    /**
     * Deletes a field.
     */
    public static function removeField(FieldInterface $field): void
    {
        Craft::$app->getFields()->deleteField($field);
        self::saveProjectConfig();
    }

    /**
     * Removes fixtures a crashed or interrupted test run may have left behind (all handles the
     * integration tests create start with `am`).
     */
    public static function removeLeftovers(): void
    {
        $removed = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if (preg_match('/^am(Test|Refresh|Remote)[0-9a-f]{8}$/', $volume->handle)) {
                $fs = $volume->getFs();
                $path = $fs instanceof Local ? $fs->getRootPath() : ($fs->path ?? null);
                self::removeVolume($volume, is_string($path) ? $path : null);
                $removed[] = "volume {$volume->handle}";
            }
        }

        foreach (Craft::$app->getFs()->getAllFilesystems() as $fs) {
            if (preg_match('/^am(Test|Refresh|Remote)[0-9a-f]{8}$/', (string)$fs->handle)) {
                Craft::$app->getFs()->removeFilesystem($fs);
                $removed[] = "filesystem {$fs->handle}";
            }
        }

        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if (preg_match('/^am(Field|RefreshField|Huge|Invalid)[0-9a-f]{8}$/', (string)$field->handle)) {
                Craft::$app->getFields()->deleteField($field);
                $removed[] = "field {$field->handle}";
            }
        }

        foreach (\craft\elements\User::find()->status(null)->all() as $user) {
            // Only the users the tests create (never touch anything else)
            if (preg_match('/^amviewer[0-9a-f]{8}$/', (string)$user->username)) {
                Craft::$app->getElements()->deleteElement($user, true);
                $removed[] = "user {$user->username}";
            }
        }

        if ($removed !== []) {
            self::saveProjectConfig();
            fwrite(STDOUT, 'Removed leftovers from a previous run: ' . implode(', ', $removed) . PHP_EOL);
        }
    }

    /**
     * Persists pending project config changes (there is no request lifecycle to do it for us).
     */
    public static function saveProjectConfig(): void
    {
        Craft::$app->getProjectConfig()->saveModifiedConfigData();
    }
}
