<?php
namespace Gt\Orm\Migration\Query;

use Gt\Orm\Migration\SchemaField;
use Gt\Orm\Migration\SchemaTable;

abstract class SchemaQuery {
	protected string $templateCreateStatement = "create table `{{tableName}}` ({{columnDefList}}\n)";
	protected string $templateColumnDef = "`{{columnName}}` {{columnType}} {{columnConstraint}}";
	protected string $templateColumnConstraint = "{{nullable}} {{default}} {{primaryKey}} {{unique}} {{foreignKey}}";

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

// TODO: Firstly, map all of the allowed constraints here.
// IMPORTANT: A null/not null with default IS actually a constraint... so it should probably just go here instead of having its own named part called columnNullDefault
// Each constraint can be checked and concatenated individually.
// ... then, check syntax for MySQL and SQLite individually and override things as necessary!
// https://www.sqlite.org/lang_createtable.html - see column-def -> column-constraint

			$columnSql = $this->inject(
				$columnSql,
				"columnConstraint",
				$this->generateColumnConstraint($field),
			);

			$sql .= $this->tidyWhitespace($columnSql);
		}

		return $this->tidyWhitespace($sql);
	}

	public function generateColumnConstraint(SchemaField $field):string {
		$constraintSql = $this->templateColumnConstraint;

		$nullableInjection = $field->isNullable() ? "null" : "not null";
		$constraintSql = $this->inject(
			$constraintSql,
			"nullable",
			$nullableInjection,
		);

		$defaultInjection = $field->hasDefaultValue()
			? "default " . $field->getDefaultValue()
			: "";
		$constraintSql = $this->inject(
			$constraintSql,
			"default",
			$defaultInjection,
		);

		$uniqueInjection = "";
		if($field->isUnique()) {
			$uniqueInjection = "unique";
		}
		$constraintSql = $this->inject(
			$constraintSql,
			"unique",
			$uniqueInjection,
		);

		$primaryKeyInjection = "";
		if($this->schemaTable->getPrimaryKey() === $field) {
			$autoincrement = $field->isAutoIncrement()
				? "autoincrement"
				: "";

			$primaryKeyInjection = "primary key$autoincrement";
		}
		$constraintSql = $this->inject(
			$constraintSql,
			"primaryKey",
			$primaryKeyInjection
		);

		$foreignKey = "";
		if($field->isForeignKey()) {
// TODO: Handle	"on delete", "on duplicate"
			$deleteDuplicateBehaviour = "";

			$foreignKey = "references `"
				. $field->getForeignKeyReferenceTable()
				. "` (`"
				. $field->getForeignKeyReferenceField()
				. "`) "
				. $deleteDuplicateBehaviour;
		}
		$constraintSql = $this->inject(
			$constraintSql,
			"foreignKey",
			$foreignKey,
		);

		return $constraintSql;
	}

	protected function quote(bool|int|float|string $value):string {
		if(is_string($value)) {
			$value = str_replace("'", "\'", $value);
			$value = "'$value'";
		}

		return $value;
	}

	abstract protected function type(string $type):string;

	private function inject(string $sql, string $key, string $value):string {
		return str_replace("{{" . $key . "}}", $value, $sql);
	}

	private function tidyWhitespace(string $sql):string {
		while(str_contains($sql, "  ")) {
			$sql = str_replace("  ", " ", $sql);
		}

		while(str_contains($sql, " ,")) {
			$sql = str_replace(" ,", ",", $sql);
		}

		return $sql;
	}
}
