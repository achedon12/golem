<?php

declare(strict_types=1);

namespace Golem\Cli;

/**
 * Notices when the files of a plugin change, by polling their modification times:
 * no extension needed, and cheap enough for a plugin-sized tree.
 */
final class ChangeWatcher
{
    private const POLL_US = 300_000;

    private string $fingerprint;

    /**
     * @param list<string> $paths files and folders to watch
     */
    public function __construct(private readonly array $paths)
    {
        $this->fingerprint = $this->fingerprint();
    }

    /**
     * Blocks until something changes, then waits for the changes to settle (editors
     * often write a file in several steps).
     */
    public function waitForChange(): void
    {
        while ($this->fingerprint() === $this->fingerprint) {
            usleep(self::POLL_US);
        }
        do {
            $settled = $this->fingerprint();
            usleep(self::POLL_US);
        } while ($this->fingerprint() !== $settled);

        $this->fingerprint = $settled;
    }

    private function fingerprint(): string
    {
        clearstatcache();
        $state = [];
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                $state[$path] = filemtime($path) . ':' . filesize($path);
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            /** @var \SplFileInfo $file */
            foreach ($files as $file) {
                $state[$file->getPathname()] = $file->getMTime() . ':' . $file->getSize();
            }
        }
        ksort($state);

        return md5(serialize($state));
    }
}
