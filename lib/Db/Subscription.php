<?php
declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getOrganizationId()
 * @method void setOrganizationId(int $id)
 * @method int getPlanId()
 * @method void setPlanId(int $id)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getStartedAt()
 * @method void setStartedAt(string $date)
 * @method string|null getEndedAt()
 * @method void setEndedAt(?string $date)
 * @method string|null getPausedAt()
 * @method void setPausedAt(?string $date)
 * @method string|null getCancelledAt()
 * @method void setCancelledAt(?string $date)
 */
class Subscription extends Entity implements \JsonSerializable
{
    /** @var int|null The ID of the organization this subscription belongs to. */
    protected int|null $organizationId = null;

    /** @var int The ID of the plan this subscription is for. */
    protected int|null $planId = null;

    /** @var string The current status of the subscription. */
    protected string|null $status = null;

    /** @var string The start date and time of the subscription. */
    protected string|null $startedAt = null;

    /** @var string|null The end date and time of the subscription. NULL if active. */
    protected string|null $endedAt = null;

    /** @var string|null The date and time the subscription was paused. */
    protected string|null $pausedAt = null;

    /** @var string|null The date and time the subscription was cancelled. */
    protected string|null $cancelledAt = null;

    public function __construct()
    {
        $this->addType('organization_id', Types::INTEGER);
        $this->addType('plan_id', Types::INTEGER);
        $this->addType('status', Types::STRING);
        $this->addType('started_at', Types::STRING);
        $this->addType('ended_at', Types::STRING, true);
        $this->addType('paused_at', Types::STRING, true);
        $this->addType('cancelled_at', Types::STRING, true);
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organizationId' => $this->organizationId,
            'planId' => $this->planId,
            'status' => $this->status,
            'startedAt' => $this->startedAt,
            'endedAt' => $this->endedAt,
            'pausedAt' => $this->pausedAt,
            'cancelledAt' => $this->cancelledAt,
        ];
    }
}
