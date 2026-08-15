<?php

declare(strict_types=1);

namespace Zithis\StandaloneWordPressIntegrator;

use Throwable;
use Zithis\StandaloneWordPressIntegrator\Storage\MetadataStore;
use Zithis\StandaloneWordPressIntegrator\Storage\WordPressCredentialStore;
use Zithis\StandaloneWordPressIntegrator\Update\UpdateManager;

final class Scheduler
{
    private bool $registered = false;

    public function __construct(
        private Configuration $configuration,
        private LicenceManager $licences,
        private WordPressCredentialStore $store,
        private MetadataStore $metadata,
        private UpdateManager $updates
    ) {
    }

    public function register(): void
    {
        if ($this->registered || !function_exists('add_action')) {
            return;
        }
        add_action($this->configuration->cronHook(), [$this, 'run']);
        add_action($this->configuration->stateChangedHook(), [$this, 'stateChanged'], 20, 2);
        add_action('init', [$this, 'ensureScheduled'], 20);
        $this->registered = true;
    }

    public function activate(): void
    {
        $this->ensureScheduled();
    }

    public function deactivate(): void
    {
        $this->unschedule();
    }

    public function run(): void
    {
        if (!$this->acquireLock()) {
            $this->schedule(time() + 300);

            return;
        }
        try {
            if ($this->store->configured()) {
                $this->licences->validate(false);
                $this->updates->discover(false);
            }
        } catch (Throwable) {
        } finally {
            $this->releaseLock();
            $this->ensureScheduled(true);
        }
    }

    public function stateChanged(string $productCode, mixed $result = null): void
    {
        if (hash_equals($this->configuration->productCode(), strtolower(trim($productCode)))) {
            $this->ensureScheduled(true);
        }
    }

    public function ensureScheduled(bool $replace = false): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }
        if (!$this->store->configured()) {
            $this->unschedule();

            return;
        }

        $next = $this->nextTimestamp();
        $existing = wp_next_scheduled($this->configuration->cronHook());
        if (is_int($existing) && !$replace && abs($existing - $next) <= 60) {
            return;
        }
        if (is_int($existing)) {
            wp_unschedule_event($existing, $this->configuration->cronHook());
        }
        $this->schedule($next);
    }

    private function nextTimestamp(): int
    {
        $now = time();
        $validationDue = $now + 300;
        try {
            $state = $this->store->load($this->configuration->productCode())?->licence();
            $validation = $this->store->validation();
            if ($state !== null) {
                if ($validation['failure_code'] !== null) {
                    $failed = $validation['failed_at'] !== null ? strtotime($validation['failed_at']) : false;
                    $validationDue = (is_int($failed) ? $failed : $now) + $this->configuration->validationRetrySeconds();
                } else {
                    $validationDue = $state->validationDueAt()->getTimestamp();
                }
            }
        } catch (Throwable) {
            $validationDue = $now + $this->configuration->validationRetrySeconds();
        }

        $lastUpdate = $this->metadata->lastUpdateCheckTimestamp();
        $updateDue = $this->licences->canReceiveUpdates()
            ? ($lastUpdate ?? $now) + $this->configuration->updateCheckSeconds()
            : PHP_INT_MAX;

        return max($now + 60, min($validationDue, $updateDue));
    }

    private function schedule(int $timestamp): void
    {
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(max(time() + 60, $timestamp), $this->configuration->cronHook());
        }
    }

    private function unschedule(): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_unschedule_event')) {
            return;
        }
        while (is_int($timestamp = wp_next_scheduled($this->configuration->cronHook()))) {
            wp_unschedule_event($timestamp, $this->configuration->cronHook());
        }
    }

    private function acquireLock(): bool
    {
        if (!function_exists('add_option') || !function_exists('get_option') || !function_exists('delete_option')) {
            return false;
        }
        $option = $this->configuration->lockOption();
        $expires = time() + $this->configuration->lockSeconds();
        if (add_option($option, $expires, '', false)) {
            return true;
        }
        $current = (int) get_option($option, 0);
        if ($current >= time()) {
            return false;
        }
        delete_option($option);

        return add_option($option, $expires, '', false);
    }

    private function releaseLock(): void
    {
        if (function_exists('delete_option')) {
            delete_option($this->configuration->lockOption());
        }
    }
}
