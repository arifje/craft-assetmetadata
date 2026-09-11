<?php

namespace carlcs\assetmetadata\fields;

use carlcs\assetmetadata\gql\AssetMetadataTypeGenerator;
use carlcs\assetmetadata\Plugin;
use carlcs\assetmetadata\web\assets\FieldAsset;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\Asset;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\helpers\Json;
use GraphQL\Type\Definition\Type;
use WeakMap;
use yii\db\Schema;

/**
 * Asset Metadata field type.
 *
 * Stores an array of subfield values (keyed by subfield ID) that is rendered from the metadata of the
 * asset’s file. Values are stored as JSON, both in Craft 4 (text content column) and Craft 5 (JSON content).
 *
 * @property string $contentColumnType
 * @property mixed $contentGqlType
 * @property mixed $settingsHtml
 */
class AssetMetadata extends Field
{
    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('asset-metadata', 'Asset Metadata');
    }

    /**
     * @inheritdoc
     */
    public static function supportedTranslationMethods(): array
    {
        return [
            self::TRANSLATION_METHOD_NONE,
        ];
    }

    /**
     * Craft 5: the DB type of the value inside the JSON content column. (Ignored by Craft 4, which
     * uses `getContentColumnType()` instead.)
     */
    public static function dbType(): array|string|null
    {
        return Schema::TYPE_JSON;
    }

    /**
     * Craft 5: the PHP type of the field value.
     */
    public static function phpType(): string
    {
        return 'array|null';
    }

    // Properties
    // =========================================================================

    /**
     * @var array|null Subfield definitions (`name`, `handle`, `template`) keyed by subfield ID
     */
    public ?array $subfields = null;

    /**
     * @var bool Whether the metadata should be re-extracted every time the asset is saved
     */
    public bool $refreshOnElementSave = false;

    /**
     * @var bool Whether the subfield inputs are read-only
     */
    public bool $readOnly = false;

    /**
     * @var WeakMap<ElementInterface, bool> Elements whose metadata was refreshed during this request
     */
    private WeakMap $_refreshed;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->_refreshed = new WeakMap();
    }

    /**
     * Returns the normalized subfield definitions, keyed by subfield ID.
     *
     * @return array<int|string, array{name: string, handle: string, template: string}>
     */
    public function getSubfields(): array
    {
        $subfields = [];

        foreach ($this->subfields ?? [] as $id => $subfield) {
            if (!is_array($subfield)) {
                continue;
            }

            $subfields[$id] = [
                'name' => trim((string)($subfield['name'] ?? '')),
                'handle' => trim((string)($subfield['handle'] ?? '')),
                'template' => (string)($subfield['template'] ?? ''),
            ];
        }

        return $subfields;
    }

    /**
     * Validates the subfield definitions.
     */
    public function validateSubfields(): void
    {
        $handles = [];

        foreach ($this->getSubfields() as $subfield) {
            $handle = $subfield['handle'];

            if ($handle === '') {
                $this->addError('subfields', Craft::t('asset-metadata', 'All subfields must have a handle.'));
            } elseif (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $handle)) {
                $this->addError('subfields', Craft::t('asset-metadata', 'Subfield handles must start with a letter or underscore and can only contain letters, numbers and underscores (“{handle}”).', ['handle' => $handle]));
            } elseif (isset($handles[$handle])) {
                $this->addError('subfields', Craft::t('asset-metadata', 'Subfield handles must be unique (“{handle}”).', ['handle' => $handle]));
            }

            $handles[$handle] = true;
        }
    }

    /**
     * Craft 4: the content column type. (Craft 5 uses `dbType()` instead.)
     */
    public function getContentColumnType(): array|string
    {
        return Schema::TYPE_TEXT;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('asset-metadata/field/settings', [
            'field' => $this,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        $view = Craft::$app->getView();

        if (!$element instanceof Asset) {
            return $view->renderTemplate('asset-metadata/field/input-error', [
                'error' => Craft::t('asset-metadata', 'Asset Metadata fields only work when added to an Asset Volume’s field layout.'),
            ]);
        }

        $view->registerAssetBundle(FieldAsset::class);
        $view->registerTranslations('asset-metadata', [
            'Could not refresh the metadata.',
        ]);

        return $view->renderTemplate('asset-metadata/field/input', [
            'id' => Html::id($this->handle),
            'name' => $this->handle,
            'value' => is_array($value) ? $value : [],
            'field' => $this,
            'subfields' => $this->getSubfields(),
            'element' => $element,
            'readOnly' => $this->readOnly,
            // The refresh button needs a saved asset to extract the metadata from
            'canRefresh' => !$this->readOnly && $element->id !== null,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (is_string($value)) {
            if (trim($value) === '') {
                return null;
            }

            $decoded = Json::decodeIfJson($value);

            if (!is_array($decoded)) {
                // Malformed stored value; don’t let it break the element
                Craft::warning("Ignoring malformed stored value for the “{$this->handle}” field.", __METHOD__);
                return null;
            }

            $value = $decoded;
        }

        if (!is_array($value)) {
            return null;
        }

        // Make the subfield values accessible from their handles as well
        foreach ($this->getSubfields() as $id => $subfield) {
            $handle = $subfield['handle'];

            if ($handle !== '' && array_key_exists($id, $value) && !array_key_exists($handle, $value)) {
                $value[$handle] = $value[$id];
            }
        }

        return $value;
    }

    /**
     * @inheritdoc
     */
    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (!is_array($value)) {
            return null;
        }

        // Drop the handle aliases again; only the subfield IDs are stored
        foreach ($this->getSubfields() as $id => $subfield) {
            $handle = $subfield['handle'];

            if ($handle !== '' && (string)$id !== $handle && array_key_exists($handle, $value)) {
                unset($value[$handle]);
            }
        }

        return $value;
    }

    /**
     * @inheritdoc
     */
    public function getSearchKeywords(mixed $value, ElementInterface $element): string
    {
        if (!is_array($value)) {
            return '';
        }

        $keywords = [];

        foreach ($this->getSubfields() as $id => $subfield) {
            $subfieldValue = $value[$id] ?? null;

            if (is_scalar($subfieldValue) && (string)$subfieldValue !== '') {
                $keywords[] = (string)$subfieldValue;
            }
        }

        return implode(' ', $keywords);
    }

    /**
     * @inheritdoc
     */
    public function beforeElementSave(ElementInterface $element, bool $isNew): bool
    {
        if (!parent::beforeElementSave($element, $isNew)) {
            return false;
        }

        if (!$element instanceof Asset || !$this->shouldRefresh($element, $isNew)) {
            return true;
        }

        // Remember the element before extracting, so a failing extraction isn’t retried over and over
        $this->_refreshed[$element] = true;

        $value = Plugin::getInstance()->getMetadata()->getFieldValue($this, $element);

        if ($value === null) {
            // The file couldn’t be analyzed; keep the metadata that is already stored
            return true;
        }

        if (Plugin::usesContentTable()) {
            $value = $this->fitToContentColumn($value);
        }

        $element->setFieldValue($this->handle, $value);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function getContentGqlType(): Type|array
    {
        if (empty(AssetMetadataTypeGenerator::gqlSubfieldHandles($this))) {
            // An object type needs at least one field; without subfields the value is always empty
            return Type::string();
        }

        $typeArray = AssetMetadataTypeGenerator::generateTypes($this);

        return array_pop($typeArray);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = ['subfields', 'validateSubfields'];

        return $rules;
    }

    /**
     * Returns whether the metadata should be (re-)extracted while saving an asset.
     *
     * Extraction happens at most once per asset per request: not while the value propagates to other
     * sites, not for drafts/revisions, and not again for an asset that was already handled.
     */
    protected function shouldRefresh(Asset $asset, bool $isNew): bool
    {
        if ($asset->propagating || ElementHelper::isDraftOrRevision($asset) || isset($this->_refreshed[$asset])) {
            return false;
        }

        // A pending `tempFilePath` means a new or replaced file, whose metadata is always fresh to extract
        return $isNew || $this->refreshOnElementSave || $asset->tempFilePath !== null;
    }

    // Private Methods
    // =========================================================================

    /**
     * Craft 4 stores the JSON-encoded value in a `text` column, which is limited to 64KB on MySQL. Trims
     * the longest values until the encoded value fits, so oversized metadata can’t fail the element save.
     *
     * @param array<int|string, string> $value
     * @return array<int|string, string>
     */
    private function fitToContentColumn(array $value): array
    {
        $capacity = Db::getTextualColumnStorageCapacity(Schema::TYPE_TEXT);

        if (!is_int($capacity) || $capacity <= 0) {
            // null means unlimited (PostgreSQL)
            return $value;
        }

        $truncated = false;

        for ($i = 0; $i < 100 && strlen(Json::encode($value)) > $capacity; $i++) {
            $longestKey = null;
            $longestLength = 0;

            foreach ($value as $key => $subfieldValue) {
                $length = is_string($subfieldValue) ? mb_strlen($subfieldValue) : 0;

                if ($length > $longestLength) {
                    $longestKey = $key;
                    $longestLength = $length;
                }
            }

            if ($longestKey === null) {
                break;
            }

            $value[$longestKey] = mb_substr((string)$value[$longestKey], 0, (int)floor($longestLength * 0.8));
            $truncated = true;
        }

        if ($truncated) {
            Craft::warning("The “{$this->handle}” field value was truncated to fit the content column.", __METHOD__);
        }

        return $value;
    }
}
