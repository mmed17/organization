<?php

declare(strict_types=1);

namespace OCA\Organization\BackgroundJob;

use OCA\Organization\Db\SubscriptionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Background job to check and expire subscriptions that have passed their end date.
 */
class CheckExpiredSubscriptions extends TimedJob
{

    public function __construct(
        protected ITimeFactory $time,
        private SubscriptionMapper $subscriptionMapper
    ) {
        parent::__construct($time);
        // Run every hour (3600 seconds)
        $this->setInterval(3600);
    }

    /**
     * Executed by Nextcloud cron system.
     */
    protected function run($argument): void
    {
        $this->subscriptionMapper->invalidateExpiredSubscriptions();
    }
}
