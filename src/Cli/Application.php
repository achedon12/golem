<?php

declare(strict_types=1);

namespace Golem\Cli;

use Golem\Cli\Command\InitCommand;
use Golem\Cli\Command\RunCommand;

final class Application
{
    public const VERSION = '0.3.0';

    public function __construct(private readonly string $packageRoot)
    {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $options = Options::parse(array_slice($argv, 1), RunCommand::VALUE_OPTIONS);
        $output = Output::forStdout(match (true) {
            $options->has('no-ansi') => false,
            $options->has('ansi') => true,
            default => null,
        });

        try {
            if ($options->has('version') || $options->has('V')) {
                $output->writeln('Golem ' . self::VERSION);

                return 0;
            }
            if ($options->has('help') || $options->has('h') || $options->command === 'help') {
                $output->writeln($this->help());

                return 0;
            }

            return match ($options->command ?? 'run') {
                'run' => (new RunCommand($output, $this->packageRoot . '/src'))->execute($options),
                'init' => (new InitCommand($output, $this->packageRoot . '/stubs'))->execute($options),
                default => throw new UserError("Unknown command \"{$options->command}\". Try `golem --help`."),
            };
        } catch (UserError $e) {
            $output->writeln();
            $output->writeln('  <fail> ERROR </> ' . Output::escape($e->getMessage()));
            $output->writeln();

            return 2;
        }
    }

    private function help(): string
    {
        $version = self::VERSION;

        return <<<HELP

              <bold>Golem</> <gray>$version</> — integration tests for PocketMine-MP plugins

            <yellow>Usage</>
              golem [run] [options]     Run the tests of the plugin in the current folder
              golem init                Add an example test and a GitHub Actions workflow

            <yellow>Options</>
              --filter=<text>           Only run tests whose Class::method contains <text>
              --path=<dir>              Plugin folder (default: current folder)
              --tests=<dir>             Tests folder, relative to the plugin (default: tests)
              --pocketmine=<version>    PocketMine-MP version, e.g. 5.44.3 (default: latest),
                                        or a fork: owner/repository[@tag]
              --compare=<version>       Also run on another version or fork, and report what changes
              --coverage                Report the plugin's commands and listeners the tests never reached
              --log-junit=<file>        Also write a JUnit XML report
              --timeout=<seconds>       Give up after this long (default: 600)
              --update-snapshots        Rewrite the snapshots of assertMatchesSnapshot()
              --watch                   Re-run the tests whenever src/ or tests/ change
              --verbose                 Show the server console
              --keep                    Keep the server folder after the run
              --php=<binary>            Use your own PocketMine PHP build
              --phar=<file>             Use your own PocketMine-MP.phar
              --no-ansi                 Disable colours

            <yellow>Settings</> (composer.json)
              "extra": { "golem": { "tests": "tests", "pocketmine": "5.44.3", "plugins": ["deps/Lib.phar"] } }

            Docs: https://github.com/achedon12/golem

            HELP;
    }
}
