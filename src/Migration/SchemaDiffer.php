<?php
namespace GT\Orm\Migration;

class SchemaDiffer {
	public function diff(Schema $before, Schema $after):SchemaDiff {
		$changeList = [];
		$tableNameList = array_unique(array_merge(
			array_keys($before->getTableList()),
			array_keys($after->getTableList()),
		));
		sort($tableNameList);

		foreach($tableNameList as $tableName) {
			$beforeTable = $before->getTable($tableName);
			$afterTable = $after->getTable($tableName);
			if($beforeTable === null) {
				if($afterTable === null) {
					continue;
				}
				$changeList[] = new SchemaChange(
					SchemaChangeType::CREATE_TABLE,
					$tableName,
					afterTable: $afterTable,
				);
				continue;
			}
			if($afterTable === null) {
				$changeList[] = new SchemaChange(
					SchemaChangeType::DROP_TABLE,
					$tableName,
					beforeTable: $beforeTable,
				);
				continue;
			}
			array_push(
				$changeList,
				...$this->fieldChangeList($beforeTable, $afterTable),
			);
		}
		return new SchemaDiff($changeList);
	}

	/** @return array<SchemaChange> */
	private function fieldChangeList(
		SchemaTable $before,
		SchemaTable $after,
	):array {
		$changeList = [];
		$fieldNameList = array_unique(array_merge(
			array_map(fn(SchemaField $field) => $field->getName(), $before->getFieldList()),
			array_map(fn(SchemaField $field) => $field->getName(), $after->getFieldList()),
		));
		sort($fieldNameList);
		foreach($fieldNameList as $fieldName) {
			$beforeField = $before->getField($fieldName);
			$afterField = $after->getField($fieldName);
			$type = match(true) {
				$beforeField === null => SchemaChangeType::ADD_FIELD,
				$afterField === null => SchemaChangeType::DROP_FIELD,
				!$beforeField->equals($afterField)
					|| $this->primaryKeyChanged($before, $after, $fieldName)
					=> SchemaChangeType::ALTER_FIELD,
				default => null,
			};
			if($type !== null) {
				$changeList[] = new SchemaChange(
					$type,
					$before->getName(),
					$before,
					$after,
					$beforeField,
					$afterField,
				);
			}
		}
		return $changeList;
	}

	private function primaryKeyChanged(
		SchemaTable $before,
		SchemaTable $after,
		string $fieldName,
	):bool {
		$beforeIsPrimary = $before->getPrimaryKey()?->getName() === $fieldName;
		$afterIsPrimary = $after->getPrimaryKey()?->getName() === $fieldName;
		return $beforeIsPrimary !== $afterIsPrimary;
	}
}
