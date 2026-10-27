<?php

declare(strict_types=1);

$source = $argv[1] ?? null;
$check = ($argv[2] ?? null) === '--check';
if ($source === null || !is_dir($source) || count($argv) > 3
    || (isset($argv[2]) && !$check)) {
    fwrite(STDERR, "Usage: php tools\\sync-contracts.php <backend-repository-directory> [--check]\n");
    exit(1);
}
$source = rtrim($source, '\\/');
$destination = dirname(__DIR__).DIRECTORY_SEPARATOR.'contracts';
if (!is_dir($destination) && ($check || !mkdir($destination))) {
    throw new RuntimeException('Cannot create SDK contract directory.');
}
$schemas = [
    'm2-dummy-v1' => ['AuthorizeInput', 'RecoveryInput', 'CompletionInput', 'EntitlementInput', 'BillingAcknowledgment', 'MachineClaims', 'SignedReceiptInput'],
    'm1-dummy-v1' => ['ExecutionRequest'],
];
$manifest = ['provisional_only' => true, 'schemas' => []];
$existing = $check
    ? json_decode(file_get_contents($destination.DIRECTORY_SEPARATOR.'manifest.json'), true, 64, JSON_THROW_ON_ERROR)
    : null;
foreach ($schemas as $version => $names) {
    foreach ($names as $name) {
        $relative = implode(DIRECTORY_SEPARATOR, ['contracts', 'schemas', $version, $name.'.json']);
        $path = $source.DIRECTORY_SEPARATOR.$relative;
        if (!is_file($path)) {
            throw new RuntimeException('Missing expected exported contract schema.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read exported contract schema.');
        }
        $sourceHash = hash('sha256', $contents);
        $contents = str_replace("\r\n", "\n", $contents);
        json_decode($contents, false, 64, JSON_THROW_ON_ERROR);
        $snapshot = $destination.DIRECTORY_SEPARATOR.$name.'.json';
        if ($check) {
            $entry = $existing['schemas'][$name] ?? null;
            $relativeSource = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $sourceHashes = [
                hash('sha256', $contents),
                hash('sha256', str_replace("\n", "\r\n", $contents)),
            ];
            if (!is_file($snapshot) || file_get_contents($snapshot) !== $contents
                || !is_array($entry) || ($entry['source'] ?? null) !== $relativeSource
                || ($entry['sha256'] ?? null) !== hash('sha256', $contents)
                || !in_array($entry['source_sha256'] ?? null, $sourceHashes, true)) {
                throw new RuntimeException("Contract parity/provenance mismatch: {$name}. Review upstream changes before syncing.");
            }
        } elseif (file_put_contents($snapshot, $contents) === false) {
            throw new RuntimeException('Cannot write SDK contract snapshot.');
        }
        $manifest['schemas'][$name] = [
            'source' => str_replace(DIRECTORY_SEPARATOR, '/', $relative),
            'source_sha256' => $sourceHash,
            'sha256' => hash('sha256', $contents),
        ];
    }
}
if ($check) {
    if (($existing['provisional_only'] ?? null) !== true
        || array_keys($existing['schemas'] ?? []) !== array_keys($manifest['schemas'])) {
        throw new RuntimeException('Unexpected contract manifest scope.');
    }
    echo "Verified all 8 provisional schemas and provenance against the backend; no files changed.\n";
    exit(0);
}
if (file_put_contents($destination.DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false) {
    throw new RuntimeException('Cannot write SDK contract manifest.');
}
echo "Copied 8 provisional backend contract schemas with SHA-256 provenance.\n";
