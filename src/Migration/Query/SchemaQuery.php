<?php
namespace GT\Orm\Migration\Query;

use GT\Orm\Migration\SchemaField;
use GT\Orm\Migration\SchemaTable;

abstract class SchemaQuery {
	protected string $templateCreateStatement = "create table `{{tableName}}` ({{columnDefList}}\n)";
	protected string $templateColumnDef = "`{{columnName}}` {{columnType}} {{columnConstraint}}";
	protected string $templateColumnConstraint = "{{nullable}} {{default}} {{primaryKey}} {{unique}} {{foreignKey}}";

	protected string $columnDefPartAutoIncrement = "autoincrement";

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
			$sql .= $this->generateColumnDefinition($field);
		}

		return $this->tidyWhitespace($sql);
	}

	public function generateColumnDefinition(
		SchemaField $field,
		bool $includePrimaryKey = true,
	):string {
		$columnSql = $this->inject(
			$this->templateColumnDef,
			"columnName",
			$field->getName(),
		);
		$columnSql = $this->inject(
			$columnSql,
			"columnType",
			$this->type($field->requireType()),
		);
		$columnSql = $this->inject(
			$columnSql,
			"columnConstraint",
			$this->generateColumnConstraint($field, $includePrimaryKey),
		);
		return trim($this->tidyWhitespace($columnSql));
	}

	public function generateColumnConstraint(
		SchemaField $field,
		bool $includePrimaryKey = true,
	):string {
		$constraintSql = $this->templateColumnConstraint;

		$nullableInjection = $field->isNullable() ? "null" : "not null";
		$constraintSql = $this->inject(
			$constraintSql,
			"nullable",
			$nullableInjection,
		);

		$defaultInjection = $field->hasDefaultValue()
			? "default " . $this->quote($field->getDefaultValue())
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
				? " " . $this->columnDefPartAutoIncrement
				: "";
			$primaryKeyInjection = $includePrimaryKey
				? "primary key$autoincrement"
				: trim($autoincrement);
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

	protected function quote(bool|int|float|string|null $value):string {
		return match(true) {
			$value === null => "null",
			is_bool($value) => $value ? "true" : "false",
			is_string($value) => "'" . str_replace("'", "''", $value) . "'",
			default => (string)$value,
		};
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
