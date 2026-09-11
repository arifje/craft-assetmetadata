# Asset Metadata plugin for Craft CMS

A field type that extracts metadata from an asset’s file (EXIF, IPTC, ID3, video/audio properties, …) with [getID3](https://github.com/JamesHeinrich/getID3) and stores it in subfields that you define with Twig templates.

## Requirements

- Craft CMS 4.0 or later, or Craft CMS 5.0 or later (one plugin version supports both)
- PHP 8.0.2 or later (Craft 5 needs PHP 8.2 or later)
- The PHP `exif` extension for EXIF data of images (getID3 falls back gracefully without it)

Version 5.2.0 was tested against Craft 4.18 and Craft 5.11 on MySQL 8 (see [Development](#development)).

## Installation

```bash
composer require carlcs/craft-assetmetadata
php craft plugin/install asset-metadata
```

## Usage

1. Create a field of the type **Asset Metadata** and define its subfields: a name, a handle and a *Default Value Template*.
2. Add the field to the field layout of one or more asset volumes. (The field only works on assets.)
3. Upload an asset. The subfield templates are rendered against the file’s metadata and the results are stored in the field.

Metadata is extracted:

- when an asset is uploaded,
- when an asset’s file is replaced (including images saved from Craft’s image editor),
- when the **Refresh** button of the field is used in the control panel (unless the field is read-only),
- on every save of the asset when the field’s **Refresh on element save** setting is enabled (advanced setting), for example during `php craft resave/assets`.

If the file can’t be read (it is missing, or downloading it from a remote filesystem fails), the values that are already stored are kept and a warning is logged.

> **Images and EXIF data:** Craft re-encodes uploaded images (to apply the EXIF orientation and to strip metadata) unless the `preserveExifData` general config setting is enabled. The plugin analyzes the uploaded file *before* that happens, so EXIF-based subfields are filled in on upload, but a later **Refresh** (or “Refresh on element save”) reads the stored, cleaned file and finds no EXIF data anymore. Enable `preserveExifData` if you need to re-extract EXIF data later.

### Field settings

| Setting | Description |
|---|---|
| Subfields | The subfields with their name, handle and template. Handles must be unique and start with a letter or underscore, followed by letters, numbers or underscores. |
| Make read-only | Hides the Refresh button and makes the inputs read-only. |
| Refresh on element save | Re-extracts the metadata every time the asset is saved. Manual edits are overwritten. |

### Subfield templates

A template is a Twig snippet that receives two variables:

- `metadata` – the array getID3 returns for the file (see getID3’s [structure.txt](https://github.com/JamesHeinrich/getID3/blob/master/structure.txt)). All tag formats are additionally merged into `metadata.comments`.
- `object` – the asset element.

Examples:

```twig
{# Camera model and date from EXIF data #}
{{ metadata.jpg.exif.IFD0.Model ?? '' }}
{{ metadata.jpg.exif.EXIF.DateTimeOriginal|convertExifDate|date('Y-m-d H:i') }}

{# GPS coordinates as 52°22'45.1"N 4°53'58.0"E, or as decimals #}
{{ metadata.jpg.exif.GPS|convertExifGpsCoordinates }}
{{ metadata.jpg.exif.GPS.computed.latitude ?? '' }}

{# Exposure #}
{{ metadata.jpg.exif.EXIF.ExposureTime|floatToFraction }} s at f/{{ metadata.jpg.exif.EXIF.FNumber }}

{# Audio and video #}
{{ metadata.comments.title[0] ?? '' }} by {{ metadata.comments.artist[0] ?? '' }}
{{ metadata.playtime_string ?? '' }}
{{ metadata.video.resolution_x ?? '' }}×{{ metadata.video.resolution_y ?? '' }}
{{ metadata.audio.bitrate|unitPrefix }}bit/s

{# Anything else #}
{{ metadata.mime_type ?? '' }}
```

Templates are rendered without autoescaping and the results are stored as plain text. If a template throws (for example `convertExifDate` on an image without EXIF data), that subfield is stored empty for the asset and a warning is logged; the other subfields and the asset itself are unaffected. Use `??` defaults or `is defined` checks to avoid the warnings.

Use `php craft asset-metadata/extract <assetId>` to see the complete metadata array for an asset, optionally reduced with `--key=jpg.exif.EXIF`.

### Twig filters

The plugin registers these filters for use in subfield templates (and in your site templates):

| Filter | Description |
|---|---|
| `convertExifDate(timezone = null)` | Converts an EXIF date string (`2024:05:06 07:08:09`) into a `DateTime`. Throws for missing/invalid dates (including the `0000:00:00 00:00:00` placeholder). |
| `convertExifGpsCoordinates(format = true)` | Converts the `GPS` section of the EXIF array into a coordinate string. `format` can be `true` (sexagesimal, ISO 6709 style), a `sprintf()` format string receiving degrees, minutes, seconds and the reference, or `false` for `{latitude, longitude}` decimals. |
| `convertExifGpsCoordinate(axis, format = false)` | The same for a single axis (`lat` or `long`). |
| `formatGpsCoordinate(axis, format = null)` | Formats a decimal coordinate in sexagesimal notation. |
| `fractionToFloat(precision = 4)` | `1/200` → `0.005` |
| `floatToFraction(tolerance = 0.001)` | `0.005` → `1/200` |
| `unitPrefix(system = 'decimal', decimals = 1, trailingZeros = false, decPoint = '.', thousandsSep = '', unitSep = ' ')` | `1500` → `1.5 k`. Systems: `decimal`, `decimalNames`, `binary`, `binaryNames`, `names`, or a custom map. |
| `numeralSystem(system, zero = -1)` | Roman (`roman`, `lowerRoman`) or alphabetic (`alpha`, `lowerAlpha`) numerals. |

### Element index columns

Every subfield is available as a column in the asset index (**Customize sources → Table columns**). Columns show the stored values; nothing is extracted or downloaded while rendering the index. Values are HTML-encoded.

### Templates and GraphQL

The field value is an array keyed by subfield handle (and by the internal subfield ID):

```twig
{{ asset.metadata.camera }}
```

GraphQL exposes the subfields as an object type named `<fieldHandle>_Subfields` with one `String` field per subfield:

```graphql
{
  assets {
    metadata {
      camera
      taken
    }
  }
}
```

Subfield values are also included in the search index.

### Events

`carlcs\assetmetadata\services\Metadata::EVENT_AFTER_EXTRACT` is triggered after the metadata was extracted from a file and before the subfield templates are rendered. The event exposes `metadata` (modifiable), `asset` and `field` (`null` when extracting from the console).

```php
use carlcs\assetmetadata\events\MetadataEvent;
use carlcs\assetmetadata\services\Metadata;
use yii\base\Event;

Event::on(Metadata::class, Metadata::EVENT_AFTER_EXTRACT, function(MetadataEvent $event) {
    $event->metadata['custom']['hash'] = md5_file($event->asset->tempFilePath ?? '');
});
```

## Configuration

Create `config/asset-metadata.php` to override the defaults:

```php
<?php

use craft\elements\Asset;

return [
    // Options for the getID3 library, applied as public properties of the getID3 instance.
    // See https://github.com/JamesHeinrich/getID3/blob/master/getid3/getid3.php
    'getId3' => [
        'option_extra_info' => true,
        'option_tags_html' => false,
        'option_save_attachments' => false,
    ],

    // Files on remote (non-local) filesystems are downloaded to a temporary file before they are
    // analyzed. Only this many bytes are downloaded; set it to `false` to download complete files.
    'downloadChunkSize' => 256 * 1024,

    // For these file kinds the temporary file is padded to the original file size, so getID3 can
    // still derive things like the playtime from a partial download. `true` enables it for all kinds.
    'fakeCompleteFileSize' => [Asset::KIND_AUDIO],

    // Rendered subfield values longer than this (in characters) are truncated before they are
    // stored. `null` disables the limit.
    'maxValueLength' => 65535,
];
```

### Remote filesystems

Assets on local filesystems are analyzed in place. For any other filesystem (S3, Google Cloud, …) the plugin streams (a chunk of) the file into Craft’s temp folder (`storage/runtime/temp`), analyzes it, and deletes the temporary file again, also when the download or analysis fails.

Because metadata is only extracted when a file is uploaded, replaced or explicitly refreshed, browsing the asset index never downloads files.

### Craft 4 content column

Craft 4 stores the field value in a `text` column (64 KB on MySQL). If the rendered values wouldn’t fit, the longest values are trimmed so the asset can still be saved; a warning is logged. Craft 5 stores the value as JSON without that limit.

## Security and privacy notes

- File contents and the metadata extracted from them are untrusted input. The plugin encodes values before rendering them in the control panel and sanitizes them before storing them, but you are responsible for escaping them in your own templates (Craft’s autoescaping does that by default).
- Metadata can contain personal data, most notably GPS coordinates and names in EXIF/IPTC/ID3 tags. Only render subfields on public pages or expose them through GraphQL when that is intended. Diagnostic log messages never include the extracted metadata.
- The Refresh button’s control panel action requires a POST request with a CSRF token by a logged-in user who may view the asset in question.
- The plugin only reads files; it never modifies or strips metadata from the original files.

## Upgrading to 5.2.0

No migration is required; existing field settings, stored values and element index columns keep working. Please note:

- Replacing an asset’s file now refreshes its metadata.
- Images without EXIF data no longer fail to save when a template uses `convertExifDate`; the subfield is stored empty instead. Templates that relied on `{{ null|convertExifDate }}` throwing a `TypeError` didn’t work on PHP 8 anyway.
- If you call the service yourself: `Metadata::getFieldValue()` returns `null` when the file can’t be analyzed, and `Metadata::extract()` throws a `carlcs\assetmetadata\errors\MetadataException` in that case.
- When you save a field, its subfield handles are now validated. Fix invalid handles (spaces, colons, leading digits) in the field settings if saving fails.

See the [changelog](CHANGELOG.md) for everything that changed.

## Development

Unit tests need no Craft installation or database:

```bash
composer install
composer test        # PHPUnit against the Craft 5 dependency tree
composer analyse     # PHPStan
```

To run the same unit tests against Craft 4:

```bash
COMPOSER_VENDOR_DIR=vendor-craft4 composer update --with "craftcms/cms:^4.0" --with-all-dependencies --no-blocking
ASSET_METADATA_VENDOR_DIR=$PWD/vendor-craft4 vendor-craft4/bin/phpunit
```

The integration tests run inside real Craft 4 and Craft 5 installations (MySQL) provided by the Docker harness in `dev/`:

```bash
bin/dev up5             # Craft 5 at http://localhost:8565 (admin / password)
bin/dev up4             # Craft 4 at http://localhost:8464
bin/dev integration5    # integration tests inside the Craft 5 container
bin/dev integration4
bin/dev check           # lint + PHPStan + unit + integration tests for both versions
bin/dev down
```

The first `up` creates a Craft project in `dev/craft4` / `dev/craft5`, installs Craft and the plugin (bind-mounted as a Composer path repository), and seeds an isolated volume (in the project’s `storage/` folder) with an Asset Metadata field and generated sample files. In dev mode the harness auto-logs-in the admin user for control panel requests. The integration tests create and remove their own disposable volumes, fields, users and assets; nothing outside the harness is touched.

The two MySQL containers are configured with small buffers; still, run one Craft version at a time on a busy Docker host (`bin/dev down` stops both).

## License

[MIT](LICENSE.md)
