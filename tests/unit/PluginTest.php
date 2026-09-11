<?php

declare(strict_types=1);

namespace carlcs\assetmetadata\tests\unit;

use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    public function testPackageVersionHasADatedChangelogEntry(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode((string)file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $changelog = (string)file_get_contents($root . '/CHANGELOG.md');

        self::assertIsString($composer['version']);
        self::assertMatchesRegularExpression(
            '/^##\s+' . preg_quote($composer['version'], '/') . '\s+-\s+\d{4}-\d{2}-\d{2}\s*$/m',
            $changelog,
            'CHANGELOG.md must contain a dated heading for the package version.'
        );
    }

    public function testComposerManifestSupportsBothCraftMajors(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode((string)file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('craft-plugin', $composer['type']);
        self::assertSame('asset-metadata', $composer['extra']['handle']);
        self::assertSame('carlcs\\assetmetadata\\Plugin', $composer['extra']['class']);
        self::assertMatchesRegularExpression('/\^4\.0/', $composer['require']['craftcms/cms']);
        self::assertMatchesRegularExpression('/\^5\.0/', $composer['require']['craftcms/cms']);
    }

    public function testTheJavaScriptReadsValuesAsAnObjectKeyedBySubfieldId(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 2) . '/src/web/assets/dist/assetmetadata.js');

        // Regression: `data.forEach()` on the returned object silently did nothing
        self::assertStringNotContainsString('data.forEach', $js);
        self::assertStringContainsString('Object.keys(values)', $js);
        self::assertStringContainsString("'asset-metadata/metadata/get-field-value'", $js);
    }
}
