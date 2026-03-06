<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010010Date20260303010000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('org_aho_jobs')) {
            $jobs = $schema->getTable('org_aho_jobs');

            if (!$jobs->hasColumn('idempotency_key')) {
                $jobs->addColumn('idempotency_key', Types::STRING, [
                    'notnull' => false,
                    'length' => 128,
                    'default' => null,
                ]);
            }

            if (!$jobs->hasColumn('request_fingerprint')) {
                $jobs->addColumn('request_fingerprint', Types::STRING, [
                    'notnull' => false,
                    'length' => 128,
                    'default' => null,
                ]);
            }

            if (!$jobs->hasColumn('attempt')) {
                $jobs->addColumn('attempt', Types::INTEGER, [
                    'notnull' => true,
                    'default' => 1,
                ]);
            }

            if (!$jobs->hasColumn('started_at')) {
                $jobs->addColumn('started_at', Types::DATETIME, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }

            if (!$jobs->hasColumn('finished_at')) {
                $jobs->addColumn('finished_at', Types::DATETIME, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }

            if (!$jobs->hasIndex('org_aho_jobs_org_idem_unique')) {
                $jobs->addUniqueIndex(['organization_id', 'idempotency_key'], 'org_aho_jobs_org_idem_unique');
            }

            if (!$jobs->hasIndex('org_aho_jobs_req_fp_idx')) {
                $jobs->addIndex(['request_fingerprint'], 'org_aho_jobs_req_fp_idx');
            }
        }

        if ($schema->hasTable('org_aho_events')) {
            $events = $schema->getTable('org_aho_events');

            if (!$events->hasColumn('sequence_no')) {
                $events->addColumn('sequence_no', Types::BIGINT, [
                    'notnull' => true,
                    'default' => 1,
                ]);
            }

            if (!$events->hasColumn('step_key')) {
                $events->addColumn('step_key', Types::STRING, [
                    'notnull' => false,
                    'length' => 64,
                    'default' => null,
                ]);
            }

            if (!$events->hasIndex('org_aho_events_job_seq_idx')) {
                $events->addIndex(['job_id', 'sequence_no'], 'org_aho_events_job_seq_idx');
            }
        }

        if (!$schema->hasTable('org_aho_steps')) {
            $steps = $schema->createTable('org_aho_steps');

            $steps->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $steps->addColumn('job_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $steps->addColumn('step_key', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $steps->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 32,
            ]);
            $steps->addColumn('attempt', Types::INTEGER, [
                'notnull' => true,
                'default' => 1,
            ]);
            $steps->addColumn('retriable', Types::SMALLINT, [
                'notnull' => true,
                'default' => 1,
            ]);
            $steps->addColumn('result_json', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $steps->addColumn('error_message', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $steps->addColumn('started_at', Types::DATETIME, [
                'notnull' => false,
                'default' => null,
            ]);
            $steps->addColumn('finished_at', Types::DATETIME, [
                'notnull' => false,
                'default' => null,
            ]);
            $steps->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $steps->setPrimaryKey(['id']);
            $steps->addUniqueIndex(['job_id', 'step_key'], 'org_aho_steps_job_step_unique');
            $steps->addIndex(['job_id', 'status'], 'org_aho_steps_job_status_idx');
        }

        return $schema;
    }
}
