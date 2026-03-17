<?php

declare(strict_types=1);

namespace OCA\Organization\BackgroundJob;

use OCA\Organization\Service\OrganizationBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class RunOrganizationBackupJob extends TimedJob
{
    public function __construct(
        protected ITimeFactory $time,
        private OrganizationBackupService $backupService,
    ) {
        parent::__construct($time);
        $this->setInterval(60);
    }

    protected function run($argument): void
    {
        try {
            $this->backupService->cleanupExpired();
        } catch (\Throwable) {
        }
        try {
            $this->backupService->enqueueScheduledJobs();
        } catch (\Throwable) {
        }

        for ($i = 0; $i < 2; $i++) {
            $jobId = $this->backupService->getOldestQueuedJobId();
            if ($jobId === null) {
                return;
            }

            try {
                $this->backupService->markJobPickedByWorker($jobId);
                $this->backupService->runJob($jobId);
            } catch (\Throwable $e) {
                $this->backupService->markJobFailedFromWorker($jobId, $e);
                continue;
            }
        }
    }
}
