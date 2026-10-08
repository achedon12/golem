<?php

/**
 * The router of PHP's built-in web server, for golem ui: static files from resources/ui,
 * the API under /api/.
 */

declare(strict_types=1);

$package = (string) getenv('GOLEM_UI_PACKAGE');
spl_autoload_register(static function (string $class) use ($package): void {
    // the CLI, plus the one runtime class the scenario writer shares with the fuzzer
    if (str_starts_with($class, 'Golem\\Cli\\') || $class === 'Golem\\Runtime\\Fuzz\\Literal') {
        $file = $package . '/src/' . str_replace('\\', '/', substr($class, strlen('Golem\\'))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

return Golem\Cli\Ui\Api::fromEnvironment()->handle();
