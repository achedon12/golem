<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

/**
 * Writes line coverage as a Clover XML file, which Codecov, Coveralls and most CI tools read.
 */
final class CloverReporter
{
    /**
     * @param array<string, array<int, int>> $lines per file relative to $sourceDirectory: line => 1 if it ran, else 0
     */
    public function write(array $lines, string $sourceDirectory, string $path): void
    {
        $time = (string) time();
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('coverage');
        $xml->writeAttribute('generated', $time);
        $xml->startElement('project');
        $xml->writeAttribute('timestamp', $time);

        $total = 0;
        $covered = 0;
        foreach ($lines as $file => $fileLines) {
            $xml->startElement('file');
            $xml->writeAttribute('name', $sourceDirectory . '/' . $file);
            foreach ($fileLines as $line => $ran) {
                $xml->startElement('line');
                $xml->writeAttribute('num', (string) $line);
                $xml->writeAttribute('type', 'stmt');
                $xml->writeAttribute('count', (string) $ran);
                $xml->endElement();
            }
            $fileCovered = count(array_filter($fileLines));
            $this->metrics($xml, count($fileLines), $fileCovered);
            $xml->endElement();
            $total += count($fileLines);
            $covered += $fileCovered;
        }

        $this->metrics($xml, $total, $covered, count($lines));
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($path, $xml->outputMemory());
    }

    private function metrics(\XMLWriter $xml, int $statements, int $covered, ?int $files = null): void
    {
        $xml->startElement('metrics');
        if ($files !== null) {
            $xml->writeAttribute('files', (string) $files);
        }
        $xml->writeAttribute('statements', (string) $statements);
        $xml->writeAttribute('coveredstatements', (string) $covered);
        $xml->writeAttribute('elements', (string) $statements);
        $xml->writeAttribute('coveredelements', (string) $covered);
        $xml->endElement();
    }
}
