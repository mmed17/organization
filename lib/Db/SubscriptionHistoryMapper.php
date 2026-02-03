<?php
declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use DateTime;

class SubscriptionHistoryMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'subscriptions_history', SubscriptionHistory::class);
    }

    /**
     * Creates a detailed history log for a subscription change.
     *
     * @param Subscription $newSubscription The subscription object AFTER the changes.
     * @param ?Subscription $previousSubscription The subscription object BEFORE the changes.
     * @param string $changedByUserId The UID of the user performing the action.
     * @param ?string $notes Optional notes about the change.
     * @return SubscriptionHistory The newly created history entity.
     */
    public function createLog(
        Subscription $newSubscription,
        ?Subscription $previousSubscription,
        string $changedByUserId,
        ?string $notes = null
    ): SubscriptionHistory {
        $history = new SubscriptionHistory();

        $history->setSubscriptionId($newSubscription->getId());
        $history->setChangedByUserId($changedByUserId);
        $history->setChangeTimestamp((new DateTime())->format('Y-m-d H:i:s'));
        $history->setNotes($notes);

        // Record the state after the change
        $history->setNewPlanId($newSubscription->getPlanId());
        $history->setNewStatus($newSubscription->getStatus());
        $history->setNewStartedAt($newSubscription->getStartedAt());
        $history->setNewEndedAt($newSubscription->getEndedAt());
        $history->setNewPausedAt($newSubscription->getPausedAt());
        $history->setNewCancelledAt($newSubscription->getCancelledAt());

        // Record the state before the change if provided
        if ($previousSubscription !== null) {
            $history->setPreviousPlanId($previousSubscription->getPlanId());
            $history->setPreviousStatus($previousSubscription->getStatus());
            $history->setPreviousStartedAt($previousSubscription->getStartedAt());
            $history->setPreviousEndedAt($previousSubscription->getEndedAt());
            $history->setPreviousPausedAt($previousSubscription->getPausedAt());
            $history->setPreviousCancelledAt($previousSubscription->getCancelledAt());
        }

        return $this->insert($history);
    }
}
