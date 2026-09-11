<?php

declare(strict_types=1);

namespace carlcs\assetmetadata\tests\unit\helpers;

use carlcs\assetmetadata\helpers\NumberHelper;
use PHPUnit\Framework\TestCase;

final class NumberHelperTest extends TestCase
{
    /**
     * @dataProvider fractionProvider
     */
    public function testConvertsFractionsAndNumbersToFloats(mixed $value, float $expected): void
    {
        self::assertSame($expected, NumberHelper::fractionToFloat($value));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: float}>
     */
    public static function fractionProvider(): iterable
    {
        yield 'EXIF degrees' => ['52/1', 52.0];
        yield 'EXIF seconds' => ['4508/100', 45.08];
        yield 'exposure time' => ['1/200', 0.005];
        yield 'with spaces' => [' 1 / 200 ', 0.005];
        yield 'decimal string' => ['2.5', 2.5];
        yield 'integer string' => ['400', 400.0];
        yield 'integer' => [3, 3.0];
        yield 'float' => [1.25, 1.25];
        yield 'division by zero' => ['1/0', 0.0];
        yield 'garbage' => ['abc', 0.0];
        yield 'null' => [null, 0.0];
        yield 'array' => [['1/2'], 0.0];
    }

    /**
     * @dataProvider floatToFractionProvider
     */
    public function testConvertsFloatsToFractions(mixed $value, string $expected): void
    {
        self::assertSame($expected, NumberHelper::floatToFraction($value));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function floatToFractionProvider(): iterable
    {
        yield 'exposure time' => [0.005, '1/200'];
        yield 'half' => [0.5, '1/2'];
        yield 'whole number' => [2, '2'];
        yield 'numeric string' => ['0.25', '1/4'];
        yield 'negative' => [-0.25, '-1/4'];
        yield 'zero (used to divide by zero)' => [0, '0'];
        yield 'garbage' => ['abc', '0'];
    }

    /**
     * @dataProvider unitPrefixProvider
     */
    public function testFormatsNumbersWithUnitPrefixes(mixed $value, array $args, string $expected): void
    {
        self::assertSame($expected, NumberHelper::unitPrefix($value, ...$args));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: array<int, mixed>, 2: string}>
     */
    public static function unitPrefixProvider(): iterable
    {
        yield 'kilo without trailing zeros' => [1500, [], '1.5 k'];
        yield 'exact kilo trims the decimal (regression: "1.50" was mangled into "1")' => [1000, [], '1 k'];
        yield 'trailing zeros kept on request' => [1000, ['decimal', 1, true], '1.0 k'];
        yield 'mega' => [2500000, [], '2.5 M'];
        yield 'binary' => [1536, ['binary'], '1.5 Ki'];
        yield 'binary names' => [1048576, ['binaryNames'], '1 mebi'];
        yield 'names' => [12345678, ['names'], '12.3 million'];
        yield 'centi' => [0.05, [], '5 c'];
        yield 'numeric string' => ['1500', [], '1.5 k'];
        yield 'not a number' => ['abc', [], ''];
        yield 'null' => [null, [], ''];
    }

    public function testUnitPrefixWithCustomSystem(): void
    {
        self::assertSame('1.5 thousand', NumberHelper::unitPrefix(1500, ['map' => [3 => 'thousand', 0 => '']]));
        self::assertSame('1.5', NumberHelper::unitPrefix(1.5, ['nomap' => true]));
    }

    /**
     * @dataProvider numeralSystemProvider
     */
    public function testConvertsNumeralSystems(int $value, string $system, int|string $expected): void
    {
        self::assertSame($expected, NumberHelper::numeralSystem($value, $system));
    }

    /**
     * @return iterable<string, array{0: int, 1: string, 2: int|string}>
     */
    public static function numeralSystemProvider(): iterable
    {
        yield 'roman' => [4, 'roman', 'IV'];
        yield 'upper roman' => [2024, 'upperRoman', 'MMXXIV'];
        yield 'lower roman' => [9, 'lowerRoman', 'ix'];
        yield 'alpha' => [3, 'alpha', 'C'];
        yield 'lower alpha' => [27, 'lowerAlpha', 'aa'];
        yield 'unknown system returns the number' => [5, 'binary', 5];
    }

    public function testNumeralSystemAcceptsAnyZeroSetting(): void
    {
        // Used to throw an UnhandledMatchError for anything but -1 and 1
        self::assertSame(0, NumberHelper::numeralSystem(0, 'roman', 0));
        self::assertSame('V', NumberHelper::numeralSystem(5, 'roman', 0));
        // The legacy semantics: -1/1 skip zero by shifting the numbers below/above it
        self::assertSame('I', NumberHelper::numeralSystem(1, 'roman', -1));
        self::assertSame('-I', NumberHelper::numeralSystem(0, 'roman', -1));
        self::assertSame('I', NumberHelper::numeralSystem(0, 'roman', 1));
        self::assertSame('-I', NumberHelper::numeralSystem(-1, 'roman', 1));
    }

    /**
     * @dataProvider trailingZeroProvider
     */
    public function testTrimsTrailingZeroes(int|float|string $value, string $decPoint, string $expected): void
    {
        self::assertSame($expected, NumberHelper::trimTrailingZeroes($value, $decPoint));
    }

    /**
     * @return iterable<string, array{0: int|float|string, 1: string, 2: string}>
     */
    public static function trailingZeroProvider(): iterable
    {
        yield 'one trailing zero' => ['1.50', '.', '1.5'];
        yield 'all zeros' => ['1.00', '.', '1'];
        yield 'integer' => [1, '.', '1'];
        yield 'comma decimal point' => ['1,50', ',', '1,5'];
        yield 'no decimals' => ['150', '.', '150'];
    }

    public function testDetectsFractionsAndFloats(): void
    {
        self::assertTrue(NumberHelper::isFraction('1/200'));
        self::assertTrue(NumberHelper::isFraction('-1 / 2'));
        self::assertFalse(NumberHelper::isFraction('1.5'));
        self::assertFalse(NumberHelper::isFraction(null));
        self::assertFalse(NumberHelper::isFraction(['1/2']));

        self::assertTrue(NumberHelper::isFloat('1.5'));
        self::assertTrue(NumberHelper::isFloat(1.5));
        self::assertTrue(NumberHelper::isFloat(2));
        self::assertFalse(NumberHelper::isFloat('1/2'));
        self::assertFalse(NumberHelper::isFloat('abc'));
        self::assertFalse(NumberHelper::isFloat(null));
    }
}
