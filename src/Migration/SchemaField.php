<?php
namespace GT\Orm\Migration;

use LogicException;

class SchemaField {
	private string $type;
	private bool $nullable;
	private bool $hasDefaultValue = false;
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

	public function setType(string $typeName):void {
		$this->type = $typeName;
	}

	public function getType():?string {
		return $this->type ?? null;
	}

	public function requireType():string {
		if(!isset($this->type)) {
			throw new LogicException(
				"Schema field {$this->name} does not have a type",
			);
		}

		return $this->type;
	}

	public function setNullable(bool $allowsNull):void {
		$this->nullable = $allowsNull;
	}

	public function isNullable():bool {
		return $this->nullable ?? false;
	}

	public function hasDefaultValue():bool {
		return $this->hasDefaultValue;
	}

	public function setDefaultValue(mixed $defaultValue):void {
		$this->hasDefaultValue = true;
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

	public function equals(self $other):bool {
		return $this->equalsExceptAutoIncrement($other)
			&& $this->isAutoIncrement() === $other->isAutoIncrement();
	}

	public function equalsExceptAutoIncrement(self $other):bool {
		return $this->getName() === $other->getName()
			&& $this->getType() === $other->getType()
			&& $this->isNullable() === $other->isNullable()
			&& $this->hasDefaultValue() === $other->hasDefaultValue()
			&& $this->getDefaultValue() === $other->getDefaultValue()
			&& $this->isUnique() === $other->isUnique()
			&& $this->foreignKeyEquals($other);
	}

	private function foreignKeyEquals(self $other):bool {
		if($this->isForeignKey() !== $other->isForeignKey()) {
			return false;
		}
		if(!$this->isForeignKey()) {
			return true;
		}

		return $this->getForeignKeyReferenceTable()
				=== $other->getForeignKeyReferenceTable()
			&& $this->getForeignKeyReferenceField()
				=== $other->getForeignKeyReferenceField();
	}

	public function copy():self {
		$field = new self($this->getName());
		$field->setType($this->requireType());
		$field->setNullable($this->isNullable());
		$field->setAutoIncrement($this->isAutoIncrement());
		$field->setUnique($this->isUnique());
		if($this->hasDefaultValue()) {
			$field->setDefaultValue($this->getDefaultValue());
		}
		if($this->isForeignKey()) {
			$field->setForeignKeyReference(
				$this->getForeignKeyReferenceTable(),
				$this->getForeignKeyReferenceField(),
			);
		}
		return $field;
	}
}
