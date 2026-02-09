<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the oc_plans table with default plans.
 */
class Version010001Date20260203000001 extends SimpleMigrationStep
{
    private IDBConnection $db;

    public function __construct(IDBConnection $db)
    {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('plans')) {
            $table = $schema->createTable('plans');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('max_projects', Types::INTEGER, [
                'notnull' => true,
                'default' => 1,
            ]);
            $table->addColumn('max_members', Types::INTEGER, [
                'notnull' => true,
                'default' => 5,
            ]);
            $table->addColumn('shared_storage_per_project', Types::BIGINT, [
                'notnull' => true,
                'default' => 5368709120, // 5GB in bytes
            ]);
            $table->addColumn('private_storage_per_user', Types::BIGINT, [
                'notnull' => true,
                'default' => 1073741824, // 1GB in bytes
            ]);
            $table->addColumn('price', Types::DECIMAL, [
                'notnull' => false,
                'precision' => 10,
                'scale' => 2,
            ]);
            $table->addColumn('currency', Types::STRING, [
                'notnull' => true,
                'length' => 3,
                'default' => 'EUR',
            ]);
            $table->addColumn('is_public', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);

            $table->setPrimaryKey(['id']);
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // Check if plans already exist to avoid duplicates
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id')
            ->from('plans')
            ->setMaxResults(1)
            ->executeQuery();

        if ($result->fetch() !== false) {
            $result->closeCursor();
            return; // Plans already exist, skip seeding
        }
        $result->closeCursor();

        // Insert default plans
        $defaultPlans = [
            // Free Plan: 50 MB shared per project, 1 GB private per user
            ['Free', 1, 1, 52428800, 1073741824, 0, 'EUR', true],
            // Pro Plan: 100 MB shared per project, 5 GB private per user
            ['Pro', 2, 5, 104857600, 5368709120, 10, 'EUR', true],
            // Gold Plan: 1 GB shared per project, 20 GB private per user
            ['Gold', 5, 20, 1073741824, 21474836480, 25, 'EUR', true],
        ];

        foreach ($defaultPlans as $plan) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('plans')
                ->values([
                    'name' => $qb->createNamedParameter($plan[0]),
                    'max_projects' => $qb->createNamedParameter($plan[1], \PDO::PARAM_INT),
                    'max_members' => $qb->createNamedParameter($plan[2], \PDO::PARAM_INT),
                    'shared_storage_per_project' => $qb->createNamedParameter($plan[3], \PDO::PARAM_INT),
                    'private_storage_per_user' => $qb->createNamedParameter($plan[4], \PDO::PARAM_INT),
                    'price' => $qb->createNamedParameter($plan[5]),
                    'currency' => $qb->createNamedParameter($plan[6]),
                    'is_public' => $qb->createNamedParameter($plan[7], IQueryBuilder::PARAM_BOOL),
                ])
                ->executeStatement();
        }
    }
}
