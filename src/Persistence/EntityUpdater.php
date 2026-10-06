<?php
namespace GT\Orm\Persistence;

use GT\Database\Database;
use GT\Orm\Entity;
use GT\Orm\Exception\InvalidEntityStateException;
use GT\Orm\Exception\InvalidEntityChangeException;
use GT\Orm\Metadata\ColumnName;
use GT\Orm\Metadata\EntityMetadata;
use GT\Orm\Metadata\EntityMetadataFactory;
use GT\Orm\Metadata\PropertyMetadata;
use GT\Orm\Query\QueryMatch;
use GT\SqlBuilder\Condition\Condition;
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
	 * @return T
	 */
	public function update(Entity $entity):Entity {
		$metadata = $this->metadataFactory->get($entity);
		$primaryKey = $metadata->requirePrimaryKey();
		$primaryKeyValue = $this->requiredPrimaryKeyValue($entity, $primaryKey);
		$valueList = [];
		foreach($metadata->getPropertyList() as $propertyMetadata) {
			if($propertyMetadata->isPrimaryKey()
				|| $propertyMetadata->isCollection()) {
				continue;
			}

			$valueList[$this->propertyColumnName($propertyMetadata)] = $this->valueMapper
				->propertyValue($entity, $propertyMetadata);
		}
		if($valueList === []) {
			return $entity;
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

		return $entity;
	}

	/**
	 * @param class-string<Entity> $className
	 * @param array<string, mixed> $changeList
	 * @param array<bool|int|string|Condition> $match
	 */
	public function updateMatching(
		string $className,
		array $changeList,
		array $match,
	):int {
		$metadata = $this->metadataFactory->get($className);
		$primaryKey = $metadata->requirePrimaryKey();
		$propertyMap = [];
		foreach($metadata->getPropertyList() as $propertyMetadata) {
			$propertyMap[$propertyMetadata->getName()] = $propertyMetadata;
		}

		$valueList = [];
		foreach($changeList as $propertyName => $value) {
			$propertyMetadata = $propertyMap[$propertyName] ?? null;
			if($propertyMetadata === null) {
				throw new InvalidEntityChangeException(
					"Entity $className does not have a persistent property named $propertyName",
				);
			}
			if($propertyMetadata->isPrimaryKey()) {
				throw new InvalidEntityChangeException(
					"Primary key $className::\$$propertyName cannot be updated",
				);
			}
			if($propertyMetadata->isCollection()) {
				throw new InvalidEntityChangeException(
					"Collection $className::\$$propertyName cannot be updated as a row",
				);
			}
			if(!$propertyMetadata->acceptsValue($value)) {
				throw new InvalidEntityChangeException(
					"Value for $className::\$$propertyName does not match its PHP type",
				);
			}

			$parameterName = "__orm_set_" . $this->propertyColumnName($propertyMetadata);
			$valueList[$parameterName] = $this->valueMapper->value(
				$propertyMetadata,
				$value,
			);
		}

		$queryMatch = new QueryMatch($primaryKey->getName(), ...$match);
		$builder = new UpdateBuilder();
		$builder->table($metadata->getTableName());
		$builder->__call("set", [$this->matchingPlaceholderList($valueList)]);
		$builder->__call("where", $queryMatch->getConditionList());
		$parameters = array_merge($valueList, $queryMatch->getParameters());

		return $this->transactionRunner->run(
			fn() => $this->database->executeSql(
				(string)$builder,
				$parameters,
			)->affectedRows(),
		);
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
		$builder->table($metadata->getTableName());
		$builder->__call("set", [$this->placeholderList($valueList)]);
		$builder->where("{$primaryKey->getName()} = :$primaryKeyParameter");
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
			throw new InvalidEntityStateException(
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

	/**
	 * @param array<string, mixed> $valueList
	 * @return array<string, string>
	 */
	private function matchingPlaceholderList(array $valueList):array {
		$placeholderList = [];
		foreach(array_keys($valueList) as $parameterName) {
			$columnName = substr($parameterName, strlen("__orm_set_"));
			$placeholderList[$columnName] = ":$parameterName";
		}

		return $placeholderList;
	}
}
