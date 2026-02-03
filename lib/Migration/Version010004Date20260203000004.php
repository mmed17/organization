<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the oc_custom_projects and oc_proj_private_folders tables.
 */
class Version010004Date20260203000004 extends SimpleMigrationStep
{

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create custom_projects table
        if (!$schema->hasTable('custom_projects')) {
            $table = $schema->createTable('custom_projects');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('organization_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('group_folder_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('board_id', Types::BIGINT, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['organization_id'], 'proj_org_id_idx');
            $table->addUniqueIndex(['group_folder_id'], 'proj_gf_id_idx');
        }

        // Create proj_private_folders table
        if (!$schema->hasTable('proj_private_folders')) {
            $table = $schema->createTable('proj_private_folders');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('project_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('folder_id', Types::BIGINT, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['project_id'], 'privfld_proj_id_idx');
            $table->addUniqueIndex(['project_id', 'user_id'], 'privfld_proj_user_idx');
        }

        return $schema;
    }
}
