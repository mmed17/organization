<?php
declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class SubscriptionMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'subscriptions', Subscription::class);
    }

    /**
     * Finds the subscription for a given organization ID.
     * @param int $organizationId
     * @return Subscription|null
     */
    public function findByOrganizationId(int $organizationId): ?Subscription
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'organization_id',
                    $qb->createNamedParameter($organizationId)
                )
            );

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Finds an active subscription for a given organization ID.
     */
    public function findActiveSubscriptionForOrganization(int $organizationId): ?Subscription
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId)))
            ->andWhere(
                $qb->expr()->gt('ended_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
            )
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Finds all active subscriptions that have passed their end date
     * and updates their status to 'expired'.
     *
     * @return int The number of subscriptions that were updated.
     */
    public function invalidateExpiredSubscriptions(): int
    {
        $now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $findQuery = $this->db->getQueryBuilder();
        $findQuery->select('id')
            ->from('subscriptions')
            ->where($findQuery->expr()->eq('status', $findQuery->createNamedParameter('active')))
            ->andWhere($findQuery->expr()->isNotNull('ended_at'))
            ->andWhere($findQuery->expr()->lt('ended_at', $findQuery->createNamedParameter($now)));

        $result = $findQuery->execute();
        $expiredSubscriptionIds = $result->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($expiredSubscriptionIds)) {
            return 0;
        }

        $updateQuery = $this->db->getQueryBuilder();
        $updateQuery->update('subscriptions')
            ->set('status', $updateQuery->createNamedParameter('expired'))
            ->where($updateQuery->expr()->in('id', $updateQuery->createParameter('ids')));

        $updateQuery->setParameter('ids', $expiredSubscriptionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY);

        return $updateQuery->executeStatement();
    }
}
