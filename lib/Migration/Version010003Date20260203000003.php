<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the oc_subscriptions_history table for audit trail.
 */
class Version010003Date20260203000003 extends SimpleMigrationStep
{

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('subscriptions_history')) {
            $table = $schema->createTable('subscriptions_history');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('subscription_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('changed_by_user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('change_timestamp', Types::DATETIME, [
                'notnull' => true,
            ]);

            // Previous state columns
            $table->addColumn('previous_plan_id', Types::BIGINT, [
                'notnull' => false,
            ]);
            $table->addColumn('previous_status', Types::STRING, [
                'notnull' => false,
                'length' => 20,
            ]);
            $table->addColumn('previous_started_at', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('previous_ended_at', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('previous_paused_at', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('previous_cancelled_at', Types::DATETIME, [
                'notnull' => false,
            ]);

            // New state columns
            $table->addColumn('new_plan_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('new_status', Types::STRING, [
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('new_started_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('new_ended_at', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('new_paused_at', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('new_cancelled_at', Types::DATETIME, [
                'notnull' => false,
            ]);

            $table->addColumn('notes', Types::TEXT, [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['subscription_id'], 'subhist_sub_id_idx');
        }

        return $schema;
    }
}
