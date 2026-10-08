<?php

declare(strict_types=1);

namespace Golem\Runtime;

use Golem\Attribute\DataProvider;
use Golem\Attribute\FreshWorld;
use Golem\Attribute\Skip;
use Golem\Attribute\Test;
use Golem\Attribute\Timeout;
use Golem\Attribute\World;
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
     * @param list<string>|null $only only the tests declared in these files (when running in parallel)
     * @return list<TestDefinition>
     */
    public static function discover(string $directory, ?string $filter, ?array $only = null): array
    {
        $only = $only !== null ? array_map(self::normalize(...), $only) : null;
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
            if ($only !== null && !in_array(self::normalize($file), $only, true)) {
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
                    $method->getAttributes(FreshWorld::class) !== [] || $reflection->getAttributes(FreshWorld::class) !== [],
                    worldTemplate: ($method->getAttributes(World::class)[0] ?? $reflection->getAttributes(World::class)[0] ?? null)?->newInstance()->path,
                );
                foreach (self::expand($definition, $method, $reflection) as $test) {
                    if ($filter !== null && $filter !== '' && stripos($test->id(), $filter) === false) {
                        continue;
                    }
                    $tests[] = $test;
                }
            }
        }

        usort($tests, static fn (TestDefinition $a, TestDefinition $b) => [$a->file, $a->line] <=> [$b->file, $b->line]);

        return $tests;
    }

    /**
     * One definition per data set of the method's #[DataProvider], or the method alone.
     *
     * @param \ReflectionClass<TestCase> $class
     * @return list<TestDefinition>
     */
    private static function expand(TestDefinition $definition, \ReflectionMethod $method, \ReflectionClass $class): array
    {
        $attribute = $method->getAttributes(DataProvider::class)[0] ?? null;
        if ($attribute === null) {
            return [$definition];
        }

        $providerName = $attribute->newInstance()->method;
        $where = $class->getShortName() . '::' . $method->getName();
        if (!$class->hasMethod($providerName)) {
            throw new \RuntimeException("The data provider $providerName() of $where does not exist");
        }
        $provider = $class->getMethod($providerName);
        if (!$provider->isPublic() || !$provider->isStatic()) {
            throw new \RuntimeException("The data provider $providerName() of $where must be public and static");
        }

        $data = $provider->invoke(null);
        if (!is_iterable($data)) {
            throw new \RuntimeException("The data provider $providerName() of $where must return an iterable");
        }

        $tests = [];
        $index = 0;
        foreach ($data as $key => $arguments) {
            if (!is_array($arguments)) {
                throw new \RuntimeException("Data set " . var_export($key, true) . " of $providerName() must be an array of arguments");
            }
            $name = is_string($key) ? $key : '#' . $index;
            $tests[] = $definition->withData($name, array_values($arguments));
            $index++;
        }
        if ($tests === []) {
            throw new \RuntimeException("The data provider $providerName() of $where returned no data");
        }

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
