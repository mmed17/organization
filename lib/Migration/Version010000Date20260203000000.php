<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the oc_organizations table.
 */
class Version010000Date20260203000000 extends SimpleMigrationStep
{

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('organizations')) {
            $table = $schema->createTable('organizations');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('nextcloud_group_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['nextcloud_group_id'], 'org_nc_group_id_idx');
        }

        return $schema;
    }
}
