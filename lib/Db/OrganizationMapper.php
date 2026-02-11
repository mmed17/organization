<?php
declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class OrganizationMapper extends QBMapper
{
    public function __construct(
        IDBConnection $db,
        private LoggerInterface $logger
    ) {
        parent::__construct($db, 'organizations', Organization::class);
    }

    /**
     * Finds an organization by its ID.
     *
     * @param int $id The organization ID.
     * @return Organization|null
     */
    public function find(int $id): ?Organization
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));

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
     *
     * @param string $search Search term for organization name
     * @param int|null $limit Maximum results
     * @param int $offset Pagination offset
     * @return array Array of organization data with subscription info
     */
    public function findAll(string $search = '', ?int $limit = null, int $offset = 0): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select(
            'o.id',
            'o.name',
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
}
