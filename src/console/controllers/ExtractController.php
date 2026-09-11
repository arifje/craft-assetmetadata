<?php

namespace carlcs\assetmetadata\console\controllers;

use carlcs\assetmetadata\errors\MetadataException;
use carlcs\assetmetadata\Plugin;
use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Displays the metadata getID3 extracts from an asset’s file.
 *
 * Usage: `php craft asset-metadata/extract <assetId> [--key=jpg.exif.EXIF]`
 */
class ExtractController extends Controller
{
    // Properties
    // =========================================================================

    /**
     * @var string|null A dot-notation key to pluck from the metadata array, e.g. `jpg.exif.EXIF.Model`
     */
    public ?string $key = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'key';

        return $options;
    }

    /**
     * Extracts and displays the metadata of an asset.
     *
     * @param int $elementId The asset’s element ID
     */
    public function actionIndex(int $elementId): int
    {
        $asset = Craft::$app->getElements()->getElementById($elementId, Asset::class);

        if (!$asset instanceof Asset) {
            $this->stderr("No asset exists with the ID {$elementId}." . PHP_EOL, Console::FG_RED);
            return ExitCode::USAGE;
        }

        try {
            $metadata = Plugin::getInstance()->getMetadata()->extract($asset, $this->key);
        } catch (MetadataException $e) {
            $this->stderr("Could not extract the metadata: {$e->getMessage()}" . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(print_r($metadata, true) . PHP_EOL);

        return ExitCode::OK;
    }
}
