<?php

declare(strict_types=1);

namespace Mazaya\License\Guard;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * A digest of the licensing code as it was when the licence was issued.
 *
 * Folded into the configuration seal, so editing or deleting any part of this
 * package invalidates the licence rather than disabling it. That is the point:
 * a check that can be commented out is not a check.
 *
 * Only this package's own files are covered. The host application's routes and
 * bootstrap deliberately are not — they change with every release of the
 * product, and sealing them would mean a forgotten `license:reseal` after a
 * deploy takes a paying customer offline. Those are reported to the licence
 * server instead, where a change is visible without being fatal.
 */
final class Integrity
{
    private ?string $cached = null;

    public function __construct(private readonly string $root) {}

    /** Hex digest over every PHP file in the package, path and content. */
    public function digest(): string
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $hashes = [];

        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = ltrim(substr($file->getPathname(), strlen($this->root)), DIRECTORY_SEPARATOR);
                $hashes[$relative] = hash_file('sha256', $file->getPathname());
            }
        } catch (Throwable) {
            // An unreadable package directory is itself a reason to distrust
            // the installation, so it must not hash to the same thing twice.
            return $this->cached = 'unreadable';
        }

        if ($hashes === []) {
            return $this->cached = 'empty';
        }

        // Sorted so the digest depends on the files, not on directory order.
        ksort($hashes);

        return $this->cached = hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    }

    /**
     * A separate digest of the host application's licensing-adjacent files,
     * reported on each heartbeat so a change shows up on the dashboard.
     *
     * @return array<string, string>
     */
    public function hostFiles(string $basePath): array
    {
        $paths = array_merge(
            glob($basePath.'/routes/*.php') ?: [],
            [$basePath.'/bootstrap/app.php', $basePath.'/bootstrap/providers.php'],
        );

        $hashes = [];

        foreach ($paths as $path) {
            if (is_readable($path)) {
                $hashes[basename(dirname($path)).'/'.basename($path)] = substr((string) hash_file('sha256', $path), 0, 16);
            }
        }

        return $hashes;
    }
}
