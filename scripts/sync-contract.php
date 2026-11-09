<?php

declare(strict_types=1);

// Copies Lumber-Kit's contract (schema.json, vectors.json) into tests/contract at the kit version in
// tests/contract/VERSION, from the npm registry, or from a local Lumber-Kit checkout with --from while the kit's change
// isn't released yet.
//
//   php scripts/sync-contract.php                    # the pinned version
//   php scripts/sync-contract.php 0.8.0              # pin a new version
//   php scripts/sync-contract.php --from ../Lumber-Kit
//
// CI runs it and fails when the copy differs from the pinned version's.

const FILES = ['schema.json', 'vectors.json'];

function fail(string $message): never
{
    fwrite(\STDERR, $message . "\n");
    exit(1);
}

$contract = dirname(__DIR__) . '/tests/contract';
$arguments = [];
foreach (array_slice(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [], 1) as $argument) {
    if (is_string($argument)) {
        $arguments[] = $argument;
    }
}

if (($arguments[0] ?? null) === '--from') {
    $source = $arguments[1] ?? fail('Usage: php scripts/sync-contract.php --from ../Lumber-Kit');
    foreach (FILES as $name) {
        if (!copy("{$source}/contract/{$name}", "{$contract}/{$name}")) {
            fail("Couldn't copy {$source}/contract/{$name}");
        }
    }
    echo "Copied the contract from {$source} (VERSION unchanged)\n";
    exit(0);
}

$version = $arguments[0] ?? trim((string) file_get_contents("{$contract}/VERSION"));
if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
    fail("Not a Lumber-Kit version: {$version}");
}

$url = "https://registry.npmjs.org/@infinitetoken/lumber-kit/-/lumber-kit-{$version}.tgz";
$tarball = file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 30]]));
if ($tarball === false) {
    fail("Couldn't download {$url}");
}

// PharData reads the npm tarball once it's in a file named .tgz
$work = sys_get_temp_dir() . '/lumber-kit-' . bin2hex(random_bytes(6));
mkdir($work);
$archive = null;
try {
    file_put_contents("{$work}/kit.tgz", $tarball);
    $archive = new PharData("{$work}/kit.tgz");
    foreach (FILES as $name) {
        $path = "package/contract/{$name}";
        if (!isset($archive[$path])) {
            fail("Lumber-Kit {$version} has no contract/{$name}");
        }
        file_put_contents("{$contract}/{$name}", $archive[$path]->getContent());
    }
} finally {
    // Closes the archive first: Windows can't delete a file still open
    $archive = null;
    array_map('unlink', glob("{$work}/*") ?: []);
    rmdir($work);
}
file_put_contents("{$contract}/VERSION", "{$version}\n");
echo "Synced the contract from Lumber-Kit {$version}\n";
