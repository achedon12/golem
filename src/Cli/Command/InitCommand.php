<?php

declare(strict_types=1);

namespace Golem\Cli\Command;

use Golem\Cli\Options;
use Golem\Cli\Output;
use Golem\Cli\Project;

/**
 * golem init: adds a first test and a GitHub Actions workflow to a plugin.
 */
final class InitCommand
{
    public function __construct(
        private readonly Output $output,
        private readonly string $stubs,
    ) {
    }

    public function execute(Options $options): int
    {
        $project = Project::load($options->get('path', getcwd() ?: '.') ?? '.', $options->get('tests'), null);
        $namespace = $project->namespace . '\\Tests';

        $this->output->writeln();
        $this->create(
            $project->testsDirectory . '/ExampleTest.php',
            strtr((string) file_get_contents($this->stubs . '/ExampleTest.php.stub'), [
                '{{namespace}}' => $namespace,
                '{{plugin}}' => $project->name,
            ]),
            $project->root,
        );
        if (!$options->has('no-workflow')) {
            $this->create(
                $project->root . '/.github/workflows/golem.yml',
                (string) file_get_contents($this->stubs . '/golem.yml.stub'),
                $project->root,
            );
        }

        $this->output->writeln();
        $this->output->writeln('  Next: run <bold>vendor/bin/golem</> and watch your first golem join,');
        $this->output->writeln('  or <bold>vendor/bin/golem ui</> to do it from a dashboard in your browser.');
        $this->output->writeln();

        return 0;
    }

    private function create(string $path, string $content, string $root): void
    {
        $relative = substr($path, strlen($root) + 1);
        if (file_exists($path)) {
            $this->output->writeln("  <yellow>skip</>    $relative <gray>(already exists)</>");

            return;
        }
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
        $this->output->writeln("  <green>created</> $relative");
    }
}
