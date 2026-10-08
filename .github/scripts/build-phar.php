<?php

/**
 * Builds Golem.phar, the server plugin: plugin.yml, LICENSE and src/ without the CLI,
 * which has no use inside a server.
 *
 * usage: php -d phar.readonly=0 .github/scripts/build-phar.php [output.phar]
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$output = $argv[1] ?? $root . '/build/Golem.phar';

if (ini_get('phar.readonly') === '1') {
    fwrite(STDERR, "Run with: php -d phar.readonly=0 {$argv[0]}\n");
    exit(1);
}

@mkdir(dirname($output), 0777, true);
@unlink($output);

$phar = new Phar($output);
$phar->startBuffering();
$phar->addFile($root . '/plugin.yml', 'plugin.yml');
$phar->addFile($root . '/LICENSE', 'LICENSE');

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
$count = 0;
/** @var SplFileInfo $file */
foreach ($files as $file) {
    $relative = substr($file->getPathname(), strlen($root) + 1);
    if (str_starts_with($relative, 'src/Cli/') || $file->getExtension() !== 'php') {
        continue;
    }
    $phar->addFile($file->getPathname(), $relative);
    $count++;
}

$phar->setStub('<?php __HALT_COMPILER();');
$phar->compressFiles(Phar::GZ);
$phar->stopBuffering();

printf("Built %s (%d classes, %d KB)\n", $output, $count, (int) round(filesize($output) / 1024));
