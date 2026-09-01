<?php
namespace GT\Orm\Migration\Query;

use GT\Orm\Migration\SchemaChange;
use GT\Orm\Migration\SchemaChangeType;
use GT\Orm\Migration\SchemaDiff;
use GT\Orm\Migration\SchemaField;
use GT\Orm\Migration\SchemaTable;
use LogicException;

class SchemaDiffQueryMySQL implements SchemaDiffSql {
	public function generateSqlList(SchemaDiff $diff):array {
		return array_map(
			fn(SchemaChange $change) => $this->changeSql($change),
			$diff->getChangeList(),
		);
	}

	private function changeSql(SchemaChange $change):string {
		$table = $this->requireAfterTable($change);
		return match($change->type) {
			SchemaChangeType::CREATE_TABLE => (new SchemaQueryMySQL($table))->generateSql(),
			SchemaChangeType::ADD_FIELD => "alter table {$this->identifier($table->getName())} "
				. "add column " . (new SchemaQueryMySQL($table))
					->generateColumnDefinition($this->requireAfterField($change)),
			SchemaChangeType::ALTER_FIELD => $this->alterFieldSql($change, $table),
			default => throw new LogicException(
				"MySQL schema change {$change->type->name} is not executable",
			),
		};
	}

	private function alterFieldSql(
		SchemaChange $change,
		SchemaTable $table,
	):string {
		$field = $this->requireAfterField($change);
		$definition = (new SchemaQueryMySQL($table))
			->generateColumnDefinition($field, false);
		return "alter table {$this->identifier($table->getName())} modify column $definition";
	}

	private function requireAfterTable(SchemaChange $change):SchemaTable {
		return $change->afterTable
			?? throw new LogicException("Schema change has no resulting table");
	}

	private function requireAfterField(SchemaChange $change):SchemaField {
		return $change->afterField
			?? throw new LogicException("Schema change has no resulting field");
	}

	private function identifier(string $name):string {
		return "`" . str_replace("`", "``", $name) . "`";
	}
}
