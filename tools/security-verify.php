#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$forbiddenPaths = [
    'AGENTS.md',
    'DELETIONS.txt',
    'PROJECT_MEMORY.md',
    'docs/acceptance',
    'docs/project-memory',
    'tools/project-memory.php',
];
foreach ($forbiddenPaths as $relative) {
    if (file_exists($root . '/' . $relative)) {
        $failures[] = 'Internal or unnecessary public artifact remains: ' . $relative;
    }
}

$composerPath = $root . '/composer.json';
try {
    $composer = json_decode((string) file_get_contents($composerPath), true, 32, JSON_THROW_ON_ERROR);
    if (($composer['license'] ?? null) !== 'MIT') {
        $failures[] = 'composer.json must declare the MIT licence.';
    }
    if (array_key_exists('version', $composer)) {
        $failures[] = 'composer.json must not hard-code a package version; VCS tags are the Composer version authority.';
    }
} catch (Throwable $exception) {
    $failures[] = 'composer.json is invalid: ' . $exception->getMessage();
}

$patterns = [
    'private_key' => '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
    'github_pat' => '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/',
    'github_classic_token' => '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/',
    'aws_access_key' => '/\bAKIA[0-9A-Z]{16}\b/',
    'local_wamp_path' => '/(?:[A-Za-z]:[\\\\\/]wamp64\b|\/c\/wamp64\b)/i',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    if (str_starts_with($relative, '.git/') || str_starts_with($relative, 'vendor/')) {
        continue;
    }
    if (preg_match('/\.(?:key|p12|pfx)$/i', $file->getFilename()) === 1) {
        $failures[] = 'Private-key container must not be committed: ' . $relative;
        continue;
    }
    $size = $file->getSize();
    if ($size > 4 * 1024 * 1024) {
        continue;
    }
    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        $failures[] = 'Unable to inspect public file: ' . $relative;
        continue;
    }
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $contents) === 1) {
            $failures[] = 'Potential ' . $label . ' found in ' . $relative;
        }
    }
}

$fixtureKey = $root . '/protocol/fixtures/v1/authority/test-authority-public.pem';
if (is_file($fixtureKey)) {
    $resource = @openssl_pkey_get_public((string) file_get_contents($fixtureKey));
    $details = $resource !== false ? @openssl_pkey_get_details($resource) : false;
    if (!is_array($details)
        || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
        || (int) ($details['bits'] ?? 0) < 2048) {
        $failures[] = 'The fixture authority key must be RSA with at least 2048 bits.';
    }
}

if (is_dir($root . '/.git') && function_exists('proc_open')) {
    $command = ['git', '-C', $root, 'log', '--all', '--name-only', '--pretty=format:'];
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $process = @proc_open($command, $descriptors, $pipes, $root, null, ['bypass_shell' => true]);
    if (is_resource($process)) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit === 0 && is_string($stdout)) {
            $history = "\n" . str_replace('\\', '/', $stdout) . "\n";
            foreach ($forbiddenPaths as $relative) {
                if (str_contains($history, "\n" . $relative . "\n")
                    || str_contains($history, "\n" . rtrim($relative, '/') . '/')) {
                    $failures[] = 'Git history still exposes internal artifact: ' . $relative . '. Rewrite or recreate the repository before making it public.';
                }
            }
        } elseif ($exit !== 0 && trim((string) $stderr) !== '') {
            $failures[] = 'Git history inspection failed.';
        }
    }
}

if ($failures !== []) {
    foreach (array_values(array_unique($failures)) as $failure) {
        fwrite(STDERR, '[security] ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, '[security] Public-release secret, metadata and repository-boundary checks passed.' . PHP_EOL);
