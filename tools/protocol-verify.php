<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifestPath = $root . '/protocol/manifest.json';
$errors = [];

$required = [
    'schema/v1/common.schema.json',
    'schema/v1/activate-request.schema.json',
    'schema/v1/activate-response.schema.json',
    'schema/v1/validate-request.schema.json',
    'schema/v1/validate-response.schema.json',
    'schema/v1/deactivate-request.schema.json',
    'schema/v1/deactivate-response.schema.json',
    'schema/v1/update-check-request.schema.json',
    'schema/v1/update-check-response.schema.json',
    'schema/v1/package-authorisation-request.schema.json',
    'schema/v1/package-authorisation-response.schema.json',
    'schema/v1/error-response.schema.json',
    'fixtures/v1/cases.json',
    'fixtures/v1/requests/activate.json',
    'fixtures/v1/requests/validate.json',
    'fixtures/v1/requests/deactivate.json',
    'fixtures/v1/requests/update-check.json',
    'fixtures/v1/requests/package-authorisation.json',
    'fixtures/v1/responses/active.json',
    'fixtures/v1/responses/validation-due.json',
    'fixtures/v1/responses/grace.json',
    'fixtures/v1/responses/expired.json',
    'fixtures/v1/responses/suspended.json',
    'fixtures/v1/responses/revoked.json',
    'fixtures/v1/responses/deactivated.json',
    'fixtures/v1/responses/update-available.json',
    'fixtures/v1/responses/update-current.json',
    'fixtures/v1/responses/package-authorisation.json',
    'fixtures/v1/errors/incompatible.json',
    'fixtures/v1/failures/malformed-response.json',
    'fixtures/v1/failures/transport-timeout.json',
    'fixtures/v1/authority/test-authority-public.pem',
];

if (!is_file($manifestPath)) {
    $errors[] = 'protocol/manifest.json is missing.';
    $manifest = [];
} else {
    try {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        $errors[] = 'protocol/manifest.json is invalid: ' . $exception->getMessage();
        $manifest = [];
    }
}

if (($manifest['protocol_version'] ?? null) !== '1.0') {
    $errors[] = 'The protocol manifest version is not 1.0.';
}
if (($manifest['client_package'] ?? null) !== 'zithis/licence-client') {
    $errors[] = 'The protocol manifest client package is invalid.';
}

$hashes = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
foreach ($required as $relative) {
    $path = $root . '/protocol/' . $relative;
    if (!is_file($path)) {
        $errors[] = 'Missing protocol file: ' . $relative;
        continue;
    }
    if (str_ends_with($relative, '.json')) {
        try {
            json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $errors[] = 'Invalid JSON in ' . $relative . ': ' . $exception->getMessage();
        }
    }
    $expected = strtolower(trim((string) ($hashes[$relative] ?? '')));
    $actual = hash_file('sha256', $path);
    if ($expected === '' || !hash_equals($expected, $actual)) {
        $errors[] = 'Protocol hash mismatch: ' . $relative;
    }
}

foreach ($hashes as $relative => $expected) {
    if (!in_array($relative, $required, true)) {
        $errors[] = 'Unexpected protocol manifest entry: ' . $relative;
    }
}

$protocolIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/protocol'));
foreach ($protocolIterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    if (preg_match('/private|secret/i', $file->getFilename()) === 1
        && preg_match('/\.(pem|key)$/i', $file->getFilename()) === 1) {
        $errors[] = 'The protocol fixtures must not contain a private signing key file.';
    }
    $contents = @file_get_contents($file->getPathname());
    if (is_string($contents)
        && preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/', $contents) === 1) {
        $errors[] = 'The protocol fixtures must not contain private signing key material.';
    }
}

$publicKey = $root . '/protocol/fixtures/v1/authority/test-authority-public.pem';
if (is_file($publicKey)) {
    $resource = @openssl_pkey_get_public((string) file_get_contents($publicKey));
    $details = $resource !== false ? @openssl_pkey_get_details($resource) : false;
    if (!is_array($details)
        || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
        || (int) ($details['bits'] ?? 0) < 2048) {
        $errors[] = 'The fixture authority public key must be RSA with at least 2048 bits.';
    }
}

$sourceTokens = [
    'Illuminate\\',
    'Laravel\\',
    'Zithis\\Settings\\',
    'Zithis\\Lib\\',
    'Zithis\\Licence\\',
    'wp_remote_',
    'wp_schedule_',
    'update_plugins',
    '$wpdb',
];
$sourceIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
foreach ($sourceIterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    foreach ($sourceTokens as $token) {
        if (str_contains($source, $token)) {
            $errors[] = 'Forbidden client dependency in ' . substr($file->getPathname(), strlen($root) + 1) . ': ' . $token;
        }
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, '[protocol] ' . $error . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, '[protocol] Manifest, schemas, fixtures, authority key and dependency boundaries verified.' . PHP_EOL);
