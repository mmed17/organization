<?php
declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getContactFirstName()
 * @method void setContactFirstName(?string $contactFirstName)
 * @method string|null getContactLastName()
 * @method void setContactLastName(?string $contactLastName)
 * @method string|null getContactEmail()
 * @method void setContactEmail(?string $contactEmail)
 * @method string|null getContactPhone()
 * @method void setContactPhone(?string $contactPhone)
 */
class Organization extends Entity implements \JsonSerializable
{

    /** @var string The name of the client organization. */
    public ?string $name = null;

    /** @var string|null First name of the contact person. */
    public ?string $contactFirstName = null;

    /** @var string|null Last name of the contact person. */
    public ?string $contactLastName = null;

    /** @var string|null Email address of the contact person. */
    public ?string $contactEmail = null;

    /** @var string|null Phone number of the contact person. */
    public ?string $contactPhone = null;

    public function __construct()
    {
        $this->addType('name', Types::STRING);
        $this->addType('contactFirstName', Types::STRING);
        $this->addType('contactLastName', Types::STRING);
        $this->addType('contactEmail', Types::STRING);
        $this->addType('contactPhone', Types::STRING);
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contactFirstName' => $this->contactFirstName,
            'contactLastName' => $this->contactLastName,
            'contactEmail' => $this->contactEmail,
            'contactPhone' => $this->contactPhone,
        ];
    }
}
