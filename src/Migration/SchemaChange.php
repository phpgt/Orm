<?php
namespace GT\Orm\Migration;

use LogicException;

readonly class SchemaChange {
	public function __construct(
		public SchemaChangeType $type,
		public string $tableName,
		public ?SchemaTable $beforeTable = null,
		public ?SchemaTable $afterTable = null,
		public ?SchemaField $beforeField = null,
		public ?SchemaField $afterField = null,
	) {}

	public function fieldName():string {
		$field = $this->afterField ?? $this->beforeField;
		if($field === null) {
			throw new LogicException("Schema change does not describe a field");
		}
		return $field->getName();
	}

	public function safety():SchemaChangeSafety {
		return match($this->type) {
			SchemaChangeType::CREATE_TABLE => SchemaChangeSafety::SAFE,
			SchemaChangeType::DROP_TABLE,
			SchemaChangeType::DROP_FIELD => SchemaChangeSafety::DESTRUCTIVE,
			SchemaChangeType::ADD_FIELD => $this->addedFieldSafety(),
			SchemaChangeType::ALTER_FIELD => $this->alteredFieldSafety(),
		};
	}

	private function addedFieldSafety():SchemaChangeSafety {
		$field = $this->afterField;
		if($field === null) {
			throw new LogicException("Added field change has no resulting field");
		}
		return $field->isNullable() || $field->hasDefaultValue()
			? SchemaChangeSafety::SAFE
			: SchemaChangeSafety::REQUIRES_DATA;
	}

	private function alteredFieldSafety():SchemaChangeSafety {
		$before = $this->beforeField;
		$after = $this->afterField;
		if($before === null || $after === null) {
			throw new LogicException("Altered field change is incomplete");
		}
		if($this->primaryKeyStatusChanged()) {
			return SchemaChangeSafety::DESTRUCTIVE;
		}
		if($before->equalsExceptAutoIncrement($after)) {
			return SchemaChangeSafety::SAFE;
		}
		if($before->isNullable() && !$after->isNullable()) {
			return SchemaChangeSafety::REQUIRES_DATA;
		}
		return SchemaChangeSafety::DESTRUCTIVE;
	}

	private function primaryKeyStatusChanged():bool {
		$beforeTable = $this->beforeTable;
		$afterTable = $this->afterTable;
		if($beforeTable === null || $afterTable === null) {
			return false;
		}
		$fieldName = $this->fieldName();
		return ($beforeTable->getPrimaryKey()?->getName() === $fieldName)
			!== ($afterTable->getPrimaryKey()?->getName() === $fieldName);
	}
}
