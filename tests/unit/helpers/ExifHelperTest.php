<?php

declare(strict_types=1);

namespace carlcs\assetmetadata\tests\unit\helpers;

use carlcs\assetmetadata\helpers\ExifHelper;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use yii\base\InvalidArgumentException;

final class ExifHelperTest extends TestCase
{
    /** @var array<string, mixed> GPS data as PHP’s exif_read_data() returns it */
    private const GPS = [
        'GPSLatitudeRef' => 'N',
        'GPSLatitude' => ['52/1', '22/1', '4508/100'],
        'GPSLongitudeRef' => 'E',
        'GPSLongitude' => ['4/1', '53/1', '5795/100'],
    ];

    public function testConvertsExifDates(): void
    {
        $date = ExifHelper::convertExifDate('2024:05:06 07:08:09');

        self::assertSame('2024-05-06 07:08:09', $date->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $date->getTimezone()->getName());
    }

    public function testConvertsExifDatesInAGivenTimezone(): void
    {
        $date = ExifHelper::convertExifDate('2024:05:06 07:08:09', new DateTimeZone('Europe/Amsterdam'));

        self::assertSame('Europe/Amsterdam', $date->getTimezone()->getName());
        self::assertSame('2024-05-06 07:08:09', $date->format('Y-m-d H:i:s'));
    }

    /**
     * @dataProvider invalidDateProvider
     */
    public function testRejectsInvalidExifDates(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExifHelper::convertExifDate($value);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidDateProvider(): iterable
    {
        yield 'null (tag missing in the file)' => [null];
        yield 'empty string' => [''];
        yield 'placeholder written by cameras' => ['0000:00:00 00:00:00'];
        yield 'garbage' => ['not a date'];
        yield 'impossible date' => ['2024:13:45 07:08:09'];
        yield 'impossible time' => ['2024:05:06 25:08:09'];
        yield 'array' => [['2024:05:06 07:08:09']];
        yield 'iso date' => ['2024-05-06'];
    }

    public function testConvertsGpsCoordinatesToDecimals(): void
    {
        self::assertEqualsWithDelta(52.379189, ExifHelper::convertExifGpsCoordinate(self::GPS, ExifHelper::GPS_LAT, false), 0.0001);
        self::assertEqualsWithDelta(4.899431, ExifHelper::convertExifGpsCoordinate(self::GPS, ExifHelper::GPS_LONG, false), 0.0001);
    }

    public function testSouthernAndWesternCoordinatesAreNegative(): void
    {
        $gps = array_merge(self::GPS, ['GPSLatitudeRef' => 'S', 'GPSLongitudeRef' => 'W']);

        self::assertLessThan(0, ExifHelper::convertExifGpsCoordinate($gps, ExifHelper::GPS_LAT, false));
        self::assertLessThan(0, ExifHelper::convertExifGpsCoordinate($gps, ExifHelper::GPS_LONG, false));
    }

    public function testFormatsCoordinatesInSexagesimalNotation(): void
    {
        self::assertSame('52°22\'45.1"N 4°53\'58.0"E', ExifHelper::convertExifGpsCoordinates(self::GPS));
        self::assertSame('52°22\'45.1"N 4°53\'58.0"E', ExifHelper::convertExifGpsCoordinates(self::GPS, true));
        self::assertSame('52-22-45.1 N', ExifHelper::convertExifGpsCoordinate(self::GPS, ExifHelper::GPS_LAT, '%d-%02d-%04.1f %s'));
    }

    public function testReturnsDecimalPairWhenFormattingIsDisabled(): void
    {
        $result = ExifHelper::convertExifGpsCoordinates(self::GPS, false);

        self::assertIsArray($result);
        self::assertEqualsWithDelta(52.379189, $result['latitude'], 0.0001);
        self::assertEqualsWithDelta(4.899431, $result['longitude'], 0.0001);
    }

    public function testFormatsDecimalCoordinates(): void
    {
        self::assertSame('52°22\'45.1"N', ExifHelper::formatGpsCoordinate(52.379189, ExifHelper::GPS_LAT));
        self::assertSame('4°53\'58.0"W', ExifHelper::formatGpsCoordinate(-4.899431, ExifHelper::GPS_LONG));
        self::assertSame('4°53\'58.0"W', ExifHelper::formatGpsCoordinate('-4.899431', ExifHelper::GPS_LONG));
    }

    /**
     * @dataProvider invalidGpsProvider
     */
    public function testRejectsIncompleteGpsData(mixed $gps): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExifHelper::convertExifGpsCoordinates($gps);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidGpsProvider(): iterable
    {
        yield 'null (no GPS section)' => [null];
        yield 'empty array' => [[]];
        yield 'missing reference' => [['GPSLatitude' => ['52/1', '22/1', '4508/100']]];
        yield 'coordinate is not an array' => [['GPSLatitude' => '52.37', 'GPSLatitudeRef' => 'N', 'GPSLongitude' => '4.89', 'GPSLongitudeRef' => 'E']];
        yield 'string instead of array' => ['52.37,4.89'];
    }

    public function testRejectsUnknownAxis(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExifHelper::convertExifGpsCoordinate(self::GPS, 'altitude');
    }

    public function testRejectsNonNumericValuesWhenFormatting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExifHelper::formatGpsCoordinate('north', ExifHelper::GPS_LAT);
    }
}
