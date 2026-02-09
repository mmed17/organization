<?php
declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class PlanMapper extends QBMapper
{
    public function __construct(IDBConnection $db, private LoggerInterface $logger)
    {
        parent::__construct($db, 'plans', Plan::class);
    }

    /**
     * Inserts a new plan entity.
     * @return Plan
     */
    public function create(
        string $name,
        int $maxMembers,
        int $maxProjects,
        int $sharedStoragePerProject,
        int $privateStoragePerUser,
        ?float $price,
        ?string $currency = 'EUR',
        ?bool $isPublic = false
    ): Plan {
        $plan = new Plan();

        $plan->setName($name);
        $plan->setMaxMembers($maxMembers);
        $plan->setMaxProjects($maxProjects);
        $plan->setSharedStoragePerProject($sharedStoragePerProject);
        $plan->setPrivateStoragePerUser($privateStoragePerUser);
        $plan->setPrice($price);
        $plan->setCurrency($currency);
        $plan->setIsPublic($isPublic);

        return $this->insert($plan);
    }


    /**
     * Custom insert method to handle boolean is_public correctly.
     *
     * @param Plan $plan The entity to insert.
     * @return Plan The entity with the new ID set.
     */
    public function insert(Entity $plan): Plan
    {
        if (!$plan instanceof Plan) {
            throw new \InvalidArgumentException('Entity must be an instance of Plan');
        }

        $qb = $this->db->getQueryBuilder();

        $qb->insert($this->getTableName())
            ->values([
                'name' => $qb->createNamedParameter($plan->getName()),
                'max_members' => $qb->createNamedParameter($plan->getMaxMembers(), \PDO::PARAM_INT),
                'max_projects' => $qb->createNamedParameter($plan->getMaxProjects(), \PDO::PARAM_INT),
                'shared_storage_per_project' => $qb->createNamedParameter($plan->getSharedStoragePerProject(), \PDO::PARAM_INT),
                'private_storage_per_user' => $qb->createNamedParameter($plan->getPrivateStoragePerUser(), \PDO::PARAM_INT),
                'price' => $qb->createNamedParameter($plan->getPrice()),
                'currency' => $qb->createNamedParameter($plan->getCurrency()),
                'is_public' => $qb->createNamedParameter($plan->getIsPublic(), IQueryBuilder::PARAM_BOOL)
            ]);

        $qb->executeStatement();

        $plan->setId($qb->getLastInsertId());

        return $plan;
    }

    /**
     * Custom update method to handle boolean is_public correctly.
     *
     * @param Plan $plan The entity to update.
     * @return Plan The updated entity.
     */
    public function update(Entity $plan): Plan
    {
        if (!$plan instanceof Plan) {
            throw new \InvalidArgumentException('Entity must be an instance of Plan');
        }

        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('name', $qb->createNamedParameter($plan->getName()))
            ->set('max_members', $qb->createNamedParameter($plan->getMaxMembers(), \PDO::PARAM_INT))
            ->set('max_projects', $qb->createNamedParameter($plan->getMaxProjects(), \PDO::PARAM_INT))
            ->set('shared_storage_per_project', $qb->createNamedParameter($plan->getSharedStoragePerProject(), \PDO::PARAM_INT))
            ->set('private_storage_per_user', $qb->createNamedParameter($plan->getPrivateStoragePerUser(), \PDO::PARAM_INT))
            ->set('price', $qb->createNamedParameter($plan->getPrice()))
            ->set('currency', $qb->createNamedParameter($plan->getCurrency()))
            ->set('is_public', $qb->createNamedParameter($plan->getIsPublic(), IQueryBuilder::PARAM_BOOL))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($plan->getId(), \PDO::PARAM_INT)));

        $qb->executeStatement();

        return $plan;
    }

    /**
     * Finds all public plans.
     * @return Plan[]
     */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())->where(
            $qb->expr()->eq('is_public', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
        );
        return $this->findEntities($qb);
    }

    /**
     * Finds a single plan by ID.
     *
     * @param int $id The ID of the plan to find.
     * @return Plan|null
     */
    public function find(int $id): ?Plan
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }
}
