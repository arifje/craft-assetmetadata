<?php

declare(strict_types=1);

namespace carlcs\assetmetadata\tests\unit\helpers;

use carlcs\assetmetadata\helpers\ArrayHelper;
use PHPUnit\Framework\TestCase;

final class ArrayHelperTest extends TestCase
{
    private const DATA = [
        'jpg' => ['exif' => ['IFD0' => ['Model' => 'EOS R5'], 'GPS' => ['GPSLatitude' => ['52/1']]]],
        'mime_type' => 'image/jpeg',
        'filesize' => 1234,
    ];

    public function testReadsNestedValuesWithDotNotation(): void
    {
        self::assertSame('EOS R5', ArrayHelper::getValueByKey('jpg.exif.IFD0.Model', self::DATA));
        self::assertSame(['52/1'], ArrayHelper::getValueByKey('jpg.exif.GPS.GPSLatitude', self::DATA));
        self::assertSame(['Model' => 'EOS R5'], ArrayHelper::getValueByKey('jpg.exif.IFD0', self::DATA));
    }

    public function testReadsTopLevelValues(): void
    {
        self::assertSame('image/jpeg', ArrayHelper::getValueByKey('mime_type', self::DATA));
        self::assertSame(1234, ArrayHelper::getValueByKey('filesize', self::DATA));
    }

    public function testReturnsNullForMissingPaths(): void
    {
        self::assertNull(ArrayHelper::getValueByKey('nope', self::DATA));
        self::assertNull(ArrayHelper::getValueByKey('jpg.exif.EXIF.DateTimeOriginal', self::DATA));
        // Traversing into a scalar used to throw a TypeError
        self::assertNull(ArrayHelper::getValueByKey('mime_type.charset', self::DATA));
        self::assertNull(ArrayHelper::getValueByKey('jpg.exif.IFD0.Model.0', self::DATA));
    }
}
