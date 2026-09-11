<?php

namespace carlcs\assetmetadata\gql;

use carlcs\assetmetadata\fields\AssetMetadata as AssetMetadataField;
use Craft;
use craft\gql\base\GeneratorInterface;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\Type;
use yii\base\InvalidConfigException;

/**
 * Generates the GraphQL object type for an Asset Metadata field’s subfields.
 */
class AssetMetadataTypeGenerator implements GeneratorInterface
{
    /**
     * @inheritdoc
     */
    public static function generateTypes(mixed $context = null): array
    {
        /** @var AssetMetadataField $context */
        $typeName = self::getName($context);
        $handles = self::gqlSubfieldHandles($context);

        if (empty($handles)) {
            throw new InvalidConfigException("The “{$context->handle}” field has no subfields with valid GraphQL names.");
        }

        $contentFields = array_fill_keys($handles, Type::string());
        $contentFields = Craft::$app->getGql()->prepareFieldDefinitions($contentFields, $typeName);

        $type = GqlEntityRegistry::getEntity($typeName) ?: GqlEntityRegistry::createEntity($typeName, new AssetMetadataType([
            'name' => $typeName,
            'fields' => function() use ($contentFields) {
                return $contentFields;
            },
        ]));

        return [$type];
    }

    /**
     * Returns the subfield handles that are valid GraphQL field names.
     *
     * @return string[]
     */
    public static function gqlSubfieldHandles(AssetMetadataField $field): array
    {
        $handles = [];

        foreach ($field->getSubfields() as $subfield) {
            if (preg_match('/^[_a-zA-Z][_0-9a-zA-Z]*$/', $subfield['handle'])) {
                $handles[] = $subfield['handle'];
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * @inheritdoc
     */
    public static function getName($context = null): string
    {
        /** @var AssetMetadataField $context */
        return $context->handle . '_Subfields';
    }
}
