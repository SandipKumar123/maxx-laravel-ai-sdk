<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failed = false;
foreach (['src', 'config', 'database', 'tests', 'tools', 'examples', 'stubs'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.DIRECTORY_SEPARATOR.$directory));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        passthru(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file->getPathname()), $code);
        $failed = $failed || $code !== 0;
    }
}
exit($failed ? 1 : 0);
