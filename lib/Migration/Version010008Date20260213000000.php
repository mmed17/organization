<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010008Date20260213000000 extends SimpleMigrationStep
{
    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('organization_members')) {
            $table = $schema->createTable('organization_members');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('organization_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('user_uid', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('role', Types::STRING, [
                'notnull' => true,
                'length' => 20,
                'default' => 'member',
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_uid'], 'org_members_user_uid_uidx');
            $table->addIndex(['organization_id'], 'org_members_org_id_idx');
            $table->addIndex(['organization_id', 'role'], 'org_members_org_role_idx');
        }

        if ($schema->hasTable('organizations')) {
            $organizations = $schema->getTable('organizations');
            if (!$organizations->hasColumn('admin_uid')) {
                $organizations->addColumn('admin_uid', Types::STRING, [
                    'notnull' => false,
                    'length' => 64,
                    'default' => null,
                ]);
                $organizations->addIndex(['admin_uid'], 'org_admin_uid_idx');
            }
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $select = $this->db->getQueryBuilder();
        try {
            $result = $select->select('uid', 'organization_id')
                ->from('users')
                ->where($select->expr()->isNotNull('organization_id'))
                ->executeQuery();
        } catch (\Throwable $e) {
            return;
        }

        while ($row = $result->fetch()) {
            $uid = (string) ($row['uid'] ?? '');
            $organizationId = isset($row['organization_id']) ? (int) $row['organization_id'] : null;
            if ($uid === '' || $organizationId === null || $organizationId <= 0) {
                continue;
            }

            $existing = $this->db->getQueryBuilder();
            $exists = $existing->select('id')
                ->from('organization_members')
                ->where($existing->expr()->eq('user_uid', $existing->createNamedParameter($uid)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            if ($exists !== false) {
                continue;
            }

            $insert = $this->db->getQueryBuilder();
            $insert->insert('organization_members')
                ->values([
                    'organization_id' => $insert->createNamedParameter($organizationId, \PDO::PARAM_INT),
                    'user_uid' => $insert->createNamedParameter($uid),
                    'role' => $insert->createNamedParameter('member'),
                    'created_at' => $insert->createNamedParameter($now),
                ])
                ->executeStatement();
        }

        $result->closeCursor();
    }
}
