<?php

declare(strict_types=1);

namespace carlcs\assetmetadata\tests\support;

use RuntimeException;

/**
 * Builds disposable media fixtures in a temporary directory. Nothing is read from or written to any
 * project’s assets; every file is generated from scratch.
 */
final class Fixtures
{
    /** Latitude/longitude of Amsterdam Centraal, used for GPS fixtures. */
    public const GPS_LAT = 52.379189;
    public const GPS_LON = 4.899431;

    /**
     * Creates a fresh, unique temporary directory for fixtures.
     */
    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/asset-metadata-tests/' . uniqid('', true);

        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create $dir");
        }

        return $dir;
    }

    /**
     * Recursively deletes a directory created with `tempDir()`.
     */
    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    /**
     * Writes a JPEG file, optionally with an EXIF APP1 segment.
     *
     * Supported `$exif` keys: `Make`, `Model`, `ImageDescription`, `Orientation`, `DateTimeOriginal`,
     * `ExposureTime` ([num, den]), `FNumber` ([num, den]), `ISOSpeedRatings`, and `gps` (['lat' => float, 'lon' => float]).
     */
    public static function jpeg(string $path, array $exif = [], int $width = 64, int $height = 48): string
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 40, 120, 200);
        imagefilledrectangle($image, 0, 0, $width, $height, $color);
        imagejpeg($image, $path, 80);

        if ($exif !== []) {
            $data = (string)file_get_contents($path);
            $segment = self::exifSegment($exif);
            // Insert the APP1 segment right after the SOI marker
            file_put_contents($path, substr($data, 0, 2) . $segment . substr($data, 2));
        }

        return $path;
    }

    /**
     * Writes a PNG file.
     */
    public static function png(string $path, int $width = 32, int $height = 20): string
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 200, 40, 40);
        imagefilledrectangle($image, 0, 0, $width, $height, $color);
        imagepng($image, $path);

        return $path;
    }

    /**
     * Writes an MP3 file: an ID3v2.4 tag (UTF-8 text frames), `$frames` silent MPEG-1 Layer III frames
     * (128 kbps, 44.1 kHz; each frame is 1152 samples ≈ 26 ms) and, optionally, an ID3v1 tag.
     *
     * @param array<string, string> $id3v2 Text frames keyed by frame ID (e.g. `TIT2`, `TPE1`, `TALB`)
     * @param array<string, string>|null $id3v1 `title`, `artist`, `album`, `year`, `comment` (ISO-8859-1)
     */
    public static function mp3(string $path, array $id3v2 = ['TIT2' => 'Fixture title', 'TPE1' => 'Fixture artist'], int $frames = 100, ?array $id3v1 = null): string
    {
        $tagBody = '';

        foreach ($id3v2 as $frameId => $text) {
            $frameData = "\x03" . $text; // encoding 3 = UTF-8 (ID3v2.4)
            $tagBody .= $frameId . self::syncsafe(strlen($frameData)) . "\x00\x00" . $frameData;
        }

        $tag = 'ID3' . "\x04\x00" . "\x00" . self::syncsafe(strlen($tagBody)) . $tagBody;

        // MPEG-1 Layer III, no CRC, 128 kbps, 44.1 kHz, no padding, stereo → 417 bytes per frame
        $frame = "\xFF\xFB\x90\x00" . str_repeat("\x00", 413);
        $audio = str_repeat($frame, $frames);

        $v1 = '';

        if ($id3v1 !== null) {
            $v1 = 'TAG'
                . self::fixed($id3v1['title'] ?? '', 30)
                . self::fixed($id3v1['artist'] ?? '', 30)
                . self::fixed($id3v1['album'] ?? '', 30)
                . self::fixed($id3v1['year'] ?? '', 4)
                . self::fixed($id3v1['comment'] ?? '', 30)
                . "\xFF";
        }

        file_put_contents($path, $tag . $audio . $v1);

        return $path;
    }

    /**
     * Writes a plain text file.
     */
    public static function text(string $path, string $content = "Just some text.\n"): string
    {
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Writes a file with the first `$bytes` bytes of another file (a truncated/corrupt copy).
     */
    public static function truncated(string $source, string $path, int $bytes): string
    {
        file_put_contents($path, substr((string)file_get_contents($source), 0, $bytes));

        return $path;
    }

    /**
     * Writes a file with deterministic pseudo-random bytes that can’t be mistaken for any media format
     * (no 0xFF bytes, so no MPEG sync words or JPEG markers can occur).
     */
    public static function garbage(string $path, int $bytes = 4096): string
    {
        mt_srand(20240506);
        $data = '';

        for ($i = 0; $i < $bytes; $i++) {
            $data .= chr(mt_rand(1, 254));
        }

        file_put_contents($path, $data);

        return $path;
    }

    // EXIF building
    // =========================================================================

    private const TYPE_BYTE = 1;
    private const TYPE_ASCII = 2;
    private const TYPE_SHORT = 3;
    private const TYPE_LONG = 4;
    private const TYPE_RATIONAL = 5;

    /**
     * Builds an APP1 (EXIF) segment with IFD0, an Exif sub-IFD and (optionally) a GPS IFD.
     */
    public static function exifSegment(array $exif): string
    {
        $tiffHeader = 'II' . pack('v', 42) . pack('V', 8);

        $ifd0 = [];
        if (isset($exif['ImageDescription'])) {
            $ifd0[] = [0x010E, self::TYPE_ASCII, (string)$exif['ImageDescription']];
        }
        if (isset($exif['Make'])) {
            $ifd0[] = [0x010F, self::TYPE_ASCII, (string)$exif['Make']];
        }
        if (isset($exif['Model'])) {
            $ifd0[] = [0x0110, self::TYPE_ASCII, (string)$exif['Model']];
        }
        if (isset($exif['Orientation'])) {
            $ifd0[] = [0x0112, self::TYPE_SHORT, [(int)$exif['Orientation']]];
        }

        $exifIfd = [];
        if (isset($exif['ExposureTime'])) {
            $exifIfd[] = [0x829A, self::TYPE_RATIONAL, [$exif['ExposureTime']]];
        }
        if (isset($exif['FNumber'])) {
            $exifIfd[] = [0x829D, self::TYPE_RATIONAL, [$exif['FNumber']]];
        }
        if (isset($exif['ISOSpeedRatings'])) {
            $exifIfd[] = [0x8827, self::TYPE_SHORT, [(int)$exif['ISOSpeedRatings']]];
        }
        if (isset($exif['DateTimeOriginal'])) {
            $exifIfd[] = [0x9003, self::TYPE_ASCII, (string)$exif['DateTimeOriginal']];
        }

        $gpsIfd = [];
        if (isset($exif['gps'])) {
            $lat = (float)$exif['gps']['lat'];
            $lon = (float)$exif['gps']['lon'];
            $gpsIfd[] = [0x0000, self::TYPE_BYTE, [2, 3, 0, 0]];
            $gpsIfd[] = [0x0001, self::TYPE_ASCII, $lat < 0 ? 'S' : 'N'];
            $gpsIfd[] = [0x0002, self::TYPE_RATIONAL, self::dms(abs($lat))];
            $gpsIfd[] = [0x0003, self::TYPE_ASCII, $lon < 0 ? 'W' : 'E'];
            $gpsIfd[] = [0x0004, self::TYPE_RATIONAL, self::dms(abs($lon))];
        }

        // Pointer entries are 4-byte LONGs stored inline, so IFD0’s size doesn’t depend on their values.
        $ifd0WithPointers = static function(int $exifOffset, int $gpsOffset) use ($ifd0, $exifIfd, $gpsIfd): array {
            $entries = $ifd0;
            if ($exifIfd !== []) {
                $entries[] = [0x8769, self::TYPE_LONG, [$exifOffset]];
            }
            if ($gpsIfd !== []) {
                $entries[] = [0x8825, self::TYPE_LONG, [$gpsOffset]];
            }
            return $entries;
        };

        $ifd0Offset = 8;
        $ifd0Length = strlen(self::ifdBlock($ifd0WithPointers(0, 0), $ifd0Offset));
        $exifOffset = $ifd0Offset + $ifd0Length;
        $exifBlock = $exifIfd !== [] ? self::ifdBlock($exifIfd, $exifOffset) : '';
        $gpsOffset = $exifOffset + strlen($exifBlock);
        $gpsBlock = $gpsIfd !== [] ? self::ifdBlock($gpsIfd, $gpsOffset) : '';
        $ifd0Block = self::ifdBlock($ifd0WithPointers($exifOffset, $gpsOffset), $ifd0Offset);

        $payload = "Exif\x00\x00" . $tiffHeader . $ifd0Block . $exifBlock . $gpsBlock;

        return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    }

    /**
     * Packs an IFD (entry count, sorted entries, next-IFD pointer, out-of-line data) starting at `$ifdOffset`.
     *
     * @param array<int, array{0: int, 1: int, 2: mixed}> $entries [tag, type, value(s)]
     */
    private static function ifdBlock(array $entries, int $ifdOffset): string
    {
        usort($entries, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        $count = count($entries);
        $dataOffset = $ifdOffset + 2 + $count * 12 + 4;
        $entriesBin = '';
        $data = '';

        foreach ($entries as [$tag, $type, $values]) {
            [$valueCount, $bin] = self::packValues($type, $values);

            if (strlen($bin) <= 4) {
                $valueField = str_pad($bin, 4, "\x00");
            } else {
                $valueField = pack('V', $dataOffset + strlen($data));
                $data .= $bin;
                if (strlen($bin) % 2 === 1) {
                    $data .= "\x00";
                }
            }

            $entriesBin .= pack('vvV', $tag, $type, $valueCount) . $valueField;
        }

        return pack('v', $count) . $entriesBin . pack('V', 0) . $data;
    }

    /**
     * @return array{0: int, 1: string} [count, packed bytes]
     */
    private static function packValues(int $type, mixed $values): array
    {
        switch ($type) {
            case self::TYPE_BYTE:
                return [count($values), pack('C*', ...$values)];
            case self::TYPE_ASCII:
                $string = (string)$values . "\x00";
                return [strlen($string), $string];
            case self::TYPE_SHORT:
                return [count($values), pack('v*', ...$values)];
            case self::TYPE_LONG:
                return [count($values), pack('V*', ...$values)];
            case self::TYPE_RATIONAL:
                $bin = '';
                foreach ($values as [$numerator, $denominator]) {
                    $bin .= pack('VV', $numerator, $denominator);
                }
                return [count($values), $bin];
        }

        throw new RuntimeException("Unsupported EXIF type $type");
    }

    /**
     * Converts a decimal coordinate to degrees/minutes/seconds rationals (seconds with 1/100 precision).
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function dms(float $decimal): array
    {
        $degrees = (int)floor($decimal);
        $minutesFloat = ($decimal - $degrees) * 60;
        $minutes = (int)floor($minutesFloat);
        $seconds = (int)round(($minutesFloat - $minutes) * 60 * 100);

        return [[$degrees, 1], [$minutes, 1], [$seconds, 100]];
    }

    private static function syncsafe(int $size): string
    {
        return chr(($size >> 21) & 0x7F) . chr(($size >> 14) & 0x7F) . chr(($size >> 7) & 0x7F) . chr($size & 0x7F);
    }

    private static function fixed(string $value, int $length): string
    {
        return str_pad(substr($value, 0, $length), $length, "\x00");
    }
}
