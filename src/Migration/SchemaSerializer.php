<?php
namespace GT\Orm\Migration;

use GT\Orm\Migration\Exception\InvalidSchemaSnapshotException;
use JsonException;

class SchemaSerializer {
	private const int FORMAT_VERSION = 1;

	public function serialize(Schema $schema):string {
		$tableDataList = [];
		$tableList = $schema->getTableList();
		ksort($tableList);
		foreach($tableList as $table) {
			$tableDataList[] = $this->tableData($table);
		}

		return json_encode(
			[
				"formatVersion" => self::FORMAT_VERSION,
				"tables" => $tableDataList,
			],
			JSON_THROW_ON_ERROR
				| JSON_PRESERVE_ZERO_FRACTION
				| JSON_UNESCAPED_SLASHES
				| JSON_UNESCAPED_UNICODE,
		);
	}

	public function deserialize(string $snapshot):Schema {
		try {
			$data = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
		}
		catch(JsonException $exception) {
			throw new InvalidSchemaSnapshotException(
				"Invalid schema snapshot JSON",
				previous: $exception,
			);
		}
		if(!is_array($data)) {
			throw new InvalidSchemaSnapshotException("Schema snapshot must be an object");
		}

		$formatVersion = $data["formatVersion"] ?? null;
		if($formatVersion !== self::FORMAT_VERSION) {
			$version = is_scalar($formatVersion) ? (string)$formatVersion : "unknown";
			throw new InvalidSchemaSnapshotException(
				"Unsupported schema snapshot format $version",
			);
		}
		$tableDataList = $data["tables"] ?? null;
		if(!is_array($tableDataList)) {
			throw new InvalidSchemaSnapshotException("Schema snapshot tables must be an array");
		}

		$tableList = [];
		foreach($tableDataList as $tableData) {
			if(!is_array($tableData)) {
				throw new InvalidSchemaSnapshotException("Schema table must be an object");
			}
			$tableList[] = $this->tableFromData($tableData);
		}
		return new Schema(...$tableList);
	}

	public function hash(Schema $schema):string {
		return hash("sha256", $this->serialize($schema));
	}

	/** @return array<string, mixed> */
	private function tableData(SchemaTable $table):array {
		$fieldDataList = [];
		$fieldList = $table->getFieldList();
		usort(
			$fieldList,
			fn(SchemaField $a, SchemaField $b) => $a->getName() <=> $b->getName(),
		);
		foreach($fieldList as $field) {
			$fieldDataList[] = $this->fieldData($field);
		}

		return [
			"name" => $table->getName(),
			"primaryKey" => $table->getPrimaryKey()?->getName(),
			"fields" => $fieldDataList,
		];
	}

	/** @return array<string, mixed> */
	private function fieldData(SchemaField $field):array {
		$foreignKey = null;
		if($field->isForeignKey()) {
			$foreignKey = [
				"table" => $field->getForeignKeyReferenceTable(),
				"field" => $field->getForeignKeyReferenceField(),
			];
		}

		return [
			"name" => $field->getName(),
			"type" => $field->requireType(),
			"nullable" => $field->isNullable(),
			"hasDefaultValue" => $field->hasDefaultValue(),
			"defaultValue" => $field->getDefaultValue(),
			"autoIncrement" => $field->isAutoIncrement(),
			"unique" => $field->isUnique(),
			"foreignKey" => $foreignKey,
		];
	}

	/** @param array<mixed> $data */
	private function tableFromData(array $data):SchemaTable {
		$name = $this->stringValue($data, "name");
		$fieldDataList = $data["fields"] ?? null;
		if(!is_array($fieldDataList)) {
			throw new InvalidSchemaSnapshotException("Schema table $name fields must be an array");
		}

		$table = new SchemaTable($name);
		foreach($fieldDataList as $fieldData) {
			if(!is_array($fieldData)) {
				throw new InvalidSchemaSnapshotException("Schema field must be an object");
			}
			$table->addField($this->fieldFromData($fieldData));
		}

		$primaryKeyName = $data["primaryKey"] ?? null;
		if($primaryKeyName !== null) {
			if(!is_string($primaryKeyName)) {
				throw new InvalidSchemaSnapshotException("Schema primary key must be a string or null");
			}
			$primaryKey = $table->getField($primaryKeyName);
			if($primaryKey === null) {
				throw new InvalidSchemaSnapshotException(
					"Schema table $name primary key $primaryKeyName does not exist",
				);
			}
			$table->setPrimaryKey($primaryKey);
		}
		return $table;
	}

	/** @param array<mixed> $data */
	private function fieldFromData(array $data):SchemaField {
		$field = new SchemaField($this->stringValue($data, "name"));
		$field->setType($this->stringValue($data, "type"));
		$field->setNullable($this->boolValue($data, "nullable"));
		$field->setAutoIncrement($this->boolValue($data, "autoIncrement"));
		$field->setUnique($this->boolValue($data, "unique"));
		if($this->boolValue($data, "hasDefaultValue")) {
			$defaultValue = $data["defaultValue"] ?? null;
			if(!is_bool($defaultValue)
				&& !is_int($defaultValue)
				&& !is_float($defaultValue)
				&& !is_string($defaultValue)
				&& $defaultValue !== null) {
				throw new InvalidSchemaSnapshotException("Schema default value is invalid");
			}
			$field->setDefaultValue($defaultValue);
		}

		$foreignKey = $data["foreignKey"] ?? null;
		if($foreignKey !== null) {
			if(!is_array($foreignKey)) {
				throw new InvalidSchemaSnapshotException("Schema foreign key must be an object or null");
			}
			$field->setForeignKeyReference(
				$this->stringValue($foreignKey, "table"),
				$this->stringValue($foreignKey, "field"),
			);
		}
		return $field;
	}

	/** @param array<mixed> $data */
	private function stringValue(array $data, string $key):string {
		$value = $data[$key] ?? null;
		if(!is_string($value)) {
			throw new InvalidSchemaSnapshotException("Schema $key must be a string");
		}
		return $value;
	}

	/** @param array<mixed> $data */
	private function boolValue(array $data, string $key):bool {
		$value = $data[$key] ?? null;
		if(!is_bool($value)) {
			throw new InvalidSchemaSnapshotException("Schema $key must be a boolean");
		}
		return $value;
	}
}
