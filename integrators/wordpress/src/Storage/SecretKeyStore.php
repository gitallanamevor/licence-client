<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator\Storage;

use RuntimeException;
use Zithis\StandaloneWordPressIntegrator\Configuration;

final class SecretKeyStore
{
    private const SEED_BYTES = 32;

    private ?string $key = null;

    public function __construct(private Configuration $configuration)
    {
    }

    public function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $configured = defined('ZITHIS_LICENCE_KEY_DIRECTORY')
            ? trim((string) constant('ZITHIS_LICENCE_KEY_DIRECTORY'))
            : '';
        if ($configured !== '') {
            return $this->key = $this->fileKey($configured);
        }

        $legacy = $this->legacyKeyPath();
        if ($legacy !== null && is_file($legacy) && !is_link($legacy)) {
            return $this->key = $this->readFileKey($legacy);
        }

        return $this->key = $this->derivedKey();
    }

    private function derivedKey(): string
    {
        if (!function_exists('get_option') || !function_exists('add_option')) {
            throw new RuntimeException('The WordPress licence key seed store is unavailable.');
        }

        $option = $this->configuration->optionPrefix() . '_key_seed';
        $encoded = trim((string) get_option($option, ''));
        if ($encoded === '') {
            $candidate = base64_encode(random_bytes(self::SEED_BYTES));
            if (add_option($option, $candidate, '', false)) {
                $encoded = $candidate;
            } else {
                $encoded = trim((string) get_option($option, ''));
            }
        }

        $seed = base64_decode($encoded, true);
        if (!is_string($seed) || strlen($seed) !== self::SEED_BYTES) {
            throw new RuntimeException('The WordPress licence key seed is invalid.');
        }

        $key = hash_hkdf(
            'sha256',
            $this->wordpressSecretMaterial(),
            32,
            'zithis-wordpress-licence-key-v2|' . $this->configuration->productCode() . '|' . $this->configuration->packageIdentifier(),
            $seed
        );
        if (strlen($key) !== 32) {
            throw new RuntimeException('The WordPress licence encryption key could not be derived.');
        }

        return $key;
    }

    private function wordpressSecretMaterial(): string
    {
        $values = [];
        foreach ([
            'AUTH_KEY',
            'SECURE_AUTH_KEY',
            'LOGGED_IN_KEY',
            'NONCE_KEY',
            'AUTH_SALT',
            'SECURE_AUTH_SALT',
            'LOGGED_IN_SALT',
            'NONCE_SALT',
        ] as $name) {
            if (!defined($name)) {
                continue;
            }
            $value = (string) constant($name);
            if ($this->usableSecret($value)) {
                $values[] = $name . '=' . $value;
            }
        }

        if (count($values) < 2 && function_exists('wp_salt')) {
            foreach (['auth', 'secure_auth', 'logged_in', 'nonce'] as $scheme) {
                $value = (string) wp_salt($scheme);
                if ($this->usableSecret($value)) {
                    $values[] = 'wp_salt:' . $scheme . '=' . $value;
                }
            }
        }

        $values = array_values(array_unique($values));
        $material = implode("\n", $values);
        if (count($values) < 2 || strlen($material) < 64) {
            throw new RuntimeException(
                'WordPress secret material is insufficient for licence-state encryption. Define strong WordPress salts or ZITHIS_LICENCE_KEY_DIRECTORY.'
            );
        }

        return $material;
    }

    private function usableSecret(string $value): bool
    {
        $value = trim($value);

        return strlen($value) >= 16
            && stripos($value, 'put your unique phrase here') === false;
    }

    private function fileKey(string $directory): string
    {
        if (str_contains($directory, "\0")) {
            throw new RuntimeException('The configured licence encryption directory is invalid.');
        }
        $directory = rtrim($directory, '/\\');
        $this->ensureDirectory($directory);
        $path = $directory . '/' . $this->keyFilename();
        if (is_link($path)) {
            throw new RuntimeException('The licence encryption key path is unsafe.');
        }
        if (!is_file($path)) {
            $this->create($path);
        }

        return $this->readFileKey($path);
    }

    private function legacyKeyPath(): ?string
    {
        if (!defined('WP_CONTENT_DIR')) {
            return null;
        }

        $directory = rtrim((string) WP_CONTENT_DIR, '/\\') . '/.zithis-licence-keys';

        return $directory . '/' . $this->keyFilename();
    }

    private function keyFilename(): string
    {
        return hash('sha256', $this->configuration->productCode() . '|' . $this->configuration->packageIdentifier()) . '.key';
    }

    private function readFileKey(string $path): string
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $encoded = trim((string) @file_get_contents($path));
            $key = base64_decode($encoded, true);
            if (is_string($key) && strlen($key) === 32) {
                return $key;
            }
            usleep(20000);
        }

        throw new RuntimeException('The licence encryption key is invalid.');
    }

    private function ensureDirectory(string $directory): void
    {
        if ($directory === '' || is_link($directory)) {
            throw new RuntimeException('The licence encryption directory is unsafe.');
        }
        if (!is_dir($directory)) {
            $created = function_exists('wp_mkdir_p')
                ? wp_mkdir_p($directory)
                : mkdir($directory, 0700, true);
            if (!$created && !is_dir($directory)) {
                throw new RuntimeException('The licence encryption directory could not be created.');
            }
        }
        @chmod($directory, 0700);
        $this->protect($directory . '/index.php', "<?php\nhttp_response_code(404);\nexit;\n");
        $this->protect($directory . '/.htaccess', "Require all denied\nDeny from all\n");
        $this->protect($directory . '/web.config', "<?xml version=\"1.0\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n");
    }

    private function create(string $path): void
    {
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            if (is_file($path) && !is_link($path)) {
                return;
            }
            throw new RuntimeException('The licence encryption key could not be created.');
        }
        try {
            $value = base64_encode(random_bytes(32)) . "\n";
            if (fwrite($handle, $value) !== strlen($value) || !fflush($handle)) {
                throw new RuntimeException('The licence encryption key could not be written.');
            }
        } finally {
            fclose($handle);
        }
        @chmod($path, 0600);
    }

    private function protect(string $path, string $contents): void
    {
        if (is_file($path) || is_link($path)) {
            return;
        }
        @file_put_contents($path, $contents, LOCK_EX);
    }
}
