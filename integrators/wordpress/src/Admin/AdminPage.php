<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator\Admin;

use DateTimeInterface;
use Zithis\LicenceClient\Value\LicenceState;
use Zithis\LicenceClient\Runtime\Status;
use Zithis\StandaloneWordPressIntegrator\Configuration;
use Zithis\StandaloneWordPressIntegrator\LicenceManager;
use Zithis\StandaloneWordPressIntegrator\ProductDescriptor;
use Zithis\StandaloneWordPressIntegrator\Storage\MetadataStore;

final class AdminPage
{
    private bool $registered = false;

    public function __construct(
        private Configuration $configuration,
        private ProductDescriptor $product,
        private LicenceManager $licences,
        private MetadataStore $metadata
    ) {
    }

    public function register(): void
    {
        if ($this->registered || !function_exists('add_action') || !function_exists('add_filter')) {
            return;
        }
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_notices', [$this, 'notice']);
        add_filter('plugin_action_links_' . $this->configuration->packageIdentifier(), [$this, 'actionLinks']);
        $this->registered = true;
    }

    public function menu(): void
    {
        if (!function_exists('add_options_page')) {
            return;
        }
        add_options_page(
            $this->configuration->productName() . ' Licence',
            $this->configuration->productName() . ' Licence',
            $this->configuration->adminCapability(),
            $this->configuration->adminSlug(),
            [$this, 'render']
        );
    }

    /** @param array<int|string,string> $links
     *  @return array<int|string,string>
     */
    public function actionLinks(array $links): array
    {
        $url = $this->adminUrl();
        $label = function_exists('esc_html__') ? esc_html__('Licence', 'default') : 'Licence';
        $link = '<a href="' . $this->escapeUrl($url) . '">' . $label . '</a>';
        array_unshift($links, $link);

        return $links;
    }

    public function notice(): void
    {
        if (!function_exists('current_user_can')
            || !current_user_can($this->configuration->adminCapability())
            || $this->licences->canUseBusinessRuntime()) {
            return;
        }
        $status = $this->licences->status();
        $message = $status->code() === Status::UNCONFIGURED
            ? $this->configuration->productName() . ' requires licence activation before its features can be used.'
            : $this->configuration->productName() . ' is unavailable until its licence status is resolved.';
        echo '<div class="notice notice-warning"><p>'
            . $this->escape($message) . ' <a href="' . $this->escapeUrl($this->adminUrl()) . '">Manage licence</a>'
            . '</p></div>';
    }

    public function render(): void
    {
        if (!function_exists('current_user_can')
            || !current_user_can($this->configuration->adminCapability())) {
            return;
        }

        $status = $this->licences->status();
        $state = $this->licences->current();
        $offer = $this->metadata->offer();
        echo '<div class="wrap">';
        echo '<h1>' . $this->escape($this->configuration->productName()) . ' Licence</h1>';
        $this->renderResult();
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        $this->row('Product', $this->configuration->productName());
        $this->row('Installed version', $this->product->installedVersion());
        $this->row('Licence status', $this->statusLabel($status));
        if ($state instanceof LicenceState) {
            $this->row('Licence ID', $state->id());
            $this->row('Entitlements', implode(', ', $state->entitlements()));
            $this->row('Expires', $this->date($state->expiresAt()));
            $this->row('Validation due', $this->date($state->validationDueAt()));
        }
        if ($offer !== null) {
            $this->row('Available update', $offer->version());
        }
        echo '</tbody></table>';

        echo '<div style="margin-top:20px">';
        if (!$this->licences->configured()) {
            $this->form('activate', 'Activate licence', true);
        } else {
            $this->form('validate', 'Validate now');
            $this->form('check_update', 'Check for updates');
            $this->form('deactivate', 'Deactivate licence');
        }
        echo '</div></div>';
    }

    private function renderResult(): void
    {
        $result = isset($_GET['zithis_licence_result']) ? (string) $_GET['zithis_licence_result'] : '';
        if (function_exists('wp_unslash')) {
            $result = wp_unslash($result);
        }
        $result = strtolower(trim($result));
        if ($result === '' || preg_match('/^[a-z0-9._-]{2,80}$/', $result) !== 1) {
            return;
        }
        $success = in_array($result, ['activated', 'validated', 'deactivated', 'update_checked'], true);
        $messages = [
            'activated' => 'The licence was activated.',
            'validated' => 'The licence was validated.',
            'deactivated' => 'The licence was deactivated.',
            'update_checked' => 'The private update check completed.',
            'activation_key_required' => 'Enter a licence key.',
            'transport_http_request_failed' => 'The licence authority could not be reached. Check this site\'s outbound HTTP connectivity and try again.',
            'transport_http_request_not_executed' => 'WordPress blocked the configured licence authority request. Contact the software provider with this site\'s environment details.',
            'authority_endpoint_not_allowed' => 'The installed licence authority configuration is invalid. Reinstall or update this plugin package.',
            'authority_url_invalid' => 'The installed licence authority URL is invalid. Reinstall or update this plugin package.',
            'wordpress_filter_api_unavailable' => 'This WordPress installation cannot apply the configured private-network licence policy.',
        ];
        $message = $messages[$result] ?? ($success ? 'The licence operation completed.' : 'The licence operation could not be completed.');
        echo '<div class="notice ' . ($success ? 'notice-success' : 'notice-error') . '"><p>' . $this->escape($message) . '</p></div>';
    }

    private function form(string $action, string $label, bool $licenceKey = false): void
    {
        $url = function_exists('admin_url') ? admin_url('admin-post.php') : '/wp-admin/admin-post.php';
        echo '<form method="post" action="' . $this->escapeUrl($url) . '" style="display:inline-block;margin:0 10px 10px 0">';
        echo '<input type="hidden" name="action" value="' . $this->escape($this->configuration->action($action)) . '">';
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field($this->configuration->action($action));
        }
        if ($licenceKey) {
            echo '<label><span class="screen-reader-text">Licence key</span><input type="password" name="licence_key" autocomplete="off" required style="min-width:320px" placeholder="Licence key"></label> ';
        }
        if (function_exists('submit_button')) {
            submit_button($label, $action === 'deactivate' ? 'secondary' : 'primary', 'submit', false);
        } else {
            echo '<button type="submit">' . $this->escape($label) . '</button>';
        }
        echo '</form>';
    }

    private function statusLabel(Status $status): string
    {
        return match ($status->code()) {
            Status::UNCONFIGURED => 'Not activated',
            Status::ACTIVE => 'Active',
            Status::VALIDATION_DUE => 'Validation due',
            Status::VALIDATION_GRACE => 'Temporary validation grace',
            Status::VALIDATION_FAILED => 'Validation failed',
            Status::EXPIRED => 'Expired',
            Status::SUSPENDED => 'Suspended',
            Status::REVOKED => 'Revoked',
            Status::INSTALLATION_INACTIVE => 'Site deactivated',
            Status::LOCAL_STORAGE_UNAVAILABLE => 'Local storage unavailable',
            Status::INCOMPATIBLE => 'Client or server incompatible',
            default => 'Unknown',
        };
    }

    private function row(string $label, string $value): void
    {
        echo '<tr><th style="width:220px">' . $this->escape($label) . '</th><td>' . $this->escape($value) . '</td></tr>';
    }

    private function date(DateTimeInterface $value): string
    {
        return $value->format('Y-m-d H:i:s \U\T\C');
    }

    private function adminUrl(): string
    {
        return function_exists('admin_url')
            ? admin_url('options-general.php?page=' . rawurlencode($this->configuration->adminSlug()))
            : '/wp-admin/options-general.php?page=' . rawurlencode($this->configuration->adminSlug());
    }

    private function escape(string $value): string
    {
        return function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function escapeUrl(string $value): string
    {
        return function_exists('esc_url') ? esc_url($value) : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
