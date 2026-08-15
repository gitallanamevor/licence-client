<?php

declare(strict_types=1);

namespace {
    $GLOBALS['zithis_test_options'] ??= [];
    $GLOBALS['zithis_test_site_transients'] ??= [];
    $GLOBALS['zithis_test_hooks'] ??= [];
    $GLOBALS['zithis_test_activation_hooks'] ??= [];
    $GLOBALS['zithis_test_deactivation_hooks'] ??= [];
    $GLOBALS['zithis_test_action_counts'] ??= [];
    $GLOBALS['zithis_test_plugin_root'] ??= sys_get_temp_dir();

    if (!defined('WP_CONTENT_DIR')) {
        define('WP_CONTENT_DIR', sys_get_temp_dir() . '/zithis-licence-client-wp-content-' . getmypid());
    }

    foreach ([
        'AUTH_KEY',
        'SECURE_AUTH_KEY',
        'LOGGED_IN_KEY',
        'NONCE_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_SALT',
        'NONCE_SALT',
    ] as $zithisSecretConstant) {
        if (!defined($zithisSecretConstant)) {
            define($zithisSecretConstant, 'zithis-test-' . $zithisSecretConstant . '-' . str_repeat('x', 48));
        }
    }

    if (!function_exists('get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return array_key_exists($name, $GLOBALS['zithis_test_options'])
                ? $GLOBALS['zithis_test_options'][$name]
                : $default;
        }
    }
    if (!function_exists('add_option')) {
        function add_option(string $name, mixed $value, string $deprecated = '', bool $autoload = true): bool
        {
            if (array_key_exists($name, $GLOBALS['zithis_test_options'])) {
                return false;
            }
            $GLOBALS['zithis_test_options'][$name] = $value;

            return true;
        }
    }
    if (!function_exists('update_option')) {
        function update_option(string $name, mixed $value, mixed $autoload = null): bool
        {
            $changed = !array_key_exists($name, $GLOBALS['zithis_test_options'])
                || $GLOBALS['zithis_test_options'][$name] !== $value;
            $GLOBALS['zithis_test_options'][$name] = $value;

            return $changed;
        }
    }
    if (!function_exists('delete_option')) {
        function delete_option(string $name): bool
        {
            $exists = array_key_exists($name, $GLOBALS['zithis_test_options']);
            unset($GLOBALS['zithis_test_options'][$name]);

            return $exists;
        }
    }
    if (!function_exists('wp_mkdir_p')) {
        function wp_mkdir_p(string $target): bool
        {
            return is_dir($target) || mkdir($target, 0700, true);
        }
    }
    if (!function_exists('plugin_basename')) {
        function plugin_basename(string $file): string
        {
            $configuredRoot = (string) $GLOBALS['zithis_test_plugin_root'];
            $resolvedRoot = realpath($configuredRoot);
            $resolvedFile = realpath($file);

            $root = rtrim(str_replace('\\', '/', is_string($resolvedRoot) ? $resolvedRoot : $configuredRoot), '/');
            $file = str_replace('\\', '/', is_string($resolvedFile) ? $resolvedFile : $file);
            $rootForComparison = PHP_OS_FAMILY === 'Windows' ? strtolower($root) : $root;
            $fileForComparison = PHP_OS_FAMILY === 'Windows' ? strtolower($file) : $file;

            $relative = str_starts_with($fileForComparison, $rootForComparison . '/')
                ? substr($file, strlen($root))
                : basename($file);

            return strtolower(ltrim($relative, '/'));
        }
    }
    if (!function_exists('get_file_data')) {
        function get_file_data(string $file, array $headers, string $context = ''): array
        {
            $source = (string) file_get_contents($file);
            $result = [];
            foreach ($headers as $key => $header) {
                $matched = preg_match('/^[ \t\/*#@]*' . preg_quote((string) $header, '/') . ':[ \t]*(.+)$/mi', $source, $matches) === 1;
                $result[$key] = $matched ? trim((string) $matches[1]) : '';
            }

            return $result;
        }
    }
    if (!function_exists('home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://customer.example' . $path;
        }
    }
    if (!function_exists('wp_get_environment_type')) {
        function wp_get_environment_type(): string
        {
            return 'production';
        }
    }
    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
        {
            $GLOBALS['zithis_test_hooks'][$hook][$priority][] = [$callback, $acceptedArgs];

            return true;
        }
    }
    if (!function_exists('add_filter')) {
        function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
        {
            return add_action($hook, $callback, $priority, $acceptedArgs);
        }
    }
    if (!function_exists('remove_filter')) {
        function remove_filter(string $hook, callable $callback, int $priority = 10): bool
        {
            $callbacks = $GLOBALS['zithis_test_hooks'][$hook][$priority] ?? [];
            foreach ($callbacks as $index => [$registered]) {
                if ($registered === $callback) {
                    unset($GLOBALS['zithis_test_hooks'][$hook][$priority][$index]);
                    if ($GLOBALS['zithis_test_hooks'][$hook][$priority] === []) {
                        unset($GLOBALS['zithis_test_hooks'][$hook][$priority]);
                    }
                    if (($GLOBALS['zithis_test_hooks'][$hook] ?? []) === []) {
                        unset($GLOBALS['zithis_test_hooks'][$hook]);
                    }
                    return true;
                }
            }

            return false;
        }
    }
    if (!function_exists('apply_filters')) {
        function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed
        {
            $priorities = $GLOBALS['zithis_test_hooks'][$hook] ?? [];
            ksort($priorities, SORT_NUMERIC);
            foreach ($priorities as $callbacks) {
                foreach ($callbacks as [$callback, $acceptedArgs]) {
                    $parameters = array_slice([$value, ...$arguments], 0, $acceptedArgs);
                    $value = $callback(...$parameters);
                }
            }

            return $value;
        }
    }
    if (!function_exists('do_action')) {
        function do_action(string $hook, mixed ...$arguments): void
        {
            $GLOBALS['zithis_test_action_counts'][$hook] = (int) ($GLOBALS['zithis_test_action_counts'][$hook] ?? 0) + 1;
            $priorities = $GLOBALS['zithis_test_hooks'][$hook] ?? [];
            ksort($priorities, SORT_NUMERIC);
            foreach ($priorities as $callbacks) {
                foreach ($callbacks as [$callback, $acceptedArgs]) {
                    $callback(...array_slice($arguments, 0, $acceptedArgs));
                }
            }
        }
    }
    if (!function_exists('did_action')) {
        function did_action(string $hook): int
        {
            return (int) ($GLOBALS['zithis_test_action_counts'][$hook] ?? 0);
        }
    }
    if (!function_exists('register_activation_hook')) {
        function register_activation_hook(string $file, callable $callback): void
        {
            $GLOBALS['zithis_test_activation_hooks'][$file] = $callback;
        }
    }
    if (!function_exists('register_deactivation_hook')) {
        function register_deactivation_hook(string $file, callable $callback): void
        {
            $GLOBALS['zithis_test_deactivation_hooks'][$file] = $callback;
        }
    }
    if (!function_exists('current_user_can')) {
        function current_user_can(string $capability): bool
        {
            return true;
        }
    }
    if (!function_exists('admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://customer.example/wp-admin/' . ltrim($path, '/');
        }
    }
    if (!function_exists('get_site_transient')) {
        function get_site_transient(string $name): mixed
        {
            return $GLOBALS['zithis_test_site_transients'][$name] ?? false;
        }
    }
    if (!function_exists('set_site_transient')) {
        function set_site_transient(string $name, mixed $value, int $expiration = 0): bool
        {
            $GLOBALS['zithis_test_site_transients'][$name] = $value;

            return true;
        }
    }
    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            public function __construct(public string $code = '', public string $message = '')
            {
            }
        }
    }
}
