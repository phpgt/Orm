<?php
namespace Gt\Orm\Migration;

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
		array_push($this->fieldList, $field);
	}

	/** @return array<SchemaField> */
	public function getFieldList():array {
		return $this->fieldList;
	}
}
