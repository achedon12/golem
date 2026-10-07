<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

/**
 * Writes a JUnit XML file, the format CI systems understand.
 */
final class JUnitReporter
{
    public function write(RunReport $report, string $path): void
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $suites = $document->createElement('testsuites');
        $document->appendChild($suites);

        $byClass = [];
        foreach ($report->results as $result) {
            $byClass[$result->class][] = $result;
        }

        foreach ($byClass as $class => $results) {
            $suite = $document->createElement('testsuite');
            $suite->setAttribute('name', $class);
            $suite->setAttribute('file', $results[0]->file);
            $suite->setAttribute('tests', (string) count($results));
            $suite->setAttribute('assertions', (string) array_sum(array_map(static fn (TestResult $r) => $r->assertions, $results)));
            $suite->setAttribute('failures', (string) count(array_filter($results, static fn (TestResult $r) => $r->status === TestResult::FAILED)));
            $suite->setAttribute('errors', (string) count(array_filter($results, static fn (TestResult $r) => $r->status === TestResult::ERRORED)));
            $suite->setAttribute('skipped', (string) count(array_filter($results, static fn (TestResult $r) => $r->status === TestResult::SKIPPED)));
            $suite->setAttribute('time', sprintf('%.6f', array_sum(array_map(static fn (TestResult $r) => $r->seconds, $results))));
            $suites->appendChild($suite);

            foreach ($results as $result) {
                $suite->appendChild($this->testCase($document, $result));
            }
        }

        if ($report->crashed || $report->abortReason !== null) {
            $suite = $document->createElement('testsuite');
            $suite->setAttribute('name', 'Golem');
            $suite->setAttribute('tests', '1');
            $suite->setAttribute('errors', '1');
            $case = $document->createElement('testcase');
            $case->setAttribute('name', 'server');
            $error = $document->createElement('error');
            $error->setAttribute('message', $report->abortReason ?? 'The server crashed before the tests were over');
            $case->appendChild($error);
            $suite->appendChild($case);
            $suites->appendChild($suite);
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $document->save($path);
    }

    private function testCase(\DOMDocument $document, TestResult $result): \DOMElement
    {
        $case = $document->createElement('testcase');
        $case->setAttribute('name', $result->name());
        $case->setAttribute('class', $result->class);
        $case->setAttribute('classname', $result->class);
        $case->setAttribute('file', $result->file);
        $case->setAttribute('line', (string) $result->line);
        $case->setAttribute('assertions', (string) $result->assertions);
        $case->setAttribute('time', sprintf('%.6f', $result->seconds));

        $tag = match ($result->status) {
            TestResult::FAILED => 'failure',
            TestResult::ERRORED => 'error',
            TestResult::SKIPPED => 'skipped',
            default => null,
        };
        if ($tag !== null) {
            $element = $document->createElement($tag);
            $element->setAttribute('message', (string) $result->message);
            if ($result->exception !== null) {
                $element->setAttribute('type', $result->exception);
            }
            $details = array_filter([
                $result->expected !== null ? 'Expected: ' . $result->expected : null,
                $result->actual !== null ? 'Actual:   ' . $result->actual : null,
                $result->failureFile !== null ? "at {$result->failureFile}:{$result->failureLine}" : null,
                ...$result->trace,
            ]);
            if ($details !== []) {
                $element->appendChild($document->createTextNode(implode("\n", $details)));
            }
            $case->appendChild($element);
        }

        return $case;
    }
}
