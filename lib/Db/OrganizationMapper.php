<?php
declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

class OrganizationMapper extends QBMapper
{
    public function __construct(
        IDBConnection $db,
        private IGroupManager $groupManager,
        private LoggerInterface $logger
    ) {
        parent::__construct($db, 'organizations', Organization::class);
    }

    /**
     * Finds an organization by its corresponding Nextcloud group ID.
     *
     * @param string $groupId The Nextcloud group ID.
     * @return Organization|null
     */
    public function findByGroupId(string $groupId): ?Organization
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq(
                'nextcloud_group_id',
                $qb->createNamedParameter($groupId)
            ));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Finds an organization by one of its user's ID.
     *
     * @param string $userId The user's ID.
     * @return Organization|null
     */
    public function findByUserId(string $userId): ?Organization
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('o.*')
            ->from($this->getTableName(), 'o')
            ->innerJoin(
                'o',
                'users',
                'u',
                $qb->expr()->eq('o.id', 'u.organization_id')
            )
            ->where(
                $qb->expr()->eq('u.uid', $qb->createNamedParameter($userId))
            );

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Retrieves all groups for a user that are linked to an organization.
     *
     * @param string $userId The user ID.
     * @return \OCP\IGroup[] An array of IGroup objects.
     */
    public function findOrganizationGroupsForUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('g.gid')
            ->from('groups', 'g')
            ->innerJoin(
                'g',
                'group_user',
                'gu',
                $qb->expr()->eq('g.gid', 'gu.gid')
            )
            ->innerJoin(
                'g',
                'organizations',
                'o',
                $qb->expr()->eq('g.gid', 'o.nextcloud_group_id')
            )
            ->where(
                $qb->expr()->eq('gu.uid', $qb->createNamedParameter($userId))
            );

        $groupIds = [];
        try {
            $result = $qb->executeQuery();
            $groupIds = $result->fetchAll(\PDO::FETCH_COLUMN, 0);
            $result->closeCursor();
        } catch (\Exception $e) {
            $this->logger->error(
                'Could not retrieve organization groups for user: ' . $e->getMessage(),
                [
                    'app' => 'organization',
                    'exception' => $e
                ]
            );
            return [];
        }

        $groupObjects = [];
        foreach ($groupIds as $gid) {
            $group = $this->groupManager->get((string) $gid);
            if ($group !== null) {
                $groupObjects[] = $group;
            }
        }

        return $groupObjects;
    }

    /**
     * Counts total users for a specific organization.
     *
     * @param int $organizationId The organization ID.
     * @return int The user count.
     */
    public function getUserCount(int $organizationId): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias(
            $qb->createFunction('COUNT(u.uid)'),
            'user_count'
        )
            ->from('users', 'u')
            ->where(
                $qb->expr()->eq('u.organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT))
            );

        $result = $qb->executeQuery();
        $count = $result->fetchOne();
        $result->closeCursor();

        return (int) $count;
    }

    /**
     * Counts total projects for a specific organization.
     *
     * @param int $organizationId The organization ID.
     * @return int The project count.
     */
    public function getProjectsCount(int $organizationId): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias(
            $qb->createFunction('COUNT(p.id)'),
            'project_count'
        )
            ->from('custom_projects', 'p')
            ->where(
                $qb->expr()->eq(
                    'p.organization_id',
                    $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)
                )
            );

        $result = $qb->executeQuery();
        $count = $result->fetchOne();
        $result->closeCursor();

        return (int) $count;
    }

    /**
     * Finds all organizations with their subscription and plan data.
     * Organizations are the primary entity - this is the main listing method.
     *
     * @param string $search Search term for organization name
     * @param int|null $limit Maximum results
     * @param int $offset Pagination offset
     * @param int $offset Pagination offset
     * @return array Array of organization data with subscription info
     */
    public function findAll(string $search = '', ?int $limit = null, int $offset = 0): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select(
            'o.id',
            'o.name',
            'o.nextcloud_group_id',
            's.id as subscription_id',
            's.status as subscription_status',
            's.started_at',
            's.ended_at',
            's.plan_id',
            'p.name as plan_name',
            'p.max_members',
            'p.max_projects'
        )
            ->from('organizations', 'o')
            ->leftJoin('o', 'subscriptions', 's', $qb->expr()->eq('o.id', 's.organization_id'))
            ->leftJoin('s', 'plans', 'p', $qb->expr()->eq('s.plan_id', 'p.id'));

        if (!empty($search)) {
            $qb->where(
                $qb->expr()->iLike('o.name', $qb->createNamedParameter('%' . $search . '%'))
            );
        }

        $qb->orderBy('o.name', 'ASC');

        if ($limit !== null && $limit > 0) {
            $qb->setMaxResults($limit);
        }

        if ($offset > 0) {
            $qb->setFirstResult($offset);
        }

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }

    /**
     * Get user count for an organization using the organization_id on users table.
     */
    public function getUserCountById(int $organizationId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('users')
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId)));

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }



    /**
     * Finds the organization group ID for a user.
     */
    public function findOrganizationGroupIdForUser(string $userId): ?string
    {
        $query = $this->db->getQueryBuilder();
        $expr = $query->expr();

        $query->select('o.nextcloud_group_id')
            ->from('users', 'u')
            ->innerJoin(
                'u',
                'organizations',
                'o',
                $expr->eq('u.organization_id', 'o.id')
            )
            ->where(
                $expr->eq('u.uid', $query->createParameter('user_id'))
            );

        $query->setParameter('user_id', $userId);

        $result = $query->executeQuery();
        $gid = $result->fetchOne();

        return ($gid === false) ? null : (string) $gid;
    }
}
