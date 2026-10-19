<?php

declare(strict_types=1);

$source = $argv[1] ?? null;
if ($source === null || !is_dir($source)) {
    fwrite(STDERR, "Usage: php tools\\sync-contracts.php <backend-repository-directory>\n");
    exit(1);
}
$source = rtrim($source, '\\/');
$destination = dirname(__DIR__).DIRECTORY_SEPARATOR.'contracts';
if (!is_dir($destination) && !mkdir($destination)) {
    throw new RuntimeException('Cannot create SDK contract directory.');
}
$schemas = [
    'm2-dummy-v1' => ['AuthorizeInput', 'RecoveryInput', 'CompletionInput', 'EntitlementInput', 'BillingAcknowledgment', 'MachineClaims', 'SignedReceiptInput'],
    'm1-dummy-v1' => ['ExecutionRequest'],
];
$manifest = ['provisional_only' => true, 'schemas' => []];
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
        if (file_put_contents($destination.DIRECTORY_SEPARATOR.$name.'.json', $contents) === false) {
            throw new RuntimeException('Cannot write SDK contract snapshot.');
        }
        $manifest['schemas'][$name] = [
            'source' => str_replace(DIRECTORY_SEPARATOR, '/', $relative),
            'source_sha256' => $sourceHash,
            'sha256' => hash('sha256', $contents),
        ];
    }
}
if (file_put_contents($destination.DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false) {
    throw new RuntimeException('Cannot write SDK contract manifest.');
}
echo "Copied 8 provisional backend contract schemas with SHA-256 provenance.\n";
