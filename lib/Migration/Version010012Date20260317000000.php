<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010012Date20260317000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('org_backup_jobs')) {
            $jobs = $schema->getTable('org_backup_jobs');

            if (!$jobs->hasColumn('backup_type')) {
                $jobs->addColumn('backup_type', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'full']);
            }
            if (!$jobs->hasColumn('trigger_source')) {
                $jobs->addColumn('trigger_source', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'manual']);
            }
            if (!$jobs->hasColumn('baseline_job_id')) {
                $jobs->addColumn('baseline_job_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
            }
            if (!$jobs->hasColumn('base_full_job_id')) {
                $jobs->addColumn('base_full_job_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
            }
            if (!$jobs->hasColumn('schedule_key')) {
                $jobs->addColumn('schedule_key', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
            }

            if (!$jobs->hasIndex('ob_jobs_org_type_status') && !$jobs->hasIndex('org_backup_jobs_org_type_status_idx')) {
                $jobs->addIndex(['organization_id', 'backup_type', 'status'], 'ob_jobs_org_type_status');
            }
            if (!$jobs->hasIndex('ob_jobs_schedule') && !$jobs->hasIndex('org_backup_jobs_schedule_idx')) {
                $jobs->addIndex(['organization_id', 'trigger_source', 'backup_type', 'schedule_key'], 'ob_jobs_schedule');
            }
        }

        $indexTable = $schema->hasTable('org_backup_file_index') ? $schema->getTable('org_backup_file_index') : $schema->createTable('org_backup_file_index');
        if (!$indexTable->hasColumn('id')) {
            $indexTable->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        }
        if (!$indexTable->hasColumn('organization_id')) {
            $indexTable->addColumn('organization_id', Types::BIGINT, ['notnull' => true]);
        }
        if (!$indexTable->hasColumn('file_id')) {
            $indexTable->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
        }
        if (!$indexTable->hasColumn('project_id')) {
            $indexTable->addColumn('project_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }
        if (!$indexTable->hasColumn('path')) {
            $indexTable->addColumn('path', Types::TEXT, ['notnull' => true]);
        }
        if (!$indexTable->hasColumn('etag')) {
            $indexTable->addColumn('etag', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
        }
        if (!$indexTable->hasColumn('mtime')) {
            $indexTable->addColumn('mtime', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }
        if (!$indexTable->hasColumn('size')) {
            $indexTable->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }
        if (!$indexTable->hasColumn('last_backup_job_id')) {
            $indexTable->addColumn('last_backup_job_id', Types::BIGINT, ['notnull' => true]);
        }
        if (!$indexTable->hasColumn('updated_at')) {
            $indexTable->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
        }
        if (!$indexTable->hasPrimaryKey()) {
            $indexTable->setPrimaryKey(['id']);
        }
        if (!$indexTable->hasIndex('ob_file_idx_org_file') && !$indexTable->hasIndex('org_backup_file_index_org_file_uidx')) {
            $indexTable->addUniqueIndex(['organization_id', 'file_id'], 'ob_file_idx_org_file');
        }
        if (!$indexTable->hasIndex('ob_file_idx_org') && !$indexTable->hasIndex('org_backup_file_index_org_idx')) {
            $indexTable->addIndex(['organization_id'], 'ob_file_idx_org');
        }

        return $schema;
    }
}
