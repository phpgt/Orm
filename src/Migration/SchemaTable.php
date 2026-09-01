<?php
namespace GT\Orm\Migration;

use LogicException;

class SchemaTable {
	private SchemaField $primaryKey;
	/** @var array<SchemaField> */
	private array $fieldList;

	public function __construct(
		private readonly string $name,
	) {
		$this->fieldList = [];
	}

	public function getName():string {
		return $this->name;
	}

	public function setPrimaryKey(SchemaField $field):void {
		$this->primaryKey = $field;
	}
	public function getPrimaryKey():?SchemaField {
		return $this->primaryKey ?? null;
	}

	public function addField(SchemaField $field):void {
		if($this->getField($field->getName()) !== null) {
			throw new LogicException(
				"Schema table {$this->name} already contains field {$field->getName()}",
			);
		}
		array_push($this->fieldList, $field);
	}

	/** @return array<SchemaField> */
	public function getFieldList():array {
		return $this->fieldList;
	}

	public function getField(string $name):?SchemaField {
		foreach($this->fieldList as $field) {
			if($field->getName() === $name) {
				return $field;
			}
		}

		return null;
	}

	public function copy(?string $name = null):self {
		$table = new self($name ?? $this->name);
		foreach($this->fieldList as $field) {
			$fieldCopy = $field->copy();
			$table->addField($fieldCopy);
			if($this->getPrimaryKey() === $field) {
				$table->setPrimaryKey($fieldCopy);
			}
		}
		return $table;
	}
}
