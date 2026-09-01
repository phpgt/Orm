<?php
namespace GT\Orm\Migration\Query;

use GT\Orm\Migration\SchemaChange;
use GT\Orm\Migration\SchemaChangeType;
use GT\Orm\Migration\SchemaDiff;
use GT\Orm\Migration\SchemaField;
use GT\Orm\Migration\SchemaTable;
use LogicException;

class SchemaDiffQuerySQLite implements SchemaDiffSql {
	public function generateSqlList(SchemaDiff $diff):array {
		$sqlList = [];
		$rebuildTableList = $this->rebuildTableList($diff);
		foreach($diff->getChangeList() as $change) {
			if(isset($rebuildTableList[$change->tableName])) {
				continue;
			}
			$sqlList[] = $this->changeSql($change);
		}
		foreach($rebuildTableList as $change) {
			array_push($sqlList, ...$this->rebuildSqlList($change));
		}
		return $sqlList;
	}

	/** @return array<string, SchemaChange> */
	private function rebuildTableList(SchemaDiff $diff):array {
		$tableList = [];
		foreach($diff->getChangeList() as $change) {
			if($change->type === SchemaChangeType::ALTER_FIELD) {
				$tableList[$change->tableName] = $change;
			}
		}
		return $tableList;
	}

	private function changeSql(SchemaChange $change):string {
		return match($change->type) {
			SchemaChangeType::CREATE_TABLE => (new SchemaQuerySQLite(
				$this->requireAfterTable($change),
			))->generateSql(),
			SchemaChangeType::ADD_FIELD => $this->addFieldSql($change),
			default => throw new LogicException(
				"SQLite schema change {$change->type->name} is not executable",
			),
		};
	}

	private function addFieldSql(SchemaChange $change):string {
		$table = $this->requireAfterTable($change);
		$field = $this->requireAfterField($change);
		$definition = (new SchemaQuerySQLite($table))
			->generateColumnDefinition($field);
		return "alter table {$this->identifier($table->getName())} add column $definition";
	}

	/** @return array<string> */
	private function rebuildSqlList(SchemaChange $change):array {
		$before = $change->beforeTable;
		$after = $change->afterTable;
		if($before === null || $after === null) {
			throw new LogicException("SQLite table rebuild is incomplete");
		}
		$temporaryName = "_orm_rebuild_" . $after->getName();
		$temporaryTable = $after->copy($temporaryName);
		$columnList = [];
		foreach($after->getFieldList() as $field) {
			if($before->getField($field->getName()) !== null) {
				$columnList[] = $this->identifier($field->getName());
			}
		}
		if($columnList === []) {
			throw new LogicException("SQLite table rebuild has no columns to preserve");
		}
		$columns = implode(", ", $columnList);
		return [
			(new SchemaQuerySQLite($temporaryTable))->generateSql(),
			"insert into {$this->identifier($temporaryName)} ($columns) "
				. "select $columns from {$this->identifier($before->getName())}",
			"drop table {$this->identifier($before->getName())}",
			"alter table {$this->identifier($temporaryName)} rename to "
				. $this->identifier($after->getName()),
		];
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
