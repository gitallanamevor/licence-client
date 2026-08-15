<?php

declare(strict_types=1);

namespace Zithis\LicenceClient\Integrator\Php;

use Zithis\LicenceClient\Integrator\Php\State\ProductLock;
use Zithis\LicenceClient\Integrator\Php\Update\UpdateManager;

final class MaintenanceRunner
{
    public function __construct(
        private Configuration $configuration,
        private LicenceManager $licences,
        private UpdateManager $updates,
        private ProductLock $lock
    ) {
    }

    public function run(): MaintenanceReport
    {
        $report = $this->lock->run(
            'maintenance',
            $this->configuration->lockWaitSeconds(),
            function (): MaintenanceReport {
                if (!$this->licences->configured()) {
                    return new MaintenanceReport(true, false, null, false, null);
                }
                $validation = $this->licences->validate(false);
                $validationAttempted = !str_starts_with($validation->requestId(), 'local-');
                $updateAttempted = $this->licences->canReceiveUpdates();
                $updateSuccessful = $updateAttempted ? $this->updates->discover(false) : null;

                return new MaintenanceReport(
                    true,
                    $validationAttempted,
                    $validationAttempted ? $validation->successful() : null,
                    $updateAttempted,
                    $updateSuccessful
                );
            }
        );

        return $report instanceof MaintenanceReport
            ? $report
            : new MaintenanceReport(false, false, null, false, null);
    }
}
