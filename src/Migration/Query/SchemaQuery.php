<?php
namespace Gt\Orm\Migration\Query;

use Gt\Orm\Migration\SchemaField;
use Gt\Orm\Migration\SchemaTable;

abstract class SchemaQuery {
	protected $templateCreateStatement = "create table `{{tableName}}` ({{columnDefList}}\n)";
	protected $templateColumnDef = "`{{columnName}}` {{columnType}} {{columnNullDefault}} {{columnConstraint}}";

	public function __construct(
		protected SchemaTable $schemaTable
	) {}

	public function generateSql():string {
		$sql = $this->templateCreateStatement;
		$sql = $this->inject(
			$sql,
			"tableName",
			$this->schemaTable->getName(),
		);
		$sql = $this->inject(
			$sql,
			"columnDefList",
			$this->generateColumnDefList($this->schemaTable->getFieldList())
		);

		return $sql;
	}

	/** @param array<SchemaField> $schemaFieldList */
	public function generateColumnDefList(array $schemaFieldList):string {
		$sql = "";

		foreach($schemaFieldList as $i => $field) {
			if($i > 0) {
				$sql .= ",";
			}

			$sql .= "\n";
			$columnSql = $this->templateColumnDef;

			$columnSql = $this->inject(
				$columnSql,
				"columnName",
				$field->getName(),
			);

			$columnSql = $this->inject(
				$columnSql,
				"columnType",
				$this->type($field->getType()),
			);

			$nullDefault = "";
			if($field->getNullable()) {
				$nullDefault .= "null";
			}
			else {
				$nullDefault = "not null";
			}

			if($field->hasDefaultValue()) {
				$nullDefault .= " default "
					. $this->quote($field->getDefaultValue());
			}

			$columnSql = $this->inject(
				$columnSql,
				"columnNullDefault",
				$nullDefault,
			);

// TODO: Firstly, map all of the allowed constraints here.
// IMPORTANT: A null/not null with default IS actually a constraint... so it should probably just go here instead of having its own named part called columnNullDefault
// Each constraint can be checked and concatenated individually.
// ... then, check syntax for MySQL and SQLite individually and override things as necessary!
// https://www.sqlite.org/lang_createtable.html - see column-def -> column-constraint
			$constraint = "";
			if($field->isPrimaryKey()) {

			}
			if($field->isForeignKey()) {

			}

			$columnSql = $this->inject(
				$columnSql,
				"columnConstraint",
				$constraint,
			);

			$sql .= $columnSql;
		}

		return $sql;
	}

	protected function quote(bool|int|float|string $value):string {
		if(is_string($value)) {
			$value = str_replace("'", "\'", $value);
			$value = "'$value'";
		}

		return $value;
	}

	protected function type(string $type):string {
		return $type;
	}

	private function inject(string $sql, string $key, string $value):string {
		return str_replace("{{" . $key . "}}", $value, $sql);
	}
}
