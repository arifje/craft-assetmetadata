<?php

namespace carlcs\assetmetadata\helpers;

use Craft;
use DateTime;
use DateTimeZone;
use Stringable;
use yii\base\InvalidArgumentException;

/**
 * Helpers for EXIF data, available as Twig filters in subfield templates.
 *
 * Invalid or missing input throws an `InvalidArgumentException`. Inside a subfield template that leaves
 * the subfield empty for the asset in question (and logs a warning), which is the behaviour the plugin
 * has always had for e.g. images without EXIF data.
 */
class ExifHelper
{
    public const GPS_LAT = 'lat';
    public const GPS_LONG = 'long';

    /**
     * Converts an EXIF date/time string (`YYYY:MM:DD HH:MM:SS`) into a DateTime object.
     *
     * @throws InvalidArgumentException if the value isn’t a valid EXIF date/time string
     */
    public static function convertExifDate(mixed $dateString, ?DateTimeZone $timezone = null): DateTime
    {
        if (!is_scalar($dateString) && !$dateString instanceof Stringable) {
            throw new InvalidArgumentException('$dateString should be a valid EXIF date/time string.');
        }

        if (!preg_match('/(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', (string)$dateString, $matches)) {
            throw new InvalidArgumentException('$dateString should be a valid EXIF date/time string.');
        }

        [, $year, $month, $day, $hour, $minute, $second] = $matches;

        // Cameras write "0000:00:00 00:00:00" when the date is unknown
        if ((int)$year === 0 || !checkdate((int)$month, (int)$day, (int)$year) || (int)$hour > 23 || (int)$minute > 59 || (int)$second > 59) {
            throw new InvalidArgumentException('$dateString should be a valid EXIF date/time string.');
        }

        $date = DateTime::createFromFormat('!Y-m-d H:i:s', "$year-$month-$day $hour:$minute:$second", $timezone);

        if ($date === false) {
            throw new InvalidArgumentException('$dateString should be a valid EXIF date/time string.');
        }

        return $date;
    }

    /**
     * Converts a GPS point location from EXIF GPS data (the `GPS` section of the EXIF array).
     *
     * @param mixed $exif The EXIF GPS data array
     * @param bool|string $format `true` for the default sexagesimal format, a `sprintf()` format string, or
     * `false` to return decimal values as `['latitude' => …, 'longitude' => …]`
     * @return array|string
     * @throws InvalidArgumentException
     */
    public static function convertExifGpsCoordinates(mixed $exif, bool|string $format = true): array|string
    {
        $latitude = self::convertExifGpsCoordinate($exif, self::GPS_LAT, $format);
        $longitude = self::convertExifGpsCoordinate($exif, self::GPS_LONG, $format);

        if ($format === false) {
            return compact('latitude', 'longitude');
        }

        return "$latitude $longitude";
    }

    /**
     * Converts a single GPS coordinate from EXIF GPS data to decimal format or sexagesimal format (ISO 6709).
     *
     * @param mixed $exif The EXIF GPS data array
     * @param string $axis `lat` or `long`
     * @param bool|string $format `false` for a decimal value, `true` for the default sexagesimal format, or
     * a `sprintf()` format string
     * @throws InvalidArgumentException
     */
    public static function convertExifGpsCoordinate(mixed $exif, string $axis, bool|string $format = false): float|string
    {
        $coordMap = [
            self::GPS_LAT => ['dms' => 'GPSLatitude', 'ref' => 'GPSLatitudeRef'],
            self::GPS_LONG => ['dms' => 'GPSLongitude', 'ref' => 'GPSLongitudeRef'],
        ];

        if (($coord = $coordMap[$axis] ?? false) === false) {
            throw new InvalidArgumentException('$axis should be either “lat” or “long”.');
        }

        if (!is_array($exif)) {
            throw new InvalidArgumentException('$exif should be a valid EXIF GPS data array.');
        }

        $dms = $exif[$coord['dms']] ?? null;
        $ref = $exif[$coord['ref']] ?? null;

        if (!is_array($dms) || count($dms) < 3 || !is_scalar($ref)) {
            throw new InvalidArgumentException('$exif should be a valid EXIF GPS data array.');
        }

        $dms = array_values($dms);
        $deg = NumberHelper::fractionToFloat($dms[0]);
        $min = NumberHelper::fractionToFloat($dms[1]);
        $sec = NumberHelper::fractionToFloat($dms[2]);

        $value = $deg + ($min * 60 + $sec) / 3600;

        if (in_array(strtoupper(trim((string)$ref)), ['S', 'W'], true)) {
            $value *= -1;
        }

        if ($format !== false) {
            return self::formatGpsCoordinate($value, $axis, is_string($format) ? $format : null);
        }

        return $value;
    }

    /**
     * Formats a decimal GPS coordinate in sexagesimal format (ISO 6709).
     *
     * @param mixed $value The decimal coordinate
     * @param string $axis `lat` or `long`
     * @param string|null $format A `sprintf()` format string receiving degrees, minutes, seconds and the reference (N/S/E/W)
     * @throws InvalidArgumentException
     */
    public static function formatGpsCoordinate(mixed $value, string $axis, ?string $format = null): string
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('$value should be a decimal coordinate.');
        }

        $value = (float)$value;
        $map = [['S', 'N'], ['W', 'E']];
        $ref = $map[$axis === self::GPS_LAT ? 0 : 1][$value < 0 ? 0 : 1];

        $value = abs($value);
        $deg = floor($value);
        $value = ($value - $deg) * 60;
        $min = floor($value);
        $sec = ($value - $min) * 60;

        $format = $format ?: "%d°%02d'%04.1f\"%s";

        return sprintf($format, $deg, $min, $sec, Craft::t('asset-metadata', $ref));
    }
}
