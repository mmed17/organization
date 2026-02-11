<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds contact person fields to the oc_organizations table.
 */
class Version010007Date20260211000001 extends SimpleMigrationStep
{

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('organizations')) {
            $table = $schema->getTable('organizations');

            if (!$table->hasColumn('contact_first_name')) {
                $table->addColumn('contact_first_name', Types::STRING, [
                    'notnull' => false,
                    'length' => 255,
                    'default' => null,
                ]);
            }

            if (!$table->hasColumn('contact_last_name')) {
                $table->addColumn('contact_last_name', Types::STRING, [
                    'notnull' => false,
                    'length' => 255,
                    'default' => null,
                ]);
            }

            if (!$table->hasColumn('contact_email')) {
                $table->addColumn('contact_email', Types::STRING, [
                    'notnull' => false,
                    'length' => 255,
                    'default' => null,
                ]);
            }

            if (!$table->hasColumn('contact_phone')) {
                $table->addColumn('contact_phone', Types::STRING, [
                    'notnull' => false,
                    'length' => 64,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }
}
