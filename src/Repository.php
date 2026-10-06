<?php
namespace GT\Orm;

use Generator;
use GT\Database\Database;
use GT\Database\Result\Row;
use GT\Orm\Metadata\ColumnName;
use GT\Orm\Metadata\EntityMetadataFactory;
use GT\Orm\Metadata\PropertyMetadata;
use GT\Orm\Persistence\EntityWriter;
use GT\Orm\Persistence\EntityDeleter;
use GT\Orm\Query\QueryFactory;
use GT\SqlBuilder\Condition\Condition;
use InvalidArgumentException;

class Repository {
	/** @var array<class-string<Entity>, array<int|string, Entity>> */
	private array $entityCache = [];
	private EntityMetadataFactory $metadataFactory;
	private ColumnName $columnName;
	private EntityWriter $entityWriter;
	private EntityDeleter $entityDeleter;
	private QueryFactory $queryFactory;

	public function __construct(
		protected Database $database,
		?EntityMetadataFactory $metadataFactory = null,
	) {
		$this->metadataFactory = $metadataFactory ?? new EntityMetadataFactory();
		$this->columnName = new ColumnName();
		$this->entityWriter = new EntityWriter(
			$this->database,
			$this->metadataFactory,
			$this->columnName,
		);
		$this->queryFactory = new QueryFactory();
		$this->entityDeleter = new EntityDeleter(
			$this->database,
			$this->metadataFactory,
			$this->queryFactory,
		);
	}

	/**
	 * @template TInsertedEntity as Entity
	 * @param TInsertedEntity $entity
	 * @return TInsertedEntity
	 */
	public function insert(Entity $entity):Entity {
		$entity = $this->entityWriter->insert($entity);
		$this->cacheEntity($entity);
		return $entity;
	}

	/**
	 * @template TUpdatedEntity as Entity
	 * @param TUpdatedEntity|class-string<TUpdatedEntity> $target
	 * @param bool|int|string|array<string, mixed>|Condition ...$arguments
	 * @return ($target is Entity ? TUpdatedEntity : int)
	 */
	public function update(
		Entity|string $target,
		bool|int|string|array|Condition... $arguments,
	):Entity|int {
		if($target instanceof Entity) {
			if($arguments !== []) {
				throw new InvalidArgumentException(
					"Entity updates do not accept additional arguments",
				);
			}

			$entity = $this->entityWriter->update($target);
			$this->cacheEntity($entity);
			return $entity;
		}

		$className = $this->metadataFactory->requireEntityClassName($target);
		$updated = $this->entityWriter->updateMatching(
			$className,
			$arguments,
		);
		unset($this->entityCache[$className]);

		return $updated;
	}

	/**
	 * Delete an entity by its primary key.
	 *
	 * @param Entity|class-string<Entity> $entity
	 * @param bool|int|string|array<string, bool|int|string|null>|Condition ...$match
	 * @return int The number of deleted rows
	 */
	public function delete(
		Entity|string $entity,
		bool|int|string|array|Condition... $match,
	):int {
		$deleted = $this->entityDeleter->delete($entity, ...$match);
		if($entity instanceof Entity) {
			$metadata = $this->metadataFactory->get($entity);
			$value = $metadata->requirePrimaryKey()->getProperty()->getValue($entity);
			unset($this->entityCache[$entity::class][$value]);
		}
		else {
			unset($this->entityCache[$entity]);
		}

		return $deleted;
	}

	/**
	 * Explicitly delete every entity matching the supplied criteria.
	 *
	 * @param class-string<Entity> $className
	 * @param bool|int|string|array<string, bool|int|string|null>|Condition ...$match
	 */
	public function deleteAll(
		string $className,
		bool|int|string|array|Condition... $match,
	):int {
		$deleted = $this->entityDeleter->deleteAll($className, ...$match);
		unset($this->entityCache[$className]);
		return $deleted;
	}

	/**
	 * Fetch a single entity matching the given criteria.
	 *
	 * @template TFetchedEntity as Entity
	 * @param class-string<TFetchedEntity> $className The entity class name
	 * @param int|string $match $match can take variable arguments:
	 * single int|string
	 *     treated as the primary key (equivalent to getById)
	 * string, int|string
	 *     first argument is the field name,
	 *     second argument is the value/comparison to match
	 * Condition[]
	 *     used to build the where/join clauses, and/or handled
	 *     by the Condition implementation
	 *
	 * @return null|TFetchedEntity
	 */
	public function fetch(
		string $className,
		int|string|Condition... $match,
	):Entity|null {
		$query = $this->queryFactory->select();
		$query->from($this->getTableName($className))
			->select(...$this->getColumnList($className));
		$query->match($this->getPrimaryKey($className), ...$match);
		$cacheKey = $query->getCacheKey();
		if($cacheKey !== null) {
			$cachedEntity = $this->entityCache[$className][$cacheKey] ?? null;
			if($cachedEntity instanceof $className) {
				return $cachedEntity;
			}
		}

		$resultSet = $this->database->executeSql(
			(string)$query,
			$query->getParameters(),
		);
		$row = $resultSet->fetch();
		if($row === null) {
			return null;
		}

		$entity = $this->rowToEntity($row, $className);
		if($cacheKey !== null) {
			$this->entityCache[$className][$cacheKey] = $entity;
		}

		return $entity;
	}

	/**
	 * Fetch all entities matching the given criteria.
	 *
	 * @template TFetchAllEntity as Entity
	 * @param class-string<TFetchAllEntity> $className
	 * @param int|string|Condition ...$match
	 * @return Generator<TFetchAllEntity>
	 */
	public function fetchAll(
		string $className,
		int|string|Condition... $match,
	):iterable {
		$query = $this->queryFactory->select();
		$query->from($this->getTableName($className))
			->select(...$this->getColumnList($className));
		$query->match($this->getPrimaryKey($className), ...$match);
		$resultSet = $this->database->executeSql(
			(string)$query,
			$query->getParameters(),
		);

		while($row = $resultSet->fetch()) {
			$entity = $this->rowToEntity($row, $className);
			$this->cacheEntity($entity);
			yield $entity;
		}
	}

	public function getTableName(string $entityClassName):string {
		return $this->metadataFactory->get($entityClassName)->getTableName();
	}

	/** @param object|string $entity */
	private function getPrimaryKey(object|string $entity):string {
		return $this->metadataFactory->get($entity)
			->requirePrimaryKey()
			->getName();
	}

	/** @return array<string> */
	public function getColumnList(string $entityClassName):array {
		$columnList = [];
		$metadata = $this->metadataFactory->get($entityClassName);
		foreach($metadata->getPropertyList() as $propertyMetadata) {
			if($propertyMetadata->isCollection()) {
				continue;
			}
			array_push(
				$columnList,
				$this->getColumnName($propertyMetadata),
			);
		}

		return $columnList;
	}

	/**
	 * @template THydratedEntity as Entity
	 * @param class-string<THydratedEntity> $className
	 * @param null|THydratedEntity $instance An existing object reference to hydrate
	 * @return THydratedEntity
	 */
	protected function rowToEntity(
		Row $row,
		string $className,
		?Entity $instance = null,
	):Entity {
		$metadata = $this->metadataFactory->get($className);

		if($instance === null) {
			$instance = $this->metadataFactory
				->newInstanceWithoutConstructor($className);
		}

		$rowValues = [];
		foreach($metadata->getPropertyList() as $propertyMetadata) {
			$columnName = $this->getColumnName($propertyMetadata);
			if(!$this->rowContains($row, $columnName)) {
				continue;
			}
			$rowValues[$columnName] = $row->get($columnName);
		}

		foreach($metadata->getPropertyList() as $propertyMetadata) {
			$this->hydrateProperty($instance, $propertyMetadata, $rowValues);
		}

		return $instance;
	}

	/** @param array<string, mixed> $rowValues */
	private function hydrateProperty(
		object $instance,
		PropertyMetadata $metadata,
		array $rowValues,
	):void {
		if($metadata->isCollection()) {
			$this->handleLazyCollectionProperty($instance, $metadata);
			return;
		}

		$columnName = $this->getColumnName($metadata);
		if(!array_key_exists($columnName, $rowValues)) {
			return;
		}

		if(!$metadata->isEntity()) {
			$this->setInstanceProperty(
				$instance,
				$metadata,
				$rowValues[$columnName],
			);
			return;
		}

		$foreignPrimaryKeyValue = $rowValues[$columnName];
		if($foreignPrimaryKeyValue === null) {
			$this->setInstanceProperty($instance, $metadata, null);
			return;
		}

		$this->handleLazyInstanceProperty(
			$instance,
			$metadata,
			$foreignPrimaryKeyValue,
		);
	}

	private function setInstanceProperty(
		object $instance,
		PropertyMetadata $metadata,
		?string $value,
	):void {
		$metadata->getProperty()->setValue(
			$instance,
			$metadata->fromDatabase($value),
		);
	}

	private function handleLazyInstanceProperty(
		object $instance,
		PropertyMetadata $metadata,
		null|int|string $foreignPrimaryKeyValue,
	):void {
		if(is_null($foreignPrimaryKeyValue)) {
			return;
		}

		$metadata->getProperty()->setValue(
			$instance,
			$this->createLazyEntityReference(
				$metadata->getTypeName(),
				$foreignPrimaryKeyValue,
			),
		);
	}

	private function handleLazyCollectionProperty(
		object $instance,
		PropertyMetadata $metadata,
	):void {
		if($metadata->getProperty()->isInitialized($instance)) {
			return;
		}

		$itemClassName = $metadata->getCollectionItemClassName();

		$lazyGhost = $metadata->newTypeLazyGhost(
			function(object $ghost) use (
				$instance,
				$metadata,
				$itemClassName,
			) {
				$ownerMetadata = $this->metadataFactory->get($instance);
				$itemMetadata = $this->metadataFactory->get($itemClassName);
				$ownerPrimaryKey = $ownerMetadata->requirePrimaryKey();
				$itemPrimaryKey = $itemMetadata->requirePrimaryKey();
				[$junctionTable, $ownerColumn, $itemColumn] = $this->columnName
					->junction(
						$ownerMetadata->getTableName(),
						$ownerPrimaryKey->getName(),
						$metadata->getName(),
						$itemMetadata->getTableName(),
						$itemPrimaryKey->getName(),
					);
				$builder = $this->queryFactory->select();
				$builder->from($junctionTable)
					->select($itemColumn)
					->where("$ownerColumn = :$ownerColumn")
					->orderBy("id");

				$ownerPrimaryKeyValue = $ownerPrimaryKey->getProperty()
					->getValue($instance);
				$resultSet = $this->database->executeSql(
					(string)$builder,
					[$ownerColumn => $ownerPrimaryKeyValue],
				);
				$itemList = [];
				while($row = $resultSet->fetch()) {
					$itemList[] = $this->createLazyEntityReference(
						$itemClassName,
						$itemPrimaryKey->fromDatabase($row->get($itemColumn)),
					);
				}

				$metadata->initialiseCollectionGhost($ghost, $itemList);
			}
		);

		$metadata->getProperty()->setValue($instance, $lazyGhost);
	}

	private function getColumnName(PropertyMetadata $metadata):string {
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

	private function createLazyEntityReference(
		string $className,
		int|string $primaryKeyValue,
	):Entity {
		$className = $this->metadataFactory
			->requireEntityClassName($className);
		$metadata = $this->metadataFactory->get($className);
		$primaryKey = $metadata->requirePrimaryKey();
		$lazyGhost = $this->metadataFactory->newLazyGhost(
			$className,
			function(object $ghost) use ($className, $metadata, $primaryKeyValue) {
				$entity = $this->fetch($className, $primaryKeyValue);
				foreach($metadata->getPropertyList() as $propertyMetadata) {
					$property = $propertyMetadata->getProperty();
					if($property->isInitialized($ghost)
						|| !$property->isInitialized($entity)) {
						continue;
					}

					$property->setValue($ghost, $property->getValue($entity));
				}
			},
		);
		$primaryKeyProperty = $primaryKey->getProperty();
		$primaryKeyProperty->skipLazyInitialization($lazyGhost);
		$primaryKeyProperty->setRawValueWithoutLazyInitialization(
			$lazyGhost,
			$primaryKeyValue,
		);

		return $lazyGhost;
	}

	private function cacheEntity(Entity $entity):void {
		$metadata = $this->metadataFactory->get($entity);
		$hasUninitialisedProperty = array_any(
			$metadata->getPropertyList(),
			fn(PropertyMetadata $property) => !$property->isCollection()
				&& !$property->getProperty()->isInitialized($entity),
		);
		if($hasUninitialisedProperty) {
			return;
		}

		$primaryKey = $metadata->requirePrimaryKey();
		$value = $primaryKey->getProperty()->getValue($entity);
		if(is_int($value) || is_string($value)) {
			$this->entityCache[$entity::class][$value] = $entity;
		}
	}

	private function rowContains(Row $row, string $propertyName):bool {
		return $row->contains($propertyName);
	}
}
