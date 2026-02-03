<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the oc_plans table with default plans.
 */
class Version010001Date20260203000001 extends SimpleMigrationStep
{

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
                'notnull' => true,
                'default' => false,
            ]);

            $table->setPrimaryKey(['id']);
        }

        return $schema;
    }
}
