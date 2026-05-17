<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010014Date20260517000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('organizations')) {
			$organizations = $schema->getTable('organizations');
			if (!$organizations->hasColumn('type')) {
				$organizations->addColumn('type', Types::STRING, [
					'notnull' => true,
					'length' => 16,
					'default' => 'standard',
				]);
			}
		}

		return $schema;
	}
}
