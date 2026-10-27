<?php

declare(strict_types=1);

namespace Maxx\Ai\Laravel;

final class ModuleInstallation
{
    public const MANIFEST = 'maxx-ai-installation.json';

    public static function snapshot(string $directory): array
    {
        if (is_link($directory) || ! is_dir($directory)) {
            throw new \RuntimeException('AiGovernance must be a real directory, not a symbolic link.');
        }
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new \RuntimeException('AiGovernance contains a symbolic link; automatic removal is unsafe.');
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1));
            if ($entry->isDir()) {
                $entries[$relative] = 'directory';
            } elseif ($entry->isFile()) {
                $hash = hash_file('sha256', $entry->getPathname());
                if ($hash === false) {
                    throw new \RuntimeException('Could not read AiGovernance file: '.$relative);
                }
                $entries[$relative] = $hash;
            } else {
                throw new \RuntimeException('AiGovernance contains an unsupported filesystem entry: '.$relative);
            }
        }
        ksort($entries);

        return $entries;
    }
}
