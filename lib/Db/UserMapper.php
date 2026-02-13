<?php
declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\IDBConnection;

class UserMapper
{
    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * Assign or remove a user from an organization.
     */
    public function addOrganizationToUser(string $userId, ?int $organizationId, string $role = 'member'): void
    {
        $delete = $this->db->getQueryBuilder();
        $delete->delete('organization_members')
            ->where($delete->expr()->eq('user_uid', $delete->createNamedParameter($userId)))
            ->executeStatement();

        if ($organizationId === null) {
            return;
        }

        $insert = $this->db->getQueryBuilder();
        $insert->insert('organization_members')
            ->values([
                'organization_id' => $insert->createNamedParameter($organizationId, \PDO::PARAM_INT),
                'user_uid' => $insert->createNamedParameter($userId),
                'role' => $insert->createNamedParameter($role),
                'created_at' => $insert->createNamedParameter((new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')),
            ])
            ->executeStatement();
    }

    public function countUsersInOrganization(int $organizationId): int
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

    /**
     * @return array{organization_id:int,role:string}|null
     */
    public function getOrganizationMembership(string $userId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('organization_id', 'role')
            ->from('organization_members')
            ->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($userId)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return null;
        }

        return [
            'organization_id' => (int) $row['organization_id'],
            'role' => (string) $row['role'],
        ];
    }

    /**
     * @return array<int,array{user_uid:string,role:string,created_at:?string}>
     */
    public function getOrganizationMembers(int $organizationId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_uid', 'role', 'created_at')
            ->from('organization_members')
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->orderBy('created_at', 'ASC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map(static fn (array $row): array => [
            'user_uid' => (string) $row['user_uid'],
            'role' => (string) $row['role'],
            'created_at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
        ], $rows);
    }

    public function removeUserFromOrganization(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('organization_members')
            ->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($userId)))
            ->executeStatement();
    }

    /**
     * @return string[]
     */
    public function getOrganizationAdminUserIds(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_uid')
            ->from('organization_members')
            ->where($qb->expr()->eq('role', $qb->createNamedParameter('admin')));

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_values(array_unique(array_map(static fn (array $row): string => (string) $row['user_uid'], $rows)));
    }
}
