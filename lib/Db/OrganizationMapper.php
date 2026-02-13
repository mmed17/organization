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
     * Finds an organization by one of its member user IDs.
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
                'organization_members',
                'om',
                $qb->expr()->eq('o.id', 'om.organization_id')
            )
            ->where(
                $qb->expr()->eq('om.user_uid', $qb->createNamedParameter($userId))
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
            $qb->createFunction('COUNT(om.user_uid)'),
            'user_count'
        )
            ->from('organization_members', 'om')
            ->where(
                $qb->expr()->eq('om.organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT))
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
    public function findAll(string $search = '', ?int $limit = null, int $offset = 0, ?int $organizationId = null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select(
            'o.id',
            'o.name',
            'o.admin_uid',
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
            ->leftJoin('s', 'plans', 'p', $qb->expr()->eq('s.plan_id', 'p.id'))
            ->leftJoin('o', 'organization_members', 'om', $qb->expr()->eq('o.id', 'om.organization_id'));

        $qb->selectAlias(
            $qb->createFunction('COUNT(om.user_uid)'),
            'user_count'
        );

        if (!empty($search)) {
            $qb->andWhere(
                $qb->expr()->iLike('o.name', $qb->createNamedParameter('%' . $search . '%'))
            );
        }

        if ($organizationId !== null) {
            $qb->andWhere(
                $qb->expr()->eq('o.id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT))
            );
        }

        $qb->groupBy('o.id')
            ->addGroupBy('o.name')
            ->addGroupBy('o.admin_uid')
            ->addGroupBy('s.id')
            ->addGroupBy('s.status')
            ->addGroupBy('s.started_at')
            ->addGroupBy('s.ended_at')
            ->addGroupBy('s.plan_id')
            ->addGroupBy('p.name')
            ->addGroupBy('p.max_members')
            ->addGroupBy('p.max_projects');

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
     * Get member count for an organization from organization_members table.
     */
    public function getUserCountById(int $organizationId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('organization_members')
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)));

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }
}
