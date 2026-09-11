<?php

namespace carlcs\assetmetadata\gql;

use craft\gql\base\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * GraphQL object type for the subfields of an Asset Metadata field.
 */
class AssetMetadataType extends ObjectType
{
    /**
     * @inheritdoc
     */
    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        if (!is_array($source)) {
            return null;
        }

        $value = $source[$resolveInfo->fieldName] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string)$value : null;
    }
}
