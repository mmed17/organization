<?php
declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getName()
 * @method void setName(string $name)
 * @method string getNextcloudGroupId()
 * @method void setNextcloudGroupId(string $groupId)
 */
class Organization extends Entity implements \JsonSerializable
{

    /** @var string The name of the client organization. */
    public ?string $name = null;

    /** @var string The corresponding Nextcloud group ID for this organization. */
    public ?string $nextcloudGroupId = null;

    public function __construct()
    {
        $this->addType('name', Types::STRING);
        $this->addType('nextcloud_group_id', Types::STRING);
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'nextcloudGroupId' => $this->nextcloudGroupId,
        ];
    }
}
