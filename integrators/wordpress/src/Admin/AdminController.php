<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator\Admin;

use Throwable;
use Zithis\LicenceClient\Value\OperationResult;
use Zithis\StandaloneWordPressIntegrator\Configuration;
use Zithis\StandaloneWordPressIntegrator\LicenceManager;
use Zithis\StandaloneWordPressIntegrator\Scheduler;
use Zithis\StandaloneWordPressIntegrator\Update\UpdateManager;

final class AdminController
{
    private bool $registered = false;

    public function __construct(
        private Configuration $configuration,
        private LicenceManager $licences,
        private UpdateManager $updates,
        private Scheduler $scheduler
    ) {
    }

    public function register(): void
    {
        if ($this->registered || !function_exists('add_action')) {
            return;
        }

        foreach (['activate', 'validate', 'deactivate', 'check_update'] as $action) {
            add_action('admin_post_' . $this->configuration->action($action), [$this, $action]);
        }
        $this->registered = true;
    }

    public function activate(): void
    {
        $this->authorise('activate');
        $key = isset($_POST['licence_key']) ? (string) $_POST['licence_key'] : '';
        if (function_exists('wp_unslash')) {
            $key = wp_unslash($key);
        }
        if (function_exists('sanitize_text_field')) {
            $key = sanitize_text_field($key);
        }
        $key = trim($key);
        if ($key === '') {
            $this->redirect('activation_key_required');
        }

        try {
            $this->finish($this->licences->activate($key), 'activated');
        } catch (Throwable) {
            $this->redirect('activation_failed');
        }
    }

    public function validate(): void
    {
        $this->authorise('validate');
        try {
            $this->finish($this->licences->validate(true), 'validated');
        } catch (Throwable) {
            $this->redirect('validation_failed');
        }
    }

    public function deactivate(): void
    {
        $this->authorise('deactivate');
        try {
            $this->finish($this->licences->deactivate(), 'deactivated');
        } catch (Throwable) {
            $this->redirect('deactivation_failed');
        }
    }

    public function check_update(): void
    {
        $this->authorise('check_update');
        try {
            $this->redirect($this->updates->discover(true) ? 'update_checked' : 'update_check_failed');
        } catch (Throwable) {
            $this->redirect('update_check_failed');
        }
    }

    private function authorise(string $action): void
    {
        if (!function_exists('current_user_can')
            || !current_user_can($this->configuration->adminCapability())) {
            if (function_exists('wp_die')) {
                wp_die('You are not authorised to manage this software licence.', '', ['response' => 403]);
            }
            throw new \RuntimeException('The licence action is not authorised.');
        }
        if (!function_exists('check_admin_referer')) {
            throw new \RuntimeException('The WordPress request verification API is unavailable.');
        }
        check_admin_referer($this->configuration->action($action));
    }

    private function finish(OperationResult $result, string $success): never
    {
        $this->scheduler->ensureScheduled(true);
        if ($result->successful()) {
            $this->redirect($success);
        }

        $code = strtolower(trim((string) ($result->error()?->code() ?? 'operation_failed')));
        $code = preg_match('/^[a-z0-9._-]{2,80}$/', $code) === 1 ? $code : 'operation_failed';
        $this->redirect($code);
    }

    private function redirect(string $result): never
    {
        $result = preg_match('/^[a-z0-9._-]{2,80}$/', $result) === 1 ? $result : 'operation_failed';
        $url = function_exists('admin_url')
            ? admin_url('options-general.php?page=' . rawurlencode($this->configuration->adminSlug()))
            : '/wp-admin/options-general.php?page=' . rawurlencode($this->configuration->adminSlug());
        if (function_exists('add_query_arg')) {
            $url = add_query_arg('zithis_licence_result', $result, $url);
        } else {
            $url .= '&zithis_licence_result=' . rawurlencode($result);
        }
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($url);
            exit;
        }

        throw new \RuntimeException('The licence action completed but WordPress could not redirect the request.');
    }
}
