<?php

declare(strict_types=1);

namespace Golem\Cli\Ui;

/**
 * The tests of a plugin, read from the source of its test files: the dashboard lists them
 * without starting a server.
 */
final class TestCatalog
{
    /**
     * @return list<array{class: string, short: string, file: string, line: int, tests: list<array{method: string, line: int}>}>
     */
    public static function read(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && !str_contains($file->getPathname(), '__snapshots__')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $classes = [];
        foreach ($files as $file) {
            $class = self::parse($file);
            if ($class !== null && $class['tests'] !== []) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return array{class: string, short: string, file: string, line: int, tests: list<array{method: string, line: int}>}|null
     */
    private static function parse(string $file): ?array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $namespace = '';
        $class = null;
        $classLine = 0;
        $tests = [];
        $attributes = '';
        foreach ($lines as $index => $line) {
            if (preg_match('/^namespace\s+([^;]+);/', $line, $match) === 1) {
                $namespace = trim($match[1]);
            } elseif ($class === null && preg_match('/^\s*(?:final\s+|abstract\s+)*class\s+(\w+)\s+extends\s+/', $line, $match) === 1) {
                if (str_contains($line, 'abstract')) {
                    return null;
                }
                $class = $match[1];
                $classLine = $index + 1;
            } elseif (str_contains($line, '#[')) {
                $attributes .= $line;
            } elseif (preg_match('/^\s*public\s+function\s+(\w+)\s*\(/', $line, $match) === 1) {
                if (str_starts_with($match[1], 'test') || preg_match('/#\[\s*(\\\\?Golem\\\\Attribute\\\\)?Test\s*[\](]/', $attributes) === 1) {
                    $tests[] = ['method' => $match[1], 'line' => $index + 1];
                }
                $attributes = '';
            } elseif (trim($line) !== '' && !str_starts_with(trim($line), '*') && !str_starts_with(trim($line), '/')) {
                $attributes = '';
            }
        }
        if ($class === null) {
            return null;
        }

        return [
            'class' => $namespace !== '' ? "$namespace\\$class" : $class,
            'short' => $class,
            'file' => $file,
            'line' => $classLine,
            'tests' => $tests,
        ];
    }
}
