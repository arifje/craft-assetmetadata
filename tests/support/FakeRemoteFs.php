<?php

declare(strict_types=1);

namespace carlcs\assetmetadata\tests\support;

use craft\base\Fs;
use craft\errors\FsException;
use craft\models\FsListing;
use Generator;

/**
 * A filesystem type that stores files in a local directory but does NOT implement `LocalFsInterface`,
 * so the plugin treats it like a remote filesystem (S3, …) and has to download files through streams.
 *
 * Counts stream/read requests and can simulate download failures.
 */
final class FakeRemoteFs extends Fs
{
    public static int $streamCount = 0;
    public static bool $failStreams = false;

    public string $path = '';

    public static function displayName(): string
    {
        return 'Fake remote filesystem (tests)';
    }

    public function getRootUrl(): ?string
    {
        return null;
    }

    public function getFileList(string $directory = '', bool $recursive = true): Generator
    {
        $root = $this->fullPath($directory);

        if (!is_dir($root)) {
            return;
        }

        $iterator = $recursive
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST)
            : new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = ltrim(substr($item->getPathname(), strlen(rtrim($this->path, '/'))), '/');

            yield new FsListing([
                'dirname' => dirname($relative) === '.' ? '' : dirname($relative),
                'basename' => $item->getFilename(),
                'type' => $item->isDir() ? 'dir' : 'file',
                'dateModified' => $item->getMTime(),
                'fileSize' => $item->isDir() ? null : $item->getSize(),
            ]);
        }
    }

    public function getFileSize(string $uri): int
    {
        $size = @filesize($this->fullPath($uri));

        if ($size === false) {
            throw new FsException("File $uri doesn’t exist.");
        }

        return $size;
    }

    public function getDateModified(string $uri): int
    {
        $time = @filemtime($this->fullPath($uri));

        if ($time === false) {
            throw new FsException("File $uri doesn’t exist.");
        }

        return $time;
    }

    public function write(string $path, string $contents, array $config = []): void
    {
        $this->ensureDir(dirname($this->fullPath($path)));
        file_put_contents($this->fullPath($path), $contents);
    }

    public function read(string $path): string
    {
        $contents = @file_get_contents($this->fullPath($path));

        if ($contents === false) {
            throw new FsException("File $path doesn’t exist.");
        }

        return $contents;
    }

    public function writeFileFromStream(string $path, $stream, array $config = []): void
    {
        $this->ensureDir(dirname($this->fullPath($path)));
        $target = fopen($this->fullPath($path), 'wb');
        stream_copy_to_stream($stream, $target);
        fclose($target);
    }

    public function fileExists(string $path): bool
    {
        return is_file($this->fullPath($path));
    }

    public function deleteFile(string $path): void
    {
        @unlink($this->fullPath($path));
    }

    public function renameFile(string $path, string $newPath, array $config = []): void
    {
        $this->ensureDir(dirname($this->fullPath($newPath)));
        rename($this->fullPath($path), $this->fullPath($newPath));
    }

    public function copyFile(string $path, string $newPath, array $config = []): void
    {
        $this->ensureDir(dirname($this->fullPath($newPath)));
        copy($this->fullPath($path), $this->fullPath($newPath));
    }

    public function getFileStream(string $uriPath)
    {
        self::$streamCount++;

        if (self::$failStreams) {
            throw new FsException('Simulated download failure.');
        }

        $stream = @fopen($this->fullPath($uriPath), 'rb');

        if ($stream === false) {
            throw new FsException("File $uriPath doesn’t exist.");
        }

        return $stream;
    }

    public function directoryExists(string $path): bool
    {
        return is_dir($this->fullPath($path));
    }

    public function createDirectory(string $path, array $config = []): void
    {
        $this->ensureDir($this->fullPath($path));
    }

    public function deleteDirectory(string $path): void
    {
        Fixtures::removeDir($this->fullPath($path));
    }

    public function renameDirectory(string $path, string $newName): void
    {
        rename($this->fullPath($path), dirname($this->fullPath($path)) . '/' . $newName);
    }

    private function fullPath(string $path): string
    {
        $root = rtrim($this->path, '/');
        $path = trim($path, '/');

        return $path === '' ? $root : "$root/$path";
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}
