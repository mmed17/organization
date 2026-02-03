<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the oc_subscriptions table.
 */
class Version010002Date20260203000002 extends SimpleMigrationStep
{

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('subscriptions')) {
            $table = $schema->createTable('subscriptions');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('organization_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('plan_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 20,
                'default' => 'active',
            ]);
            $table->addColumn('started_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('ended_at', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('paused_at', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('cancelled_at', Types::DATETIME, [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['organization_id'], 'sub_org_id_idx');
            $table->addIndex(['status'], 'sub_status_idx');
        }

        return $schema;
    }
}
