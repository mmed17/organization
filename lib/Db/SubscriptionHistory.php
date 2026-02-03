<?php
declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getSubscriptionId()
 * @method void setSubscriptionId(int $id)
 * @method string getChangedByUserId()
 * @method void setChangedByUserId(string $userId)
 * @method string getChangeTimestamp()
 * @method void setChangeTimestamp(string $timestamp)
 * @method int|null getPreviousPlanId()
 * @method void setPreviousPlanId(?int $id)
 * @method string|null getPreviousStatus()
 * @method void setPreviousStatus(?string $status)
 * @method string|null getPreviousStartedAt()
 * @method void setPreviousStartedAt(?string $date)
 * @method string|null getPreviousEndedAt()
 * @method void setPreviousEndedAt(?string $date)
 * @method string|null getPreviousPausedAt()
 * @method void setPreviousPausedAt(?string $date)
 * @method string|null getPreviousCancelledAt()
 * @method void setPreviousCancelledAt(?string $date)
 * @method int getNewPlanId()
 * @method void setNewPlanId(int $id)
 * @method string getNewStatus()
 * @method void setNewStatus(string $status)
 * @method string getNewStartedAt()
 * @method void setNewStartedAt(string $date)
 * @method string|null getNewEndedAt()
 * @method void setNewEndedAt(?string $date)
 * @method string|null getNewPausedAt()
 * @method void setNewPausedAt(?string $date)
 * @method string|null getNewCancelledAt()
 * @method void setNewCancelledAt(?string $date)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 */
class SubscriptionHistory extends Entity implements \JsonSerializable
{
    protected int|null $subscriptionId = null;
    protected string|null $changedByUserId = null;
    protected string|null $changeTimestamp = null;

    // State before the change
    protected int|null $previousPlanId = null;
    protected string|null $previousStatus = null;
    protected string|null $previousStartedAt = null;
    protected string|null $previousEndedAt = null;
    protected string|null $previousPausedAt = null;
    protected string|null $previousCancelledAt = null;

    // State after the change
    protected int|null $newPlanId = null;
    protected string|null $newStatus = null;
    protected string|null $newStartedAt = null;
    protected string|null $newEndedAt = null;
    protected string|null $newPausedAt = null;
    protected string|null $newCancelledAt = null;

    protected string|null $notes = null;

    public function __construct()
    {
        $this->addType('subscription_id', Types::INTEGER);
        $this->addType('changed_by_user_id', Types::STRING);
        $this->addType('change_timestamp', Types::STRING);

        // Previous state types
        $this->addType('previous_plan_id', Types::INTEGER, true);
        $this->addType('previous_status', Types::STRING, true);
        $this->addType('previous_started_at', Types::STRING, true);
        $this->addType('previous_ended_at', Types::STRING, true);
        $this->addType('previous_paused_at', Types::STRING, true);
        $this->addType('previous_cancelled_at', Types::STRING, true);

        // New state types
        $this->addType('new_plan_id', Types::INTEGER);
        $this->addType('new_status', Types::STRING);
        $this->addType('new_started_at', Types::STRING);
        $this->addType('new_ended_at', Types::STRING, true);
        $this->addType('new_paused_at', Types::STRING, true);
        $this->addType('new_cancelled_at', Types::STRING, true);

        $this->addType('notes', Types::STRING, true);
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
