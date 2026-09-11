<?php

namespace carlcs\assetmetadata;

use carlcs\assetmetadata\fields\AssetMetadata as AssetMetadataField;
use carlcs\assetmetadata\services\Metadata;
use carlcs\assetmetadata\web\twig\Extension;
use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\elements\Asset;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\services\Fields;
use craft\services\Volumes;
use Throwable;
use yii\base\Event;

/**
 * Asset Metadata plugin.
 *
 * Supports Craft CMS 4 and 5 from a single code base. Version-specific behaviour is isolated in
 * small, explicit checks (see `_registerAssetTableAttributes()` and `usesContentTable()`).
 *
 * @property-read Metadata $metadata
 * @property-read Settings $settings
 * @method Settings getSettings()
 * @method static Plugin getInstance()
 */
class Plugin extends \craft\base\Plugin
{
    // Properties
    // =========================================================================

    public string $schemaVersion = '3.0.0';
    public string $minVersionRequired = '2.1.1';

    /**
     * @var array<string, array{label: string}>|null Element index table attributes for all subfields,
     * keyed by attribute key (`field:<fieldHandle>:<subfieldHandle>`). Built lazily once per request.
     */
    private ?array $_tableAttributes = null;

    /**
     * @var array<string, array{0: string, 1: string}> Attribute key => [field handle, subfield handle]
     */
    private array $_subfieldMap = [];

    // Static Methods
    // =========================================================================

    /**
     * Returns whether this Craft installation stores custom field values in dedicated content-table
     * columns (Craft 4). Craft 5 stores them as JSON in `elements_sites.content` and has no column limits.
     */
    public static function usesContentTable(): bool
    {
        return version_compare(Craft::$app->getVersion(), '5.0.0-alpha', '<');
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->set('metadata', Metadata::class);

        Craft::$app->getView()->registerTwigExtension(new Extension());

        $this->_registerFieldTypes();
        $this->_registerAssetTableAttributes();
    }

    /**
     * Returns the metadata service.
     */
    public function getMetadata(): Metadata
    {
        /** @var Metadata $service */
        $service = $this->get('metadata');
        return $service;
    }

    /**
     * Returns the element index table attributes for the subfields of all Asset Metadata fields.
     *
     * Keys use the `field:<fieldHandle>:<subfieldHandle>` format the plugin has used since Craft 3, so
     * table attributes stored in existing element sources keep working.
     *
     * @return array<string, array{label: string}>
     */
    public function getSubfieldTableAttributes(): array
    {
        if ($this->_tableAttributes === null) {
            $this->_tableAttributes = [];
            $this->_subfieldMap = [];

            foreach ($this->_assetMetadataFields() as $field) {
                foreach ($field->getSubfields() as $subfield) {
                    $key = "field:$field->handle:{$subfield['handle']}";
                    $label = $subfield['name'] !== '' ? $subfield['name'] : $subfield['handle'];
                    $this->_tableAttributes[$key] = ['label' => Craft::t('asset-metadata', $label)];
                    $this->_subfieldMap[$key] = [$field->handle, $subfield['handle']];
                }
            }
        }

        return $this->_tableAttributes;
    }

    /**
     * Returns the HTML-encoded table cell content for a subfield table attribute, or `null` if the
     * attribute doesn’t belong to an Asset Metadata field.
     *
     * The stored field value is used as-is; no metadata is extracted (and no file downloaded) while
     * rendering element index rows.
     */
    public function getSubfieldTableAttributeHtml(Asset $asset, string $attribute): ?string
    {
        $this->getSubfieldTableAttributes();

        if (!isset($this->_subfieldMap[$attribute])) {
            return null;
        }

        [$fieldHandle, $subfieldHandle] = $this->_subfieldMap[$attribute];

        try {
            $value = $asset->getFieldValue($fieldHandle);
        } catch (Throwable) {
            // The field isn’t part of this asset’s field layout
            return '';
        }

        if (!is_array($value) || !isset($value[$subfieldHandle])) {
            return '';
        }

        $subfieldValue = $value[$subfieldHandle];

        if (!is_scalar($subfieldValue)) {
            $subfieldValue = StringHelper::toString($subfieldValue, ', ');
        }

        // Metadata comes from untrusted files: always encode it before it ends up in the control panel.
        return Html::encode((string)$subfieldValue);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    // Private Methods
    // =========================================================================

    private function _registerFieldTypes(): void
    {
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = AssetMetadataField::class;
        });
    }

    private function _registerAssetTableAttributes(): void
    {
        // Registering table attributes works the same way in Craft 4 and 5.
        Event::on(Asset::class, Element::EVENT_REGISTER_TABLE_ATTRIBUTES, function(RegisterElementTableAttributesEvent $event) {
            // Always start from the current fields when an index is being prepared
            $this->_tableAttributes = null;
            $event->tableAttributes = array_merge($event->tableAttributes, $this->getSubfieldTableAttributes());
        });

        // Long-running processes (queue workers, console commands, tests) must not keep working with an
        // outdated attribute map when fields or volumes change.
        $forget = function(): void {
            $this->_tableAttributes = null;
        };

        foreach ([Fields::EVENT_AFTER_SAVE_FIELD, Fields::EVENT_AFTER_DELETE_FIELD] as $eventName) {
            Event::on(Fields::class, $eventName, $forget);
        }

        foreach ([Volumes::EVENT_AFTER_SAVE_VOLUME, Volumes::EVENT_AFTER_DELETE_VOLUME] as $eventName) {
            Event::on(Volumes::class, $eventName, $forget);
        }

        // Rendering a table cell differs between versions:
        // - Craft 5: `Element::getAttributeHtml()` fires `EVENT_DEFINE_ATTRIBUTE_HTML` (`DefineAttributeHtmlEvent`),
        //   and `Element::getInlineAttributeInputHtml()` fires `EVENT_DEFINE_INLINE_ATTRIBUTE_INPUT_HTML` with the
        //   same event class when the table is in inline-editing mode.
        // - Craft 4: `Element::getTableAttributeHtml()` fires `EVENT_SET_TABLE_ATTRIBUTE_HTML`
        //   (`SetElementTableAttributeHtmlEvent`), which Craft 5 removed.
        // Both event classes expose `$attribute` and `$html`, so one handler serves all of them.
        $handler = function(Event $event): void {
            $this->_applyAttributeHtml($event);
        };

        $eventNames = [];

        if (defined(Element::class . '::EVENT_DEFINE_ATTRIBUTE_HTML')) {
            // Craft 5+
            $eventNames[] = constant(Element::class . '::EVENT_DEFINE_ATTRIBUTE_HTML');

            if (defined(Element::class . '::EVENT_DEFINE_INLINE_ATTRIBUTE_INPUT_HTML')) {
                $eventNames[] = constant(Element::class . '::EVENT_DEFINE_INLINE_ATTRIBUTE_INPUT_HTML');
            }
        } elseif (defined(Element::class . '::EVENT_SET_TABLE_ATTRIBUTE_HTML')) {
            // Craft 4
            $eventNames[] = constant(Element::class . '::EVENT_SET_TABLE_ATTRIBUTE_HTML');
        }

        foreach ($eventNames as $eventName) {
            Event::on(Asset::class, $eventName, $handler);
        }
    }

    /**
     * Sets the HTML of an attribute-HTML event if the attribute belongs to one of our subfields.
     *
     * @param object $event A `DefineAttributeHtmlEvent` (Craft 5) or `SetElementTableAttributeHtmlEvent` (Craft 4);
     * both expose `$attribute` and `$html`
     */
    private function _applyAttributeHtml(object $event): void
    {
        $asset = $event->sender ?? null;
        $attribute = $event->attribute ?? null;

        if (!$asset instanceof Asset || !is_string($attribute)) {
            return;
        }

        $html = $this->getSubfieldTableAttributeHtml($asset, $attribute);

        if ($html !== null) {
            $event->html = $html;
            $event->handled = true;
        }
    }

    /**
     * Returns all Asset Metadata fields, keyed by handle.
     *
     * Fields are collected from the global field list and from the asset volumes’ field layouts, so
     * layout-specific handles (Craft 5) are covered as well.
     *
     * @return AssetMetadataField[]
     */
    private function _assetMetadataFields(): array
    {
        $fields = [];

        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if ($field instanceof AssetMetadataField && $field->handle) {
                $fields[$field->handle] = $field;
            }
        }

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            try {
                $layoutFields = $volume->getFieldLayout()->getCustomFields();
            } catch (Throwable) {
                continue;
            }

            foreach ($layoutFields as $field) {
                if ($field instanceof AssetMetadataField && $field->handle) {
                    $fields[$field->handle] = $field;
                }
            }
        }

        return $fields;
    }
}
