<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010011Date20260314000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $jobs = $schema->hasTable('org_backup_jobs') ? $schema->getTable('org_backup_jobs') : $schema->createTable('org_backup_jobs');
        if (!$jobs->hasColumn('id')) {
            $jobs->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        }
        if (!$jobs->hasColumn('organization_id')) {
            $jobs->addColumn('organization_id', Types::BIGINT, ['notnull' => true]);
        }
        if (!$jobs->hasColumn('requested_by_uid')) {
            $jobs->addColumn('requested_by_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
        }
        if (!$jobs->hasColumn('status')) {
            $jobs->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
        }
        if (!$jobs->hasColumn('options_json')) {
            $jobs->addColumn('options_json', Types::TEXT, ['notnull' => false, 'default' => null]);
        }
        if (!$jobs->hasColumn('attempt')) {
            $jobs->addColumn('attempt', Types::INTEGER, ['notnull' => true, 'default' => 1]);
        }
        if (!$jobs->hasColumn('result_json')) {
            $jobs->addColumn('result_json', Types::TEXT, ['notnull' => false, 'default' => null]);
        }
        if (!$jobs->hasColumn('error_message')) {
            $jobs->addColumn('error_message', Types::TEXT, ['notnull' => false, 'default' => null]);
        }
        if (!$jobs->hasColumn('artifact_name')) {
            $jobs->addColumn('artifact_name', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        }
        if (!$jobs->hasColumn('artifact_size')) {
            $jobs->addColumn('artifact_size', Types::BIGINT, ['notnull' => false, 'default' => null]);
        }
        if (!$jobs->hasColumn('created_at')) {
            $jobs->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
        }
        if (!$jobs->hasColumn('updated_at')) {
            $jobs->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
        }
        if (!$jobs->hasColumn('started_at')) {
            $jobs->addColumn('started_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
        }
        if (!$jobs->hasColumn('finished_at')) {
            $jobs->addColumn('finished_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
        }
        if (!$jobs->hasColumn('expires_at')) {
            $jobs->addColumn('expires_at', Types::DATETIME, ['notnull' => true]);
        }
        if (!$jobs->hasPrimaryKey()) {
            $jobs->setPrimaryKey(['id']);
        }
        if (!$jobs->hasIndex('ob_jobs_org_status') && !$jobs->hasIndex('org_backup_jobs_org_status_idx')) {
            $jobs->addIndex(['organization_id', 'status'], 'ob_jobs_org_status');
        }
        if (!$jobs->hasIndex('ob_jobs_status_id') && !$jobs->hasIndex('org_backup_jobs_status_id_idx')) {
            $jobs->addIndex(['status', 'id'], 'ob_jobs_status_id');
        }
        if (!$jobs->hasIndex('ob_jobs_expires') && !$jobs->hasIndex('org_backup_jobs_expires_idx')) {
            $jobs->addIndex(['expires_at'], 'ob_jobs_expires');
        }

        $steps = $schema->hasTable('org_backup_steps') ? $schema->getTable('org_backup_steps') : $schema->createTable('org_backup_steps');
        if (!$steps->hasColumn('id')) {
            $steps->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        }
        if (!$steps->hasColumn('job_id')) {
            $steps->addColumn('job_id', Types::BIGINT, ['notnull' => true]);
        }
        if (!$steps->hasColumn('step_key')) {
            $steps->addColumn('step_key', Types::STRING, ['notnull' => true, 'length' => 64]);
        }
        if (!$steps->hasColumn('status')) {
            $steps->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
        }
        if (!$steps->hasColumn('attempt')) {
            $steps->addColumn('attempt', Types::INTEGER, ['notnull' => true, 'default' => 1]);
        }
        if (!$steps->hasColumn('retriable')) {
            $steps->addColumn('retriable', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
        }
        if (!$steps->hasColumn('result_json')) {
            $steps->addColumn('result_json', Types::TEXT, ['notnull' => false, 'default' => null]);
        }
        if (!$steps->hasColumn('error_message')) {
            $steps->addColumn('error_message', Types::TEXT, ['notnull' => false, 'default' => null]);
        }
        if (!$steps->hasColumn('started_at')) {
            $steps->addColumn('started_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
        }
        if (!$steps->hasColumn('finished_at')) {
            $steps->addColumn('finished_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
        }
        if (!$steps->hasColumn('updated_at')) {
            $steps->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
        }
        if (!$steps->hasPrimaryKey()) {
            $steps->setPrimaryKey(['id']);
        }
        if (!$steps->hasIndex('ob_steps_job_step') && !$steps->hasIndex('org_backup_steps_job_step_uidx')) {
            $steps->addUniqueIndex(['job_id', 'step_key'], 'ob_steps_job_step');
        }
        if (!$steps->hasIndex('ob_steps_job_status') && !$steps->hasIndex('org_backup_steps_job_status_idx')) {
            $steps->addIndex(['job_id', 'status'], 'ob_steps_job_status');
        }

        $events = $schema->hasTable('org_backup_events') ? $schema->getTable('org_backup_events') : $schema->createTable('org_backup_events');
        if (!$events->hasColumn('id')) {
            $events->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        }
        if (!$events->hasColumn('job_id')) {
            $events->addColumn('job_id', Types::BIGINT, ['notnull' => true]);
        }
        if (!$events->hasColumn('sequence_no')) {
            $events->addColumn('sequence_no', Types::BIGINT, ['notnull' => true, 'default' => 1]);
        }
        if (!$events->hasColumn('step_key')) {
            $events->addColumn('step_key', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        }
        if (!$events->hasColumn('level')) {
            $events->addColumn('level', Types::STRING, ['notnull' => true, 'length' => 16]);
        }
        if (!$events->hasColumn('message')) {
            $events->addColumn('message', Types::TEXT, ['notnull' => true]);
        }
        if (!$events->hasColumn('payload_json')) {
            $events->addColumn('payload_json', Types::TEXT, ['notnull' => false, 'default' => null]);
        }
        if (!$events->hasColumn('created_at')) {
            $events->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
        }
        if (!$events->hasPrimaryKey()) {
            $events->setPrimaryKey(['id']);
        }
        if (!$events->hasIndex('ob_events_job_seq') && !$events->hasIndex('org_backup_events_job_seq_idx')) {
            $events->addIndex(['job_id', 'sequence_no'], 'ob_events_job_seq');
        }
        if (!$events->hasIndex('ob_events_job') && !$events->hasIndex('org_backup_events_job_idx')) {
            $events->addIndex(['job_id'], 'ob_events_job');
        }

        return $schema;
    }
}
