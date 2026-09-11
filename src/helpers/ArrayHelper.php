<?php

namespace carlcs\assetmetadata\helpers;

class ArrayHelper
{
    /**
     * Returns a value from a nested array using dot notation (`jpg.exif.EXIF.Model`), or `null` if the
     * path doesn’t exist.
     */
    public static function getValueByKey(string $path, array $data): mixed
    {
        if (!str_contains($path, '.')) {
            return $data[$path] ?? null;
        }

        foreach (explode('.', $path) as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }

            $data = $data[$key];
        }

        return $data;
    }
}
