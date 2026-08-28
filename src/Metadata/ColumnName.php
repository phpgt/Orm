<?php
namespace GT\Orm\Metadata;

class ColumnName {
	public function foreignKey(
		string $propertyName,
		string $foreignTableName,
		string $foreignPrimaryKey,
	):string {
		return implode("_", [
			$propertyName,
			$foreignTableName,
			$foreignPrimaryKey,
		]);
	}

	public function junctionPlaceholder(string $propertyName):string {
		return implode("_", [
			$propertyName,
			"TODO",
			"JUNCTION",
			"TABLE",
		]);
	}
}
