<?php

namespace carlcs\assetmetadata\services;

use carlcs\assetmetadata\errors\MetadataException;
use carlcs\assetmetadata\events\MetadataEvent;
use carlcs\assetmetadata\fields\AssetMetadata;
use carlcs\assetmetadata\helpers\ArrayHelper;
use carlcs\assetmetadata\Plugin;
use carlcs\assetmetadata\Settings;
use Craft;
use craft\base\Component;
use craft\base\LocalFsInterface;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\web\View;
use getID3;
use getid3_lib;
use Throwable;

/**
 * Metadata service.
 *
 * Extracts metadata from asset files with getID3 and renders the subfield templates of Asset Metadata fields.
 */
class Metadata extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event MetadataEvent The event that is triggered after metadata was extracted from an asset’s file.
     */
    public const EVENT_AFTER_EXTRACT = 'afterExtract';

    // Properties
    // =========================================================================

    private ?getID3 $_getId3 = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the value for an Asset Metadata field, rendered from the asset’s freshly extracted metadata.
     *
     * Returns `null` when the asset’s file couldn’t be analyzed at all (missing file, failed download, …)
     * so callers can keep the value that is already stored for the asset.
     *
     * @return array<int|string, string>|null Subfield values keyed by subfield ID
     */
    public function getFieldValue(AssetMetadata $field, Asset $asset): ?array
    {
        try {
            $metadata = $this->extract($asset, null, $field);
        } catch (Throwable $e) {
            Craft::warning(sprintf(
                'Could not extract metadata from asset %s: %s',
                $this->describeAsset($asset),
                $e->getMessage()
            ), __METHOD__);

            return null;
        }

        return $this->renderSubfields($field, $asset, is_array($metadata) ? $metadata : []);
    }

    /**
     * Renders the subfield templates of a field against the given metadata.
     *
     * Template errors (for example a missing EXIF tag passed to a filter) only affect the subfield in
     * question, which is stored as an empty string.
     *
     * @return array<int|string, string> Subfield values keyed by subfield ID
     */
    public function renderSubfields(AssetMetadata $field, Asset $asset, array $metadata): array
    {
        $value = [];

        foreach ($field->getSubfields() as $id => $subfield) {
            $label = "$field->handle:{$subfield['handle']}";

            try {
                $rendered = $this->renderTemplate($subfield['template'], [
                    'object' => $asset,
                    'metadata' => $metadata,
                ]);
            } catch (Throwable $e) {
                Craft::warning(sprintf(
                    'Error rendering the “%s” subfield template for asset %s: %s',
                    $label,
                    $this->describeAsset($asset),
                    $e->getMessage()
                ), __METHOD__);
                $rendered = '';
            }

            $value[$id] = $this->sanitizeValue($rendered, $label);
        }

        return $value;
    }

    /**
     * Extracts the metadata from an asset’s file.
     *
     * Files on local filesystems are read in place; files on other filesystems are downloaded to a
     * temporary file (completely or partially, see the `downloadChunkSize` setting) that is deleted
     * again afterwards.
     *
     * @param Asset $asset The asset
     * @param string|null $key Optional dot-notation key to pluck from the metadata array
     * @param AssetMetadata|null $field The field the metadata is extracted for, if any
     * @return mixed The metadata array, or the value at `$key`
     * @throws MetadataException if the asset’s file can’t be accessed
     */
    public function extract(Asset $asset, ?string $key = null, ?AssetMetadata $field = null): mixed
    {
        $tempPath = null;

        try {
            [$path, $tempPath] = $this->resolveFilePath($asset);
            $metadata = $this->analyzeFile($path, $this->assetFilename($asset));
        } catch (MetadataException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MetadataException($e->getMessage(), 0, $e);
        } finally {
            if ($tempPath !== null) {
                FileHelper::unlink($tempPath);
            }
        }

        $event = new MetadataEvent([
            'metadata' => $metadata,
            'asset' => $asset,
            'field' => $field,
        ]);
        $this->trigger(self::EVENT_AFTER_EXTRACT, $event);
        $metadata = $event->metadata;

        if ($key !== null) {
            return ArrayHelper::getValueByKey($key, $metadata);
        }

        return $metadata;
    }

    /**
     * Analyzes a local file with getID3 and returns its metadata, with all tag formats merged into `comments`.
     *
     * getID3 errors (for example an unsupported file format) don’t throw; they are logged and returned in
     * the `error` key of the metadata array, like getID3 does itself.
     *
     * @param string $path Path to a local file
     * @param string|null $originalFilename The file’s original name, if `$path` is a temporary copy
     * @param int|null $fileSize The size to report to getID3 instead of the actual file size
     * @throws MetadataException if the file doesn’t exist or isn’t readable
     */
    public function analyzeFile(string $path, ?string $originalFilename = null, ?int $fileSize = null): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new MetadataException("The file “{$path}” doesn’t exist or isn’t readable.");
        }

        $metadata = $this->getGetId3()->analyze($path, $fileSize, $originalFilename ?? '');

        if (!is_array($metadata)) {
            $metadata = [];
        }

        $name = $originalFilename ?? basename($path);

        if (!empty($metadata['error'])) {
            Craft::warning("getID3 couldn’t fully analyze “{$name}”: " . $this->implodeMessages($metadata['error']), __METHOD__);
        }

        if (!empty($metadata['warning'])) {
            Craft::info("getID3 warnings for “{$name}”: " . $this->implodeMessages($metadata['warning']), __METHOD__);
        }

        // Merges all available tags into one array
        // @see https://github.com/JamesHeinrich/getID3/blob/master/structure.txt
        getid3_lib::CopyTagsToComments($metadata);

        return $metadata;
    }

    // Protected Methods
    // =========================================================================

    /**
     * Resolves a local path to the asset’s file.
     *
     * @return array{0: string, 1: string|null} The path, and the temporary file to delete afterwards (if any)
     * @throws MetadataException
     */
    protected function resolveFilePath(Asset $asset): array
    {
        // A file that was just uploaded (or is replacing the current file) still lives in the temp folder
        if ($asset->tempFilePath !== null) {
            if (!is_file($asset->tempFilePath)) {
                throw new MetadataException("The uploaded file “{$asset->tempFilePath}” could not be found.");
            }

            return [$asset->tempFilePath, null];
        }

        $volume = $asset->getVolume();
        $fs = $volume->getFs();

        if ($fs instanceof LocalFsInterface) {
            $path = FileHelper::normalizePath($fs->getRootPath() . DIRECTORY_SEPARATOR . $asset->getPath());

            if (!is_file($path)) {
                throw new MetadataException(sprintf('The file “%s” doesn’t exist in the “%s” volume.', $asset->getPath(), $volume->name));
            }

            return [$path, null];
        }

        $tempPath = $this->getTempCopyOfFile($asset);

        return [$tempPath, $tempPath];
    }

    /**
     * Downloads an asset’s file from a remote filesystem to a temporary file, completely or partially
     * depending on the `downloadChunkSize` setting, and returns the temporary file’s path.
     *
     * @throws MetadataException
     */
    protected function getTempCopyOfFile(Asset $asset): string
    {
        $settings = $this->settings();
        $chunkSize = $settings->downloadChunkSize;
        $fileSize = $asset->size;

        if ($chunkSize === false || $chunkSize <= 0 || $fileSize === null || $fileSize <= $chunkSize) {
            // Download the complete file (Craft puts it in storage/runtime/temp/)
            return $asset->getCopyOfFile();
        }

        $tempPath = $this->createTempPath($asset);
        $tempStream = @fopen($tempPath, 'wb');

        if ($tempStream === false) {
            throw new MetadataException("Could not create the temporary file “{$tempPath}”.");
        }

        $assetStream = null;

        try {
            if ($this->shouldFakeCompleteFileSize($asset)) {
                // Make sure the temp file is the same size as the asset’s file
                fseek($tempStream, $fileSize - 1);
                fwrite($tempStream, "\0");
                fseek($tempStream, 0);
            }

            $assetStream = $asset->getStream();

            if (!is_resource($assetStream)) {
                throw new MetadataException('Could not open a stream to the asset’s file.');
            }

            if (stream_copy_to_stream($assetStream, $tempStream, $chunkSize) === false) {
                throw new MetadataException('Could not download the asset’s file.');
            }
        } catch (Throwable $e) {
            if (is_resource($assetStream)) {
                fclose($assetStream);
            }

            fclose($tempStream);
            FileHelper::unlink($tempPath);

            throw $e instanceof MetadataException ? $e : new MetadataException($e->getMessage(), 0, $e);
        }

        fclose($assetStream);
        fclose($tempStream);

        return $tempPath;
    }

    /**
     * Renders a subfield template.
     *
     * Subfield templates are defined by administrators in the field settings, so they are rendered as
     * regular (site) templates, without autoescaping.
     */
    protected function renderTemplate(string $template, array $variables): string
    {
        $twig = '{% autoescape false %}' . $template . '{% endautoescape %}';

        return Craft::$app->getView()->renderString($twig, $variables, View::TEMPLATE_MODE_SITE);
    }

    /**
     * Makes sure a rendered subfield value is valid UTF-8, contains no NUL bytes and doesn’t exceed the
     * `maxValueLength` setting.
     */
    protected function sanitizeValue(string $value, string $label): string
    {
        if (!StringHelper::isUtf8($value)) {
            $value = StringHelper::convertToUtf8($value);

            if (!StringHelper::isUtf8($value)) {
                $value = (string)mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
        }

        $value = str_replace("\0", '', $value);
        $maxLength = $this->settings()->maxValueLength;

        if ($maxLength !== null && $maxLength >= 0 && mb_strlen($value) > $maxLength) {
            Craft::warning("The “{$label}” subfield value was truncated to {$maxLength} characters.", __METHOD__);
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    /**
     * Returns whether the complete file size should be faked for a partially downloaded file.
     */
    protected function shouldFakeCompleteFileSize(Asset $asset): bool
    {
        $fakeCompleteFileSize = $this->settings()->fakeCompleteFileSize;

        if (is_bool($fakeCompleteFileSize)) {
            return $fakeCompleteFileSize;
        }

        return in_array($asset->kind, $fakeCompleteFileSize, true);
    }

    /**
     * Returns a configured getID3 instance.
     */
    protected function getGetId3(): getID3
    {
        if ($this->_getId3 === null) {
            $this->_getId3 = new getID3();

            foreach ($this->settings()->getId3 as $option => $value) {
                if (is_string($option) && property_exists($this->_getId3, $option)) {
                    $this->_getId3->{$option} = $value;
                } else {
                    Craft::warning("Ignoring unknown getID3 option “{$option}” in the asset-metadata config.", __METHOD__);
                }
            }
        }

        return $this->_getId3;
    }

    /**
     * Returns the plugin settings.
     */
    protected function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return $settings;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a unique, safe temporary file path for a (partial) copy of the asset’s file.
     */
    private function createTempPath(Asset $asset): string
    {
        $extension = preg_replace('/[^a-zA-Z0-9]/', '', $this->assetFilename($asset) !== null ? $asset->getExtension() : '');
        $name = 'assetmetadata-' . str_replace('.', '', uniqid('', true));

        return Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . $name . ($extension !== '' ? ".$extension" : '');
    }

    private function assetFilename(Asset $asset): ?string
    {
        try {
            $filename = $asset->getFilename();
        } catch (Throwable) {
            return null;
        }

        return $filename !== '' ? $filename : null;
    }

    private function describeAsset(Asset $asset): string
    {
        $filename = $this->assetFilename($asset);

        if ($asset->id !== null) {
            return $filename !== null ? "“{$filename}” (ID {$asset->id})" : "ID {$asset->id}";
        }

        return $filename !== null ? "“{$filename}”" : '(new asset)';
    }

    /**
     * @param mixed $messages getID3 `error`/`warning` entries
     */
    private function implodeMessages(mixed $messages): string
    {
        $strings = [];

        foreach ((array)$messages as $message) {
            $strings[] = is_scalar($message) ? (string)$message : StringHelper::toString($message, ', ');
        }

        return implode('; ', $strings);
    }
}
