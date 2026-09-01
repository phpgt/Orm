<?php
namespace GT\Orm\Migration;

use GT\Orm\Metadata\ColumnName;
use GT\Orm\Metadata\EntityMetadata;
use GT\Orm\Metadata\EntityMetadataFactory;
use GT\Orm\Metadata\PropertyKind;
use GT\Orm\Metadata\PropertyMetadata;

class SchemaGenerator {
	private EntityMetadataFactory $metadataFactory;
	private ColumnName $columnName;

	public function __construct(?EntityMetadataFactory $metadataFactory = null) {
		$this->metadataFactory = $metadataFactory ?? new EntityMetadataFactory();
		$this->columnName = new ColumnName();
	}

	public function generate(object|string $class):SchemaTable {
		$metadata = $this->metadataFactory->get($class);
		$table = new SchemaTable($metadata->getTableName());

		foreach($metadata->getPropertyList() as $propertyMetadata) {
			if($propertyMetadata->getKind() === PropertyKind::COLLECTION) {
				continue;
			}

			$field = $this->createField($propertyMetadata);
			$table->addField($field);
			if($propertyMetadata->isPrimaryKey()) {
				$table->setPrimaryKey($field);
			}
		}

		return $table;
	}

	/**
	 * @param object|class-string ...$entityList
	 * @return array<SchemaTable>
	 */
	public function generateAll(object|string ...$entityList):array {
		$tableList = [];
		foreach($entityList as $entity) {
			$table = $this->generate($entity);
			$tableList[$table->getName()] = $table;
		}

		foreach($entityList as $entity) {
			foreach($this->generateJunctionTableList($entity) as $junctionTable) {
				$tableList[$junctionTable->getName()] = $junctionTable;
			}
		}

		return array_values($tableList);
	}

	/** @param object|class-string ...$entityList */
	public function generateSchema(object|string ...$entityList):Schema {
		return new Schema(...$this->generateAll(...$entityList));
	}

	/**
	 * @param object|class-string $entity
	 * @return array<SchemaTable>
	 */
	public function generateJunctionTableList(object|string $entity):array {
		$metadata = $this->metadataFactory->get($entity);
		$junctionTableList = [];
		foreach($metadata->getPropertyList() as $propertyMetadata) {
			if($propertyMetadata->getKind() !== PropertyKind::COLLECTION) {
				continue;
			}

			$junctionTableList[] = $this->createJunctionTable(
				$metadata,
				$propertyMetadata,
			);
		}

		return $junctionTableList;
	}

	private function createField(PropertyMetadata $metadata):SchemaField {
		if($metadata->getKind() === PropertyKind::ENTITY) {
			$field = $this->createForeignKeyField($metadata);
		}
		else {
			$field = new SchemaField($metadata->getName());
			$field->setType($metadata->getStorageType());
		}

		$field->setNullable($metadata->isNullable());
		$field->setAutoIncrement($metadata->isAutoIncrement());
		if($metadata->hasDefaultValue()) {
			$field->setDefaultValue($metadata->getDefaultValue());
		}

		return $field;
	}

	private function createForeignKeyField(
		PropertyMetadata $metadata,
	):SchemaField {
		$foreignMetadata = $this->metadataFactory->get($metadata->getTypeName());
		$foreignPrimaryKey = $foreignMetadata->requirePrimaryKey();
		$fieldName = $this->columnName->foreignKey(
			$metadata->getName(),
			$foreignMetadata->getTableName(),
			$foreignPrimaryKey->getName(),
		);
		$field = new SchemaField($fieldName);
		$field->setType($foreignPrimaryKey->getStorageType());
		$field->setForeignKeyReference(
			$foreignMetadata->getTableName(),
			$foreignPrimaryKey->getName(),
		);

		return $field;
	}

	private function createJunctionTable(
		EntityMetadata $ownerMetadata,
		PropertyMetadata $collectionMetadata,
	):SchemaTable {
		$ownerPrimaryKey = $ownerMetadata->requirePrimaryKey();
		$itemMetadata = $this->metadataFactory->get(
			$collectionMetadata->getCollectionItemClassName(),
		);
		$itemPrimaryKey = $itemMetadata->requirePrimaryKey();
		$tableName = $this->columnName->junctionTable(
			$ownerMetadata->getTableName(),
			$collectionMetadata->getName(),
			$itemMetadata->getTableName(),
		);
		$table = new SchemaTable($tableName);

		$idField = new SchemaField("id");
		$idField->setType("int");
		$idField->setNullable(false);
		$idField->setAutoIncrement(true);
		$table->addField($idField);
		$table->setPrimaryKey($idField);

		$table->addField($this->createJunctionForeignKeyField(
			$this->columnName->junctionForeignKey(
				$ownerMetadata->getTableName(),
				$ownerPrimaryKey->getName(),
			),
			$ownerMetadata->getTableName(),
			$ownerPrimaryKey,
		));
		$table->addField($this->createJunctionForeignKeyField(
			$this->columnName->junctionItemForeignKey(
				$ownerMetadata->getTableName(),
				$ownerPrimaryKey->getName(),
				$collectionMetadata->getName(),
				$itemMetadata->getTableName(),
				$itemPrimaryKey->getName(),
			),
			$itemMetadata->getTableName(),
			$itemPrimaryKey,
		));

		return $table;
	}

	private function createJunctionForeignKeyField(
		string $fieldName,
		string $tableName,
		PropertyMetadata $primaryKey,
	):SchemaField {
		$field = new SchemaField($fieldName);
		$field->setType($primaryKey->getStorageType());
		$field->setNullable(false);
		$field->setForeignKeyReference($tableName, $primaryKey->getName());

		return $field;
	}
}
