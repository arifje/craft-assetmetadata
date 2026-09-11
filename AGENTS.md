# Agent working rules for craft-assetmetadata

These rules apply to any AI agent (and human) working in this repository.

## What the plugin does
- Provides the “Asset Metadata” field type: subfields whose values are rendered from the metadata that
  getID3 extracts from an asset’s file (EXIF, ID3, media info) when the asset is uploaded, its file is
  replaced, the “Refresh” button is used, or on every save when the field says so.
- One code base supports Craft 4 and Craft 5. Version-specific behaviour lives in small, explicit checks
  (`Plugin::_registerAssetTableAttributes()`, `Plugin::usesContentTable()`, `MetadataController::canViewAsset()`).
  Don’t override framework methods whose signatures differ between the versions (`Field::inputHtml()`,
  `Element::tableAttributeHtml()`); use the public hooks that exist in both.

## Verification
- Unit tests need no Craft installation: `composer test` (Craft 5 tree) and, after
  `COMPOSER_VENDOR_DIR=vendor-craft4 composer update --with craftcms/cms:^4.0 --no-blocking`,
  `ASSET_METADATA_VENDOR_DIR=$PWD/vendor-craft4 vendor-craft4/bin/phpunit` (Craft 4 tree).
- Integration tests run inside the Docker harness (`bin/dev up4`, `bin/dev up5`, `bin/dev integration4`,
  `bin/dev integration5`) against real Craft installations with MySQL. Exercise both versions whenever
  changed functionality is version-sensitive (events, field rendering, controllers, element indexes).
- Inspect `vendor/craftcms/cms` (Craft 5) and `vendor-craft4/craftcms/cms` (Craft 4) before using a
  version-sensitive API. Do not guess, and do not claim compatibility from Composer constraints alone.

## Security
- File contents and extracted metadata are untrusted input: encode them before rendering, never log the
  full metadata array at error level (it can contain GPS coordinates and other personal data), and never
  execute or include anything derived from it.
- The plugin never modifies original asset files.

## Releases
- Bump `composer.json` `version` and add a dated CHANGELOG heading (the unit tests check they match),
  then tag the release from the `v5` branch.
