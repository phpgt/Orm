<?php
namespace Gt\Orm\Migration;

class SchemaField {
	private string $type;
	private bool $nullable;
	private mixed $defaultValue;
	private bool $autoIncrement;
	private string $foreignKeyReferenceTable;
	private string $foreignKeyReferenceField;
	private bool $unique;

	public function __construct(
		private readonly string $name,
	) {}

	public function getName():string {
		return $this->name;
	}

	public function setType(mixed $typeName):void {
		$this->type = $typeName;
	}

	public function getType():?string {
		return $this->type ?? null;
	}

	public function setNullable(bool $allowsNull):void {
		$this->nullable = $allowsNull;
	}

	public function isNullable():bool {
		return $this->nullable ?? false;
	}

	public function hasDefaultValue():bool {
		return isset($this->defaultValue);
	}

	public function setDefaultValue(mixed $defaultValue):void {
		$this->defaultValue = $defaultValue;
	}

	public function getDefaultValue():mixed {
		return $this->defaultValue ?? null;
	}

	public function setAutoIncrement(bool $autoIncrement):void {
		$this->autoIncrement = $autoIncrement;
	}

	public function isAutoIncrement():bool {
		return $this->autoIncrement ?? false;
	}

	public function setForeignKeyReference(string $table, string $field):void {
		$this->foreignKeyReferenceTable = $table;
		$this->foreignKeyReferenceField = $field;
	}

	public function isForeignKey():bool {
		return isset($this->foreignKeyReferenceTable);
	}

	public function getForeignKeyReferenceTable():string {
		return $this->foreignKeyReferenceTable;
	}

	public function getForeignKeyReferenceField():string {
		return $this->foreignKeyReferenceField;
	}

	public function setUnique(bool $unique):void {
		$this->unique = $unique;
	}

	public function isUnique():bool {
		return $this->unique ?? false;
	}
}
