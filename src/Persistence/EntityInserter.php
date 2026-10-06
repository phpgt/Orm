<?php
namespace GT\Orm\Persistence;

use GT\Database\Database;
use GT\Orm\Entity;
use GT\Orm\Exception\InvalidEntityStateException;
use GT\Orm\Metadata\ColumnName;
use GT\Orm\Metadata\EntityMetadata;
use GT\Orm\Metadata\EntityMetadataFactory;
use GT\Orm\Metadata\PropertyKind;
use GT\Orm\Metadata\PropertyMetadata;
use GT\SqlBuilder\InsertBuilder;
use PDO;

class EntityInserter {
	public function __construct(
		private readonly Database $database,
		private readonly EntityMetadataFactory $metadataFactory,
		private readonly ColumnName $columnName,
		private readonly EntityValueMapper $valueMapper,
		private readonly TransactionRunner $transactionRunner,
	) {}

	/**
	 * @template T of Entity
	 * @param T $entity
	 * @return T
	 */
	public function insert(Entity $entity):Entity {
		$metadata = $this->metadataFactory->get($entity);
		$primaryKey = $metadata->requirePrimaryKey();
		if($primaryKey->isAutoIncrement()
			&& $primaryKey->getProperty()->isInitialized($entity)) {
			$className = $entity::class;
			throw new InvalidEntityStateException(
				"Cannot insert $className because its generated primary key is already initialised",
			);
		}

		$primaryKeyValue = $this->transactionRunner->run(
			fn() => $this->insertEntityAndJunctions($entity, $metadata),
		);
		if($primaryKey->isAutoIncrement()) {
			$primaryKey->getProperty()->setValue($entity, $primaryKeyValue);
		}

		return $entity;
	}

	private function insertEntityAndJunctions(
		Entity $entity,
		EntityMetadata $metadata,
	):int|string {
		$valueList = $this->insertValueList($entity, $metadata);
		$result = $this->database->executeSql(
			$this->insertSql($metadata->getTableName(), $valueList),
			$valueList,
		);
		$primaryKey = $metadata->requirePrimaryKey();
		$primaryKeyValue = $primaryKey->isAutoIncrement()
			? $primaryKey->fromDatabase($result->lastInsertId())
			: $this->valueMapper->propertyValue($entity, $primaryKey);
		if(!is_int($primaryKeyValue) && !is_string($primaryKeyValue)) {
			$className = $entity::class;
			throw new InvalidEntityStateException(
				"Inserted entity $className does not have a valid primary key",
			);
		}

		$this->insertJunctionRows($entity, $metadata, $primaryKeyValue);
		return $primaryKeyValue;
	}

	/** @return array<string, bool|int|float|string|null> */
	private function insertValueList(
		Entity $entity,
		EntityMetadata $metadata,
	):array {
		$valueList = [];
		foreach($metadata->getPropertyList() as $propertyMetadata) {
			if($propertyMetadata->getKind() === PropertyKind::COLLECTION
				|| $propertyMetadata->isAutoIncrement()) {
				continue;
			}

			$property = $propertyMetadata->getProperty();
			if(!$property->isInitialized($entity)
				&& $propertyMetadata->hasDefaultValue()) {
				continue;
			}
			$valueList[$this->propertyColumnName($propertyMetadata)] = $this->valueMapper
				->propertyValue($entity, $propertyMetadata);
		}

		return $valueList;
	}

	private function insertJunctionRows(
		Entity $entity,
		EntityMetadata $ownerMetadata,
		int|string $ownerPrimaryKeyValue,
	):void {
		foreach($ownerMetadata->getPropertyList() as $collectionMetadata) {
			if($collectionMetadata->getKind() !== PropertyKind::COLLECTION
				|| !$collectionMetadata->getProperty()->isInitialized($entity)) {
				continue;
			}

			$this->insertCollectionRows(
				$entity,
				$ownerMetadata,
				$collectionMetadata,
				$ownerPrimaryKeyValue,
			);
		}
	}

	private function insertCollectionRows(
		Entity $entity,
		EntityMetadata $ownerMetadata,
		PropertyMetadata $collectionMetadata,
		int|string $ownerPrimaryKeyValue,
	):void {
		$collection = $collectionMetadata->getProperty()->getValue($entity);
		$itemClassName = $collectionMetadata->getCollectionItemClassName();
		$itemMetadata = $this->metadataFactory->get($itemClassName);
		$itemPrimaryKey = $itemMetadata->requirePrimaryKey();
		$ownerPrimaryKey = $ownerMetadata->requirePrimaryKey();
		[$junctionTable, $ownerColumn, $itemColumn] = $this->columnName->junction(
			$ownerMetadata->getTableName(),
			$ownerPrimaryKey->getName(),
			$collectionMetadata->getName(),
			$itemMetadata->getTableName(),
			$itemPrimaryKey->getName(),
		);

		foreach($collection as $item) {
			if(!$item instanceof Entity || !$item instanceof $itemClassName) {
				$className = $entity::class;
				throw new InvalidEntityStateException(
					"Collection $className::\${$collectionMetadata->getName()} can only contain $itemClassName",
				);
			}

			$valueList = [
				$ownerColumn => $this->valueMapper->value(
					$ownerPrimaryKey,
					$ownerPrimaryKeyValue,
				),
				$itemColumn => $this->valueMapper->propertyValue(
					$item,
					$itemPrimaryKey,
				),
			];
			$this->database->executeSql(
				$this->insertSql($junctionTable, $valueList),
				$valueList,
			);
		}
	}

	private function propertyColumnName(PropertyMetadata $metadata):string {
		if($metadata->getKind() !== PropertyKind::ENTITY) {
			return $metadata->getName();
		}

		$foreignMetadata = $this->metadataFactory->get($metadata->getTypeName());
		return $this->columnName->foreignKey(
			$metadata->getName(),
			$foreignMetadata->getTableName(),
			$foreignMetadata->requirePrimaryKey()->getName(),
		);
	}

	/** @param array<string, bool|int|float|string|null> $valueList */
	private function insertSql(string $tableName, array $valueList):string {
		if($valueList === []) {
			$driverName = $this->database->getDriver()->getConnection()
				->getAttribute(PDO::ATTR_DRIVER_NAME);
			return $driverName === "mysql"
				? "insert into $tableName () values ()"
				: "insert into $tableName default values";
		}

		$builder = new InsertBuilder();
		$builder->into($tableName)
			->set(...$this->placeholderList($valueList));
		return (string)$builder;
	}

	/**
	 * @param array<string, mixed> $valueList
	 * @return array<string>
	 */
	private function placeholderList(array $valueList):array {
		return array_map(
			fn(string $columnName) => ":$columnName",
			array_keys($valueList),
		);
	}
}
