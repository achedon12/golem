<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

/**
 * On GitHub Actions, turns failures into annotations shown inline on the pull request diff.
 */
final class GitHubReporter
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    public static function isAvailable(): bool
    {
        return getenv('GITHUB_ACTIONS') === 'true';
    }

    public function write(RunReport $report): void
    {
        foreach ($report->results as $result) {
            if (!$result->isProblem()) {
                continue;
            }
            $message = (string) $result->message;
            if ($result->expected !== null || $result->actual !== null) {
                $message .= "\nExpected: " . $result->expected . "\nActual:   " . $result->actual;
            }

            echo sprintf(
                "::error file=%s,line=%d,title=%s::%s\n",
                $this->escapeProperty($this->relative($result->failureFile ?? $result->file)),
                $result->failureLine ?? $result->line,
                $this->escapeProperty($result->shortClass() . ' › ' . $result->description()),
                $this->escapeData($message),
            );
        }
    }

    private function relative(string $path): string
    {
        $workspace = getenv('GITHUB_WORKSPACE');
        $root = is_string($workspace) && $workspace !== '' ? $workspace : $this->projectRoot;
        $root = rtrim($root, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private function escapeData(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private function escapeProperty(string $value): string
    {
        return str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $value);
    }
}
