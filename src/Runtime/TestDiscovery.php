<?php

declare(strict_types=1);

namespace Golem\Runtime;

use Golem\Attribute\Skip;
use Golem\Attribute\Test;
use Golem\Attribute\Timeout;
use Golem\TestCase;

/**
 * Finds test methods in a directory.
 *
 * @internal
 */
final class TestDiscovery
{
    public const DEFAULT_TIMEOUT_TICKS = 200;

    /**
     * @param string|null $filter case-insensitive substring matched against "Class::method"
     * @return list<TestDefinition>
     */
    public static function discover(string $directory, ?string $filter): array
    {
        $directory = self::normalize($directory);
        $files = self::phpFiles($directory);

        // Test files are loaded in alphabetical order, so a test may extend a helper
        // class that is not loaded yet: resolve those by file name.
        $byName = [];
        foreach ($files as $file) {
            $byName[strtolower(basename($file, '.php'))] = $file;
        }
        spl_autoload_register(static function (string $class) use ($byName): void {
            $short = strtolower(substr($class, (int) strrpos('\\' . $class, '\\')));
            if (isset($byName[$short])) {
                require_once $byName[$short];
            }
        });

        foreach ($files as $file) {
            require_once $file;
        }

        $tests = [];
        foreach (get_declared_classes() as $class) {
            if (!is_subclass_of($class, TestCase::class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            $file = $reflection->getFileName();
            if ($reflection->isAbstract() || $file === false || !str_starts_with(self::normalize($file), $directory . '/')) {
                continue;
            }

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->getDeclaringClass()->getName() === TestCase::class) {
                    continue;
                }
                $isTest = str_starts_with($method->getName(), 'test') || $method->getAttributes(Test::class) !== [];
                if (!$isTest) {
                    continue;
                }
                $definition = new TestDefinition(
                    $class,
                    $method->getName(),
                    $file,
                    $method->getStartLine() ?: 0,
                    self::timeout($method, $reflection),
                    self::skipReason($method, $reflection),
                );
                if ($filter !== null && $filter !== '' && stripos($definition->id(), $filter) === false) {
                    continue;
                }
                $tests[] = $definition;
            }
        }

        usort($tests, static fn (TestDefinition $a, TestDefinition $b) => [$a->file, $a->line] <=> [$b->file, $b->line]);

        return $tests;
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException("The tests directory \"$directory\" does not exist");
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param \ReflectionClass<TestCase> $class
     */
    private static function timeout(\ReflectionMethod $method, \ReflectionClass $class): int
    {
        $attribute = $method->getAttributes(Timeout::class)[0] ?? $class->getAttributes(Timeout::class)[0] ?? null;

        return $attribute?->newInstance()->ticks ?? self::DEFAULT_TIMEOUT_TICKS;
    }

    /**
     * @param \ReflectionClass<TestCase> $class
     */
    private static function skipReason(\ReflectionMethod $method, \ReflectionClass $class): ?string
    {
        $attribute = $method->getAttributes(Skip::class)[0] ?? $class->getAttributes(Skip::class)[0] ?? null;

        return $attribute?->newInstance()->reason;
    }

    private static function normalize(string $path): string
    {
        $real = realpath($path);

        return rtrim(str_replace('\\', '/', $real === false ? $path : $real), '/');
    }
}
