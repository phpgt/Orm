<?php
namespace GT\Orm\Metadata;

class ColumnName {
	/**
	 * @return array{string, string, string} Junction table, owner column, item column
	 */
	public function junction(
		string $ownerTable,
		string $ownerPrimaryKey,
		string $propertyName,
		string $itemTable,
		string $itemPrimaryKey,
	):array {
		return [
			$this->junctionTable($ownerTable, $propertyName, $itemTable),
			$this->junctionForeignKey($ownerTable, $ownerPrimaryKey),
			$this->junctionItemForeignKey(
				$ownerTable,
				$ownerPrimaryKey,
				$propertyName,
				$itemTable,
				$itemPrimaryKey,
			),
		];
	}

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

	public function junctionTable(
		string $ownerTable,
		string $propertyName,
		string $itemTable,
	):string {
		return implode("_", [$ownerTable, $propertyName, $itemTable]);
	}

	public function junctionForeignKey(
		string $tableName,
		string $primaryKey,
	):string {
		return implode("_", [$tableName, $primaryKey]);
	}

	public function junctionItemForeignKey(
		string $ownerTable,
		string $ownerPrimaryKey,
		string $propertyName,
		string $itemTable,
		string $itemPrimaryKey,
	):string {
		$ownerColumn = $this->junctionForeignKey(
			$ownerTable,
			$ownerPrimaryKey,
		);
		$itemColumn = $this->junctionForeignKey($itemTable, $itemPrimaryKey);
		if($itemColumn !== $ownerColumn) {
			return $itemColumn;
		}

		return $this->foreignKey($propertyName, $itemTable, $itemPrimaryKey);
	}
}
