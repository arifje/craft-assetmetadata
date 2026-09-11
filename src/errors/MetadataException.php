<?php

namespace carlcs\assetmetadata\errors;

use yii\base\Exception;

/**
 * Thrown when an asset’s file can’t be accessed for metadata extraction (missing file, failed download, …).
 *
 * @since 5.2.0
 */
class MetadataException extends Exception
{
    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Metadata exception';
    }
}
