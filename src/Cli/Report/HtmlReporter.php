<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

/**
 * A self-contained HTML report of the run, to keep as a CI artifact or open in a browser:
 * results by class, failures with their details and code, and coverage.
 */
final class HtmlReporter
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    public function write(RunReport $report, string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($path, $this->html($report));
    }

    public function html(RunReport $report): string
    {
        $passed = $report->count(TestResult::PASSED);
        $failed = $report->count(TestResult::FAILED) + $report->count(TestResult::ERRORED);
        $skipped = $report->count(TestResult::SKIPPED);
        $ok = $report->isSuccessful();

        $classes = [];
        foreach ($report->results as $result) {
            $classes[$result->shortClass()][] = $result;
        }

        $body = '';
        foreach ($classes as $class => $results) {
            $body .= '<section><h2>' . $this->e($class) . '</h2>';
            foreach ($results as $result) {
                $body .= $this->test($result);
            }
            $body .= '</section>';
        }

        $title = sprintf('Golem · %s', $report->plugin ?? 'tests');
        $summary = sprintf(
            '<span class="badge %s">%s</span> %d passed%s%s · %d assertions · %.2f s',
            $ok ? 'pass' : 'fail',
            $report->crashed ? 'CRASHED' : ($ok ? 'PASSED' : 'FAILED'),
            $passed,
            $failed > 0 ? ", <b class=\"red\">$failed failed</b>" : '',
            $skipped > 0 ? ", $skipped skipped" : '',
            $report->assertions(),
            $report->totalSeconds,
        );
        $meta = $this->e(sprintf('%s · PocketMine-MP %s · %s', $report->plugin ?? '', $report->pocketmine ?? $report->label ?? '?', date('Y-m-d H:i')));

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$this->e($title)}</title>
            <style>
            :root { --bg: #0d1117; --panel: #161b22; --border: #2a313c; --text: #e6edf3; --muted: #9da7b3; --green: #22c55e; --red: #f85149; --yellow: #d29922; --cyan: #39c5cf; color-scheme: dark; }
            @media (prefers-color-scheme: light) { :root { --bg: #ffffff; --panel: #f6f8fa; --border: #d0d7de; --text: #1f2328; --muted: #57606a; --green: #1a7f37; --red: #cf222e; --yellow: #9a6700; --cyan: #0969da; color-scheme: light; } }
            * { box-sizing: border-box; }
            body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
            main { max-width: 1100px; margin: 0 auto; padding: 32px 20px 60px; }
            h1 { font: 700 26px ui-monospace, monospace; margin: 0; }
            h2 { font-size: 16px; margin: 28px 0 8px; }
            .meta { color: var(--muted); margin: 4px 0 18px; }
            .summary { font-size: 17px; padding: 14px 18px; border: 1px solid var(--border); border-radius: 10px; background: var(--panel); }
            .badge { font: 700 13px ui-monospace, monospace; padding: 3px 10px; border-radius: 5px; margin-right: 6px; }
            .badge.pass { background: var(--green); color: #fff; } .badge.fail { background: var(--red); color: #fff; }
            .red { color: var(--red); }
            details.test { border-bottom: 1px solid var(--border); }
            details.test summary { list-style: none; display: flex; gap: 10px; padding: 6px 4px; cursor: pointer; }
            details.test summary::-webkit-details-marker { display: none; }
            .icon { width: 16px; font-weight: 700; } .passed .icon { color: var(--green); } .failed .icon, .errored .icon { color: var(--red); } .skipped .icon { color: var(--yellow); }
            .failed .name, .errored .name { color: var(--red); }
            .name { flex: 1; } .time { color: var(--muted); font: 13px ui-monospace, monospace; }
            .detail { padding: 6px 30px 14px; }
            pre { background: var(--panel); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; overflow-x: auto; font: 13px/1.5 ui-monospace, monospace; }
            .label { color: var(--muted); font: 12px ui-monospace, monospace; }
            .location { color: var(--cyan); font: 13px ui-monospace, monospace; }
            table { border-collapse: collapse; width: 100%; font-size: 14px; }
            td, th { text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--border); }
            .bar { width: 160px; height: 8px; background: var(--border); border-radius: 4px; overflow: hidden; display: inline-block; vertical-align: middle; }
            .bar i { display: block; height: 100%; background: var(--green); }
            footer { margin-top: 40px; color: var(--muted); font-size: 13px; }
            footer a { color: var(--cyan); }
            </style>
            </head>
            <body>
            <main>
            <h1>golem</h1>
            <p class="meta">{$meta}</p>
            <div class="summary">{$summary}</div>
            {$this->problems($report)}
            {$body}
            {$this->coverage($report)}
            <footer>Generated by <a href="https://github.com/achedon12/golem">Golem</a>, integration tests for PocketMine-MP plugins.</footer>
            </main>
            </body>
            </html>

            HTML;
    }

    private function test(TestResult $result): string
    {
        $icon = match ($result->status) {
            TestResult::PASSED => '✓',
            TestResult::SKIPPED => '–',
            default => '✗',
        };
        $time = $result->status === TestResult::SKIPPED ? $this->e((string) $result->message) : sprintf('%.0f ms · %d ticks', $result->seconds * 1000, $result->ticks);
        $name = $result->description() . ($result->repetition > 1 ? " (run {$result->repetition})" : '');
        $detail = '';
        if ($result->isProblem()) {
            $detail .= '<p class="red">' . $this->e((string) $result->message) . '</p>';
            if ($result->expected !== null) {
                $detail .= '<div class="label">expected</div><pre>' . $this->e($result->expected) . '</pre>';
            }
            if ($result->actual !== null) {
                $detail .= '<div class="label">actual</div><pre>' . $this->e($result->actual) . '</pre>';
            }
            $file = $result->failureFile ?? $result->file;
            $line = $result->failureLine ?? $result->line;
            $detail .= '<p class="location">' . $this->e($this->relative($file) . ':' . $line) . '</p>';
            $snippet = $this->snippet($file, $line);
            if ($snippet !== '') {
                $detail .= '<pre>' . $snippet . '</pre>';
            }
            if ($result->trace !== []) {
                $detail .= '<pre>' . $this->e(implode("\n", $result->trace)) . '</pre>';
            }
        }

        return sprintf(
            '<details class="test %s"%s><summary><span class="icon">%s</span><span class="name">%s</span><span class="time">%s</span></summary>%s</details>',
            $result->status,
            $result->isProblem() ? ' open' : '',
            $icon,
            $this->e($name),
            $time,
            $detail !== '' ? '<div class="detail">' . $detail . '</div>' : '',
        );
    }

    private function problems(RunReport $report): string
    {
        $html = '';
        if ($report->abortReason !== null) {
            $html .= '<p class="red">' . $this->e($report->abortReason) . '</p>';
        }
        $flaky = $report->flaky();
        if ($flaky !== []) {
            $html .= '<h2>Flaky tests</h2><ul>';
            foreach ($flaky as $entry) {
                $html .= sprintf('<li>%s › %s: failed %d of %d runs</li>', $this->e($entry['result']->shortClass()), $this->e($entry['result']->description()), $entry['failed'], $entry['passed'] + $entry['failed']);
            }
            $html .= '</ul>';
        }

        return $html;
    }

    private function coverage(RunReport $report): string
    {
        if ($report->coverage === null) {
            return '';
        }
        $html = '<section><h2>Coverage</h2>';
        $missed = static fn (array $counts) => array_keys(array_filter($counts, static fn (int $n) => $n === 0));
        $html .= sprintf(
            '<p>Commands %d/%d · listeners %d/%d</p>',
            count($report->coverage['commands']) - count($missed($report->coverage['commands'])),
            count($report->coverage['commands']),
            count($report->coverage['listeners']) - count($missed($report->coverage['listeners'])),
            count($report->coverage['listeners']),
        );
        $lines = $report->coverage['lines'] ?? [];
        if ($lines !== []) {
            $html .= '<table><tr><th>File</th><th>Lines</th><th></th><th>Not run</th></tr>';
            foreach ($lines as $file => $fileLines) {
                $ran = count(array_filter($fileLines));
                $ratio = count($fileLines) > 0 ? $ran / count($fileLines) : 1;
                $notRun = array_keys(array_filter($fileLines, static fn (int $r) => $r === 0));
                $html .= sprintf(
                    '<tr><td>src/%s</td><td>%.0f%% (%d/%d)</td><td><span class="bar"><i style="width:%.1f%%"></i></span></td><td class="label">%s</td></tr>',
                    $this->e($file),
                    $ratio * 100,
                    $ran,
                    count($fileLines),
                    $ratio * 100,
                    $this->e(implode(', ', $notRun)),
                );
            }
            $html .= '</table>';
        }

        return $html . '</section>';
    }

    private function snippet(string $file, int $line): string
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return '';
        }
        $html = '';
        for ($n = max(1, $line - 3); $n <= min(count($lines), $line + 2); $n++) {
            $text = sprintf('%4d │ %s', $n, $lines[$n - 1]);
            $html .= $n === $line ? '<b class="red">' . $this->e($text) . '</b>' . "\n" : $this->e($text) . "\n";
        }

        return rtrim($html);
    }

    private function relative(string $path): string
    {
        $root = rtrim($this->projectRoot, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private function e(string $text): string
    {
        return self::escape($text);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
