<?php

namespace carlcs\assetmetadata\helpers;

use Craft;

/**
 * Number helpers, available as Twig filters in subfield templates.
 */
class NumberHelper
{
    /**
     * Converts a decimal number to a representation of that number in another numeral system.
     *
     * @param int $int The number
     * @param string $system `roman`, `upperRoman`, `lowerRoman`, `alpha`, `upperAlpha` or `lowerAlpha`
     * @param int $zero `-1` or `1` to skip zero (which has no roman/alpha representation), anything else keeps the number as-is
     */
    public static function numeralSystem(int $int, string $system, int $zero = -1): int|string
    {
        $int = match ($zero) {
            -1 => ($int < 1) ? $int - 1 : $int,
            1 => ($int > -1) ? $int + 1 : $int,
            default => $int,
        };

        if ($int == 0) {
            return $zero;
        }

        if ($int < 0) {
            $int = abs($int);
            $prefix = '-';
        } else {
            $prefix = '';
        }

        return match ($system) {
            'roman', 'upperRoman' => $prefix . self::_roman($int),
            'lowerRoman' => $prefix . self::_roman($int, 'lower'),
            'alpha', 'upperAlpha' => $prefix . self::_alpha($int),
            'lowerAlpha' => $prefix . self::_alpha($int, 'lower'),
            default => $int,
        };
    }

    /**
     * Formats a number with unit prefixes (e.g. `1500` → `1.5 k`, or `1.5 Ki` for the binary system).
     *
     * @param mixed $number The number
     * @param array|string $system A preset (`decimal`, `decimalSymbol`, `decimalNames`, `binary`, `binarySymbol`,
     * `binaryNames`, `names`) or a settings array with a `map` (exponent => prefix) and optional `base`
     */
    public static function unitPrefix(mixed $number, array|string $system = 'decimal', int $decimals = 1, bool $trailingZeros = false, string $decPoint = '.', string $thousandsSep = '', string $unitSep = ' '): string
    {
        if (!is_numeric($number)) {
            return '';
        }

        $float = (float)$number;

        if (is_string($system)) {
            $system = self::_getUnitPrefixSettings($system);
        }

        if (!array_key_exists('map', $system) || !is_array($system['map'])) {
            return (string)$float;
        }

        $base = array_key_exists('base', $system) ? (float)$system['base'] : 10;

        foreach ($system['map'] as $exp => $prefix) {
            if ($float >= ($base ** $exp)) {
                $float /= ($base ** $exp);

                $formatted = number_format($float, $decimals, $decPoint, $thousandsSep);

                if (!$trailingZeros) {
                    $formatted = self::trimTrailingZeroes($formatted, $decPoint);
                }

                return $formatted . $unitSep . Craft::t('site', (string)$prefix);
            }
        }

        return (string)$float;
    }

    /**
     * Converts a fraction (`1/200`, as found in EXIF data) or numeric string to a float.
     * Non-numeric values result in `0.0`.
     */
    public static function fractionToFloat(mixed $value, int $precision = 4): float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }

        if (!is_string($value)) {
            return 0.0;
        }

        $value = trim($value);

        if (self::isFloat($value)) {
            return (float)$value;
        }

        if (self::isFraction($value)) {
            [$numerator, $denominator] = array_map('trim', explode('/', $value));
            $denominator = (float)$denominator;

            if ($denominator == 0) {
                return 0.0;
            }

            return round((float)$numerator / $denominator, $precision);
        }

        return 0.0;
    }

    /**
     * Converts a decimal number to a fraction (`0.005` → `1/200`).
     */
    public static function floatToFraction(mixed $value, float $tolerance = 0.001): string
    {
        if (!is_numeric($value)) {
            return '0';
        }

        $float = (float)$value;

        if ($float == 0) {
            return '0';
        }

        $sign = $float < 0 ? '-' : '';
        $float = abs($float);

        $h1 = 1;
        $h2 = 0;
        $k1 = 0;
        $k2 = 1;
        $b = 1 / $float;

        do {
            $b = 1 / $b;
            $a = floor($b);
            $aux = $h1;
            $h1 = $a * $h1 + $h2;
            $h2 = $aux;
            $aux = $k1;
            $k1 = $a * $k1 + $k2;
            $k2 = $aux;
            $b -= $a;
        } while ($b != 0 && abs($float - $h1 / $k1) > $float * $tolerance);

        if ($k1 == 1) {
            return $sign . self::_formatNumber($h1);
        }

        return $sign . self::_formatNumber($h1) . '/' . self::_formatNumber($k1);
    }

    /**
     * Returns whether a value is a fraction string like `1/200`.
     */
    public static function isFraction(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[-+]?\d*\.?\d+[ ]?\/[ ]?[-+]?\d*\.?\d+$/', trim($value)) === 1;
    }

    /**
     * Returns whether a value is a (decimal) number.
     */
    public static function isFloat(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        return is_string($value) && preg_match('/^[-+]?\d*\.?\d+$/', trim($value)) === 1;
    }

    /**
     * Trims trailing zeroes (and a dangling decimal point) from a formatted number.
     */
    public static function trimTrailingZeroes(int|float|string $number, string $decPoint = '.'): string
    {
        $number = (string)$number;

        return str_contains($number, $decPoint) ? rtrim(rtrim($number, '0'), $decPoint) : $number;
    }

    // Private Methods
    // =========================================================================

    /**
     * Converts a decimal number to its roman numeral equivalent.
     */
    private static function _roman(int $int, string $case = 'upper'): string
    {
        $map = [1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'];
        $roman = '';

        foreach ($map as $d => $r) {
            $roman .= str_repeat($r, (int)($int / $d));
            $int %= $d;
        }

        return ($case == 'lower') ? strtolower($roman) : $roman;
    }

    /**
     * Converts a decimal number to its alphabetic equivalent.
     */
    private static function _alpha(int $int, string $case = 'upper'): string
    {
        $alpha = '';

        // Bijective base-26: 1 => A, 26 => Z, 27 => AA, …
        while ($int > 0) {
            $int--;
            $alpha = chr(65 + $int % 26) . $alpha;
            $int = intdiv($int, 26);
        }

        return ($case == 'lower') ? strtolower($alpha) : $alpha;
    }

    /**
     * Formats a float that holds an integer value without a decimal part.
     */
    private static function _formatNumber(float $number): string
    {
        return (string)(floor($number) == $number ? (int)$number : $number);
    }

    /**
     * Returns configuration settings for unit prefixes.
     */
    private static function _getUnitPrefixSettings(string $preset): array
    {
        $settings = [];

        switch ($preset) {
            case 'names':
                $settings['map'] = [12 => 'trillion', 9 => 'billion', 6 => 'million', 3 => 'thousand', 2 => 'hundred', 0 => ''];
                break;
            case 'decimal':
            case 'decimalSymbol':
                $settings['map'] = [15 => 'P', 12 => 'T', 9 => 'G', 6 => 'M', 3 => 'k', 0 => '', -2 => 'c', -3 => 'm', -6 => 'µ', -9 => 'n'];
                break;
            case 'decimalNames':
                $settings['map'] = [15 => 'peta', 12 => 'tera', 9 => 'giga', 6 => 'mega', 3 => 'kilo', 0 => '', -2 => 'centi', -3 => 'milli', -6 => 'micro', -9 => 'nano'];
                break;
            case 'binary':
            case 'binarySymbol':
                $settings['base'] = 2;
                $settings['map'] = [50 => 'Pi', 40 => 'Ti', 30 => 'Gi', 20 => 'Mi', 10 => 'Ki', 0 => ''];
                break;
            case 'binaryNames':
                $settings['base'] = 2;
                $settings['map'] = [50 => 'pebi', 40 => 'tebi', 30 => 'gibi', 20 => 'mebi', 10 => 'kibi', 0 => ''];
                break;
        }

        return $settings;
    }
}
