<?php

declare(strict_types=1);

namespace OCA\Organization\BackgroundJob;

use OCA\Organization\Service\AccountHandoverService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class RunAccountHandoverJob extends TimedJob
{
    public function __construct(
        protected ITimeFactory $time,
        private AccountHandoverService $handoverService,
    ) {
        parent::__construct($time);
        $this->setInterval(60);
    }

    protected function run($argument): void
    {
        for ($i = 0; $i < 3; $i++) {
            $jobId = $this->handoverService->getOldestQueuedJobId();
            if ($jobId === null) {
                return;
            }

            try {
                $this->handoverService->runJob($jobId);
            } catch (\Throwable) {
                return;
            }
        }
    }
}
