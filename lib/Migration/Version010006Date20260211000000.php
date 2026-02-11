<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drops the nextcloud_group_id column and its unique index from oc_organizations.
 * Organizations are no longer linked to Nextcloud groups.
 */
class Version010006Date20260211000000 extends SimpleMigrationStep
{

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('organizations')) {
            $table = $schema->getTable('organizations');

            if ($table->hasIndex('org_nc_group_id_idx')) {
                $table->dropIndex('org_nc_group_id_idx');
            }

            if ($table->hasColumn('nextcloud_group_id')) {
                $table->dropColumn('nextcloud_group_id');
            }
        }

        return $schema;
    }
}
