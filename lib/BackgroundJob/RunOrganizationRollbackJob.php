<?php

declare(strict_types=1);

namespace OCA\Organization\BackgroundJob;

use OCA\Organization\Service\OrganizationRollbackService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class RunOrganizationRollbackJob extends TimedJob
{
    public function __construct(
        protected ITimeFactory $time,
        private OrganizationRollbackService $rollbackService,
    ) {
        parent::__construct($time);
        $this->setInterval(60);
    }

    protected function run($argument): void
    {
        for ($i = 0; $i < 2; $i++) {
            $jobId = $this->rollbackService->getOldestQueuedJobId();
            if ($jobId === null) {
                return;
            }

            try {
                $this->rollbackService->runJob($jobId);
            } catch (\Throwable $e) {
                $this->rollbackService->markJobFailedFromWorker($jobId, $e);
            }
        }
    }
}
