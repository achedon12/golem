<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

/**
 * A summary of the run in Markdown: for the pull request comment of the GitHub Action, and
 * the job summary of GitHub Actions.
 */
final class MarkdownReporter
{
    /** the first line of the comment, so the action finds it again to update it */
    public const MARKER = '<!-- golem-report -->';

    private const MAX_FAILURES = 10;

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function markdown(RunReport $report): string
    {
        $passed = $report->count(TestResult::PASSED);
        $failed = $report->count(TestResult::FAILED) + $report->count(TestResult::ERRORED);
        $skipped = $report->count(TestResult::SKIPPED);
        $title = match (true) {
            $report->crashed => '💥 The server crashed',
            $report->abortReason !== null => '⛔ The run stopped',
            $failed > 0 => "❌ $failed failed, $passed passed",
            default => "✅ $passed passed",
        };

        $lines = [
            self::MARKER,
            "### Golem: $title",
            '',
            sprintf(
                '%s · PocketMine-MP %s · %d assertion%s · %.1f s',
                $report->plugin ?? 'plugin',
                $report->pocketmine ?? $report->label ?? '?',
                $report->assertions(),
                $report->assertions() === 1 ? '' : 's',
                $report->totalSeconds,
            ),
            '',
            '| Passed | Failed | Skipped |' . ($report->coverage !== null ? ' Coverage |' : ''),
            '| ---: | ---: | ---: |' . ($report->coverage !== null ? ' ---: |' : ''),
            sprintf('| %d | %d | %d |', $passed, $failed, $skipped) . ($report->coverage !== null ? ' ' . $this->coverage($report->coverage) . ' |' : ''),
        ];

        if ($report->abortReason !== null) {
            array_push($lines, '', '> ' . $report->abortReason);
        }

        $problems = array_values(array_filter($report->results, static fn (TestResult $r) => $r->isProblem()));
        if ($problems !== []) {
            array_push($lines, '', '#### Failures', '');
            foreach (array_slice($problems, 0, self::MAX_FAILURES) as $result) {
                $location = $this->relative($result->failureFile ?? $result->file) . ':' . ($result->failureLine ?? $result->line);
                $lines[] = sprintf('- **%s › %s** (`%s`): %s', $result->shortClass(), $result->description(), $location, self::inline((string) $result->message));
                if ($result->expected !== null || $result->actual !== null) {
                    $lines[] = sprintf('  expected `%s`, got `%s`', self::inline((string) $result->expected), self::inline((string) $result->actual));
                }
            }
            if (count($problems) > self::MAX_FAILURES) {
                $lines[] = sprintf('- …and %d more', count($problems) - self::MAX_FAILURES);
            }
        }

        $flaky = $report->flaky();
        if ($flaky !== []) {
            array_push($lines, '', '#### Flaky', '');
            foreach ($flaky as $entry) {
                $lines[] = sprintf('- %s › %s: failed %d of %d runs', $entry['result']->shortClass(), $entry['result']->description(), $entry['failed'], $entry['passed'] + $entry['failed']);
            }
        }

        $lines[] = '';
        $lines[] = '<sub>Tested with [Golem](https://github.com/achedon12/golem).</sub>';

        return implode("\n", $lines) . "\n";
    }

    public function write(RunReport $report, string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($path, $this->markdown($report));
    }

    /**
     * @param array{commands: array<string, int>, listeners: array<string, int>, lines: array<string, array<int, int>>|null} $coverage
     */
    private function coverage(array $coverage): string
    {
        $lines = $coverage['lines'] ?? [];
        if ($lines !== []) {
            $total = array_sum(array_map('count', $lines));
            $ran = array_sum(array_map(static fn (array $file) => count(array_filter($file)), $lines));

            return sprintf('%.1f%% of lines', $total > 0 ? $ran / $total * 100 : 100);
        }
        $all = array_merge(array_values($coverage['commands']), array_values($coverage['listeners']));

        return sprintf('%d/%d commands and listeners', count(array_filter($all)), count($all));
    }

    private function relative(string $path): string
    {
        $root = rtrim($this->projectRoot, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private static function inline(string $text): string
    {
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return str_replace(['`', '|'], ["'", '\\|'], mb_strlen($text) > 160 ? mb_substr($text, 0, 157) . '…' : $text);
    }
}
