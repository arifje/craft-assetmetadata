<?php

declare(strict_types=1);

namespace carlcs\assetmetadata\tests\unit\fields;

use carlcs\assetmetadata\fields\AssetMetadata;
use craft\base\ElementInterface;
use craft\elements\Asset;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;
use yii\db\Schema;

final class AssetMetadataFieldTest extends TestCase
{
    private const SUBFIELDS = [
        'col1' => ['name' => 'Camera', 'handle' => 'camera', 'template' => '{{ metadata.jpg.exif.IFD0.Model }}'],
        'col2' => ['name' => 'Taken', 'handle' => 'taken', 'template' => '{{ metadata.jpg.exif.EXIF.DateTimeOriginal }}'],
    ];

    public function testNormalizesSubfieldDefinitions(): void
    {
        self::assertSame([], (new AssetMetadata())->getSubfields());
        self::assertSame([], (new AssetMetadata(['subfields' => []]))->getSubfields());

        $field = new AssetMetadata(['subfields' => [
            'col1' => ['name' => ' Camera ', 'handle' => ' camera ', 'template' => '{{ x }}'],
            'col2' => ['handle' => 'partial'],
            'col3' => 'not a row',
        ]]);

        self::assertSame([
            'col1' => ['name' => 'Camera', 'handle' => 'camera', 'template' => '{{ x }}'],
            'col2' => ['name' => '', 'handle' => 'partial', 'template' => ''],
        ], $field->getSubfields());
    }

    /**
     * @dataProvider handleProvider
     */
    public function testValidatesSubfieldHandles(array $subfields, bool $valid): void
    {
        $field = new AssetMetadata(['handle' => 'metadata', 'subfields' => $subfields]);
        $field->validateSubfields();

        self::assertSame($valid, !$field->hasErrors('subfields'), print_r($field->getErrors(), true));
    }

    /**
     * @return iterable<string, array{0: array<string, array<string, string>>, 1: bool}>
     */
    public static function handleProvider(): iterable
    {
        yield 'valid handles' => [self::SUBFIELDS, true];
        yield 'underscore handle' => [['a' => ['handle' => '_private']], true];
        yield 'missing handle' => [['a' => ['name' => 'Camera', 'handle' => '']], false];
        yield 'space in handle' => [['a' => ['handle' => 'date taken']], false];
        yield 'colon in handle (would break table attribute keys)' => [['a' => ['handle' => 'a:b']], false];
        yield 'leading digit' => [['a' => ['handle' => '1st']], false];
        yield 'duplicate handles' => [['a' => ['handle' => 'camera'], 'b' => ['handle' => 'camera']], false];
    }

    public function testNormalizesStoredJsonAndAddsHandleAliases(): void
    {
        $field = new AssetMetadata(['handle' => 'metadata', 'subfields' => self::SUBFIELDS]);

        $value = $field->normalizeValue('{"col1":"EOS R5","col2":"2024:05:06 07:08:09"}');

        self::assertSame([
            'col1' => 'EOS R5',
            'col2' => '2024:05:06 07:08:09',
            'camera' => 'EOS R5',
            'taken' => '2024:05:06 07:08:09',
        ], $value);

        // Craft 5 hands over the decoded array straight from the JSON content column
        self::assertSame($value, $field->normalizeValue(['col1' => 'EOS R5', 'col2' => '2024:05:06 07:08:09']));
    }

    public function testNormalizeValueToleratesEmptyAndMalformedStoredValues(): void
    {
        $field = new AssetMetadata(['handle' => 'metadata', 'subfields' => self::SUBFIELDS]);

        self::assertNull($field->normalizeValue(null));
        self::assertNull($field->normalizeValue(''));
        self::assertNull($field->normalizeValue('   '));
        self::assertNull($field->normalizeValue('{"col1": "unterminated'));
        self::assertNull($field->normalizeValue('"just a string"'));
        self::assertNull($field->normalizeValue(42));
    }

    public function testNormalizeValueKeepsValuesWithoutSubfieldDefinitions(): void
    {
        $field = new AssetMetadata(['handle' => 'metadata']);

        self::assertSame(['col1' => 'EOS R5'], $field->normalizeValue('{"col1":"EOS R5"}'));
    }

    public function testAliasesDoNotOverwriteExistingKeys(): void
    {
        $field = new AssetMetadata(['handle' => 'metadata', 'subfields' => ['col1' => ['handle' => 'col2'], 'col2' => ['handle' => 'other']]]);

        self::assertSame(['col1' => 'a', 'col2' => 'b', 'other' => 'b'], $field->normalizeValue(['col1' => 'a', 'col2' => 'b']));
    }

    public function testSerializeValueDropsTheAliasesAgain(): void
    {
        $field = new AssetMetadata(['handle' => 'metadata', 'subfields' => self::SUBFIELDS]);
        $normalized = $field->normalizeValue(['col1' => 'EOS R5', 'col2' => '']);

        self::assertSame(['col1' => 'EOS R5', 'col2' => ''], $field->serializeValue($normalized));
        self::assertNull($field->serializeValue(null));
        self::assertNull($field->serializeValue('legacy string'));
    }

    public function testSearchKeywordsOnlyContainSubfieldValuesOnce(): void
    {
        $field = new AssetMetadata(['handle' => 'metadata', 'subfields' => self::SUBFIELDS]);
        $element = $this->createMock(ElementInterface::class);

        self::assertSame('EOS R5 2024:05:06', $field->getSearchKeywords($field->normalizeValue(['col1' => 'EOS R5', 'col2' => '2024:05:06']), $element));
        self::assertSame('', $field->getSearchKeywords(null, $element));
        self::assertSame('', $field->getSearchKeywords(['col9' => 'orphan'], $element));
    }

    public function testStorageTypesForBothCraftVersions(): void
    {
        $field = new AssetMetadata();

        self::assertSame(Schema::TYPE_JSON, AssetMetadata::dbType());
        self::assertSame(Schema::TYPE_TEXT, $field->getContentColumnType());
        self::assertSame('array|null', AssetMetadata::phpType());
        self::assertSame([AssetMetadata::TRANSLATION_METHOD_NONE], AssetMetadata::supportedTranslationMethods());
    }

    public function testGraphQlTypeFallsBackToStringWithoutSubfields(): void
    {
        $type = (new AssetMetadata(['handle' => 'metadata']))->getContentGqlType();

        self::assertSame(Type::string(), $type);
    }

    public function testBeforeElementSaveIgnoresNonAssets(): void
    {
        $field = new AssetMetadata(['handle' => 'metadata', 'subfields' => self::SUBFIELDS]);
        $element = $this->createMock(ElementInterface::class);
        $element->expects(self::never())->method('setFieldValue');

        self::assertTrue($field->beforeElementSave($element, true));
    }

    /**
     * @dataProvider refreshProvider
     */
    public function testDecidesWhenMetadataShouldBeRefreshed(array $fieldConfig, array $assetConfig, bool $isNew, bool $expected): void
    {
        $field = new TestableField(array_merge(['handle' => 'metadata', 'subfields' => self::SUBFIELDS], $fieldConfig));
        $asset = new Asset(array_merge(['filename' => 'photo.jpg'], $assetConfig));

        self::assertSame($expected, $field->exposedShouldRefresh($asset, $isNew));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool, 3: bool}>
     */
    public static function refreshProvider(): iterable
    {
        yield 'new asset' => [[], [], true, true];
        yield 'existing asset, plain save' => [[], [], false, false];
        yield 'existing asset, refresh on save enabled' => [['refreshOnElementSave' => true], [], false, true];
        yield 'existing asset with a replaced file' => [[], ['tempFilePath' => '/tmp/replacement.jpg'], false, true];
        yield 'propagating to another site' => [['refreshOnElementSave' => true], ['propagating' => true], true, false];
    }

    public function testRefreshesEachAssetOnlyOncePerRequest(): void
    {
        $field = new TestableField(['handle' => 'metadata', 'subfields' => self::SUBFIELDS, 'refreshOnElementSave' => true]);
        $first = new Asset(['filename' => 'one.jpg']);
        $second = new Asset(['filename' => 'two.jpg']);

        self::assertTrue($field->exposedShouldRefresh($first, false));
        $field->markRefreshed($first);
        self::assertFalse($field->exposedShouldRefresh($first, false));
        // Other assets saved in the same request (bulk resaves) must still be refreshed
        self::assertTrue($field->exposedShouldRefresh($second, false));
    }
}

/**
 * Exposes the protected refresh decision for the tests.
 */
final class TestableField extends AssetMetadata
{
    public function exposedShouldRefresh(Asset $asset, bool $isNew): bool
    {
        return $this->shouldRefresh($asset, $isNew);
    }

    public function markRefreshed(Asset $asset): void
    {
        $property = new \ReflectionProperty(AssetMetadata::class, '_refreshed');
        $property->setAccessible(true);
        $map = $property->getValue($this);
        $map[$asset] = true;
    }
}
