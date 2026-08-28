<?php
namespace GT\Orm\Migration;

use GT\Orm\Metadata\ColumnName;
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
}
