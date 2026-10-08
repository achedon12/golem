<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

/**
 * Prints TeamCity service messages, the format TeamCity and other CI servers read to show
 * a tree of tests with their failures.
 *
 * Golem only learns about a test once it is over, so each test is started and finished
 * at once, with its real duration.
 */
final class TeamCityReporter implements Reporter
{
    private ?string $currentClass = null;

    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream
     */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? STDOUT;
    }

    public function started(RunReport $report, int $count): void
    {
        $this->message('testCount', ['count' => (string) $count]);
    }

    public function testFinished(TestResult $result): void
    {
        if ($result->class !== $this->currentClass) {
            $this->closeSuite();
            $this->currentClass = $result->class;
            $this->message('testSuiteStarted', [
                'name' => $result->shortClass(),
                'locationHint' => "php_qn://{$result->file}::\\{$result->class}",
            ]);
        }

        $name = $result->name();
        $this->message('testStarted', [
            'name' => $name,
            'locationHint' => "php_qn://{$result->file}::\\{$result->class}::{$result->method}",
        ]);

        if ($result->status === TestResult::SKIPPED) {
            $this->message('testIgnored', ['name' => $name, 'message' => (string) $result->message]);
        } elseif ($result->isProblem()) {
            $attributes = [
                'name' => $name,
                'message' => (string) $result->message,
                'details' => $this->details($result),
            ];
            if ($result->expected !== null && $result->actual !== null) {
                $attributes += ['type' => 'comparisonFailure', 'expected' => $result->expected, 'actual' => $result->actual];
            }
            $this->message('testFailed', $attributes);
        }

        $this->message('testFinished', ['name' => $name, 'duration' => (string) (int) round($result->seconds * 1000)]);
    }

    public function finished(RunReport $report, string $serverLogTail): void
    {
        $this->closeSuite();
        if ($report->crashed || $report->abortReason !== null) {
            $message = $report->abortReason ?? 'The server crashed before the tests were over';
            $this->message('testStarted', ['name' => 'server']);
            $this->message('testFailed', ['name' => 'server', 'message' => $message, 'details' => $serverLogTail]);
            $this->message('testFinished', ['name' => 'server']);
        }
    }

    private function closeSuite(): void
    {
        if ($this->currentClass !== null) {
            $short = substr($this->currentClass, (int) strrpos('\\' . $this->currentClass, '\\'));
            $this->message('testSuiteFinished', ['name' => $short]);
            $this->currentClass = null;
        }
    }

    private function details(TestResult $result): string
    {
        $lines = [];
        if ($result->failureFile !== null) {
            $lines[] = "{$result->failureFile}:{$result->failureLine}";
        }

        return implode("\n", [...$lines, ...$result->trace]);
    }

    /**
     * @param array<string, string> $attributes
     */
    private function message(string $name, array $attributes): void
    {
        $text = "##teamcity[$name";
        foreach ($attributes as $key => $value) {
            $text .= " $key='" . self::escape($value) . "'";
        }
        fwrite($this->stream, $text . "]\n");
    }

    public static function escape(string $value): string
    {
        return strtr($value, ['|' => '||', "'" => "|'", "\n" => '|n', "\r" => '|r', '[' => '|[', ']' => '|]']);
    }
}
