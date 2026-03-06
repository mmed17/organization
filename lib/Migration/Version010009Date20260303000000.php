<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010009Date20260303000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('org_aho_jobs')) {
            $table = $schema->createTable('org_aho_jobs');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('organization_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('source_user_uid', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('target_user_uid', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('requested_by_uid', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 32,
            ]);
            $table->addColumn('dry_run', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('remove_source_from_groups', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('remap_deck_content', Types::SMALLINT, [
                'notnull' => true,
                'default' => 1,
            ]);
            $table->addColumn('result_json', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('error_message', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['organization_id', 'status'], 'org_aho_jobs_org_status_idx');
            $table->addIndex(['source_user_uid'], 'org_aho_jobs_source_uid_idx');
            $table->addIndex(['target_user_uid'], 'org_aho_jobs_target_uid_idx');
        }

        if (!$schema->hasTable('org_aho_events')) {
            $table = $schema->createTable('org_aho_events');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('job_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('level', Types::STRING, [
                'notnull' => true,
                'length' => 16,
            ]);
            $table->addColumn('message', Types::TEXT, [
                'notnull' => true,
            ]);
            $table->addColumn('payload_json', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['job_id'], 'org_aho_events_job_id_idx');
        }

        return $schema;
    }
}
