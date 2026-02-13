<?php
declare(strict_types=1);

namespace OCA\Organization\Service;

use OCA\Organization\Db\Organization;
use OCA\Organization\Db\OrganizationMapper;

class OrganizationService
{
    public function __construct(
        private OrganizationMapper $organizationMapper,
    ) {
    }

    /**
     * Creates a new organization.
     *
     * @param string $name The display name for the organization
     * @param string|null $contactFirstName First name of the contact person
     * @param string|null $contactLastName Last name of the contact person
     * @param string|null $contactEmail Email of the contact person
     * @param string|null $contactPhone Phone of the contact person
     * @param string|null $adminUid User ID of the organization admin
     * @return Organization
     */
    public function createOrganization(
        string $name,
        ?string $contactFirstName = null,
        ?string $contactLastName = null,
        ?string $contactEmail = null,
        ?string $contactPhone = null,
        ?string $adminUid = null,
    ): Organization {
        $organization = new Organization();
        $organization->setName($name);
        $organization->setContactFirstName($contactFirstName);
        $organization->setContactLastName($contactLastName);
        $organization->setContactEmail($contactEmail);
        $organization->setContactPhone($contactPhone);
        $organization->setAdminUid($adminUid);

        return $this->organizationMapper->insert($organization);
    }
}
