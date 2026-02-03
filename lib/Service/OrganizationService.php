<?php
declare(strict_types=1);

namespace OCA\Organization\Service;

use OCA\Organization\Db\Organization;
use OCA\Organization\Db\OrganizationMapper;
use OCP\IGroupManager;

class OrganizationService
{
    public function __construct(
        private OrganizationMapper $organizationMapper,
        private IGroupManager $groupManager,
    ) {
    }

    /**
     * Creates a new organization with the given Nextcloud group ID.
     * First creates the Nextcloud group, then creates the organization record.
     * 
     * @param string $groupId The group ID (also used as Nextcloud group ID)
     * @param string $displayName The display name for the group
     * @return Organization
     * @throws \Exception If group creation fails
     */
    public function createOrganization(string $groupId, string $displayName): Organization
    {
        // Check if group already exists
        $existingGroup = $this->groupManager->get($groupId);
        if ($existingGroup !== null) {
            throw new \Exception('Group already exists: ' . $groupId);
        }

        // Create the Nextcloud group first
        $group = $this->groupManager->createGroup($groupId);
        if ($group === null) {
            throw new \Exception('Failed to create Nextcloud group: ' . $groupId);
        }

        // Set display name if provided
        if (!empty($displayName)) {
            $group->setDisplayName($displayName);
        }

        // Now create the organization record
        $organization = new Organization();
        $organization->setNextcloudGroupId($groupId);
        $organization->setName($displayName ?: $groupId);

        $this->organizationMapper->insert($organization);
        return $organization;
    }
}
