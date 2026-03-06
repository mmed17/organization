<?php

declare(strict_types=1);

namespace OCA\Organization\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Db\SubscriptionMapper;
use OCA\Organization\Db\SubscriptionHistoryMapper;
use OCA\Organization\Service\NotificationService;

use Psr\Log\LoggerInterface;

/**
 * Background job to check and expire subscriptions that have passed their end date.
 */
class CheckExpiredSubscriptions extends TimedJob
{

    public function __construct(
        protected ITimeFactory $time,
        private SubscriptionMapper $subscriptionMapper,
        private OrganizationMapper $organizationMapper,
        private SubscriptionHistoryMapper $subscriptionHistoryMapper,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
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
        $expiredSubscriptions = $this->subscriptionMapper->invalidateExpiredSubscriptions();
        if ($expiredSubscriptions === []) {
            return;
        }

        foreach ($expiredSubscriptions as $previousSubscription) {
            $organizationId = $previousSubscription->getOrganizationId();

            $organization = $this->organizationMapper->find($organizationId);
            if ($organization === null) {
                $this->logger->error('Failed to notify about expired subscription: organization not found', [
                    'orgId' => $organizationId,
                    'subscriptionId' => $previousSubscription->getId(),
                ]);
                continue;
            }

            $newSubscription = clone $previousSubscription;
            $newSubscription->setStatus('expired');

            try {
                $this->subscriptionHistoryMapper->createLog(
                    $newSubscription,
                    $previousSubscription,
                    'system',
                    'Expired by cron',
                );
            } catch (\Throwable $e) {
                $this->logger->error('Failed to create subscription history log for cron expiration', [
                    'orgId' => $organizationId,
                    'subscriptionId' => $previousSubscription->getId(),
                    'exception' => $e,
                ]);
            }

            $this->notificationService->notifySubscriptionExpired(
                $organizationId,
                (string) ($organization->getName() ?? ''),
                $previousSubscription->getEndedAt(),
            );
        }
    }
}
