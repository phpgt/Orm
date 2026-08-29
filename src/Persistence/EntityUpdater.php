<?php
namespace GT\Orm\Persistence;

use GT\Database\Database;
use GT\Orm\Entity;
use GT\Orm\Exception\InvalidEntityChangeException;
use GT\Orm\Metadata\ColumnName;
use GT\Orm\Metadata\EntityMetadata;
use GT\Orm\Metadata\EntityMetadataFactory;
use GT\Orm\Metadata\PropertyMetadata;
use GT\SqlBuilder\UpdateBuilder;

class EntityUpdater {
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
	 * @param array<string, mixed> $changeList
	 * @return T
	 */
	public function update(Entity $entity, array $changeList):Entity {
		if($changeList === []) {
			return $entity;
		}

		$metadata = $this->metadataFactory->get($entity);
		$primaryKey = $metadata->requirePrimaryKey();
		$primaryKeyValue = $this->requiredPrimaryKeyValue($entity, $primaryKey);
		$propertyList = $this->changedPropertyList($metadata, $changeList);
		$replacement = $this->createReplacement(
			$entity,
			$metadata,
			$propertyList,
			$changeList,
		);
		$valueList = [];
		foreach($propertyList as $propertyMetadata) {
			$valueList[$this->propertyColumnName($propertyMetadata)] = $this->valueMapper
				->propertyValue($replacement, $propertyMetadata);
		}

		$this->transactionRunner->run(function() use (
			$metadata,
			$primaryKey,
			$primaryKeyValue,
			$valueList,
		):void {
			$this->executeUpdate(
				$metadata,
				$primaryKey,
				$primaryKeyValue,
				$valueList,
			);
		});

		return $replacement;
	}

	/**
	 * @param array<string, mixed> $changeList
	 * @return array<string, PropertyMetadata>
	 */
	private function changedPropertyList(
		EntityMetadata $metadata,
		array $changeList,
	):array {
		$propertyList = [];
		foreach($metadata->getPropertyList() as $propertyMetadata) {
			$propertyList[$propertyMetadata->getName()] = $propertyMetadata;
		}

		$changedPropertyList = [];
		foreach(array_keys($changeList) as $propertyName) {
			if(!isset($propertyList[$propertyName])) {
				throw new InvalidEntityChangeException(
					"Entity {$metadata->getClassName()} does not have a persistent property named $propertyName",
				);
			}

			$propertyMetadata = $propertyList[$propertyName];
			if($propertyMetadata->isPrimaryKey()) {
				throw new InvalidEntityChangeException(
					"Primary key {$metadata->getClassName()}::\$$propertyName cannot be updated",
				);
			}
			if($propertyMetadata->isCollection()) {
				throw new InvalidEntityChangeException(
					"Collection {$metadata->getClassName()}::\$$propertyName cannot be updated as a row",
				);
			}

			$changedPropertyList[$propertyName] = $propertyMetadata;
		}

		return $changedPropertyList;
	}

	/**
	 * @template T of Entity
	 * @param T $entity
	 * @param array<string, PropertyMetadata> $changedPropertyList
	 * @param array<string, mixed> $changeList
	 * @return T
	 */
	private function createReplacement(
		Entity $entity,
		EntityMetadata $metadata,
		array $changedPropertyList,
		array $changeList,
	):Entity {
		$replacement = $metadata->copyWithoutProperties(
			$entity,
			array_keys($changedPropertyList),
		);

		foreach($changedPropertyList as $propertyName => $propertyMetadata) {
			$value = $changeList[$propertyName];
			if(!$propertyMetadata->acceptsValue($value)) {
				$className = $entity::class;
				throw new InvalidEntityChangeException(
					"Value for $className::\$$propertyName does not match its PHP type",
				);
			}

			$propertyMetadata->getProperty()->setValue($replacement, $value);
		}

		return $replacement;
	}

	/** @param array<string, bool|int|float|string|null> $valueList */
	private function executeUpdate(
		EntityMetadata $metadata,
		PropertyMetadata $primaryKey,
		int|string $primaryKeyValue,
		array $valueList,
	):void {
		$primaryKeyParameter = "__orm_primary_key";
		$builder = new UpdateBuilder();
		$builder->table($metadata->getTableName())
			->set(...$this->placeholderList($valueList))
			->where("{$primaryKey->getName()} = :$primaryKeyParameter");
		$valueList[$primaryKeyParameter] = $this->valueMapper->value(
			$primaryKey,
			$primaryKeyValue,
		);
		$this->database->executeSql((string)$builder, $valueList);
	}

	private function requiredPrimaryKeyValue(
		Entity $entity,
		PropertyMetadata $primaryKey,
	):int|string {
		$value = $this->valueMapper->propertyValue($entity, $primaryKey);
		if(!is_int($value) && !is_string($value)) {
			$className = $entity::class;
			throw new InvalidEntityChangeException(
				"Entity $className does not have a valid primary key",
			);
		}

		return $value;
	}

	private function propertyColumnName(PropertyMetadata $metadata):string {
		if(!$metadata->isEntity()) {
			return $metadata->getName();
		}

		$foreignMetadata = $this->metadataFactory->get($metadata->getTypeName());
		return $this->columnName->foreignKey(
			$metadata->getName(),
			$foreignMetadata->getTableName(),
			$foreignMetadata->requirePrimaryKey()->getName(),
		);
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
