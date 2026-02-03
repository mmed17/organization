<?php
declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class UserMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'users', Subscription::class);
    }

    /**
     * Sets or removes the organization ID for a user.
     *
     * @param string $userid The user ID.
     * @param int|null $organizationid The organization ID or null to remove.
     */
    public function addOrganizationToUser(string $userid, int|null $organizationid): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('users')
            ->set('organization_id', $qb->createNamedParameter($organizationid))
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($userid)));
        $qb->executeStatement();
    }
}
