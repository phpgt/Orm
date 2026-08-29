<?php
namespace GT\Orm;

use GT\Database\Database;
use GT\Database\Result\Row;
use GT\Orm\Metadata\ColumnName;
use GT\Orm\Metadata\EntityMetadataFactory;
use GT\Orm\Metadata\PropertyMetadata;
use GT\Orm\Persistence\EntityWriter;
use GT\SqlBuilder\Condition\Condition;
use GT\SqlBuilder\SelectBuilder;

class Repository {
	/** @var array<class-string, array<int|string, object>> */
	private array $entityCache = [];
	private EntityMetadataFactory $metadataFactory;
	private ColumnName $columnName;
	private EntityWriter $entityWriter;

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
	 * @param TUpdatedEntity $entity
	 * @param array<string, mixed> $changeList
	 * @return TUpdatedEntity
	 */
	public function update(Entity $entity, array $changeList):Entity {
		$entity = $this->entityWriter->update($entity, $changeList);
		$this->cacheEntity($entity);
		return $entity;
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
	) {
		$parameters = [];

		$primaryKey = $this->getPrimaryKey($className);

		if(count($match) === 1 && !$match[0] instanceof Condition) {
			$parameters[$primaryKey] = $match[0];
			$cachedEntity = $this->entityCache[$className][$match[0]] ?? null;
			if($cachedEntity instanceof $className) {
				return $cachedEntity;
			}
		}

		$builder = new SelectBuilder();
		$builder->from($this->getTableName($className))
			->select(...$this->getColumnList($className))
			->where("$primaryKey = :$primaryKey");

		$resultSet = $this->database->executeSql((string)$builder, $parameters);
		$row = $resultSet->fetch();

		$entity = $this->rowToEntity($row, $className);
		if(isset($parameters[$primaryKey])) {
			$this->entityCache[$className][$parameters[$primaryKey]] = $entity;
		}

		return $entity;
	}

	public function getTableName(string $entityClassName):string {
		return $this->metadataFactory->get($entityClassName)->getTableName();
	}

	/** @param object|class-string $entity */
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
	 * @return null|THydratedEntity
	 */
	protected function rowToEntity(Row $row, string $className, ?object $instance = null) {
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
				$ownerPrimaryKey = $ownerMetadata->requirePrimaryKey();
				$itemMetadata = $this->metadataFactory->get($itemClassName);
				$itemPrimaryKey = $itemMetadata->requirePrimaryKey();
				$junctionTable = $this->columnName->junctionTable(
					$ownerMetadata->getTableName(),
					$metadata->getName(),
					$itemMetadata->getTableName(),
				);
				$ownerColumn = $this->columnName->junctionForeignKey(
					$ownerMetadata->getTableName(),
					$ownerPrimaryKey->getName(),
				);
				$itemColumn = $this->columnName->junctionItemForeignKey(
					$ownerMetadata->getTableName(),
					$ownerPrimaryKey->getName(),
					$metadata->getName(),
					$itemMetadata->getTableName(),
					$itemPrimaryKey->getName(),
				);
				$builder = new SelectBuilder();
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
				while(true) {
					try {
						$row = $resultSet->fetch();
					}
					catch(\Throwable) {
						break;
					}

					if(!$row) {
						break;
					}

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
		foreach($metadata->getPropertyList() as $property) {
			if(!$property->isCollection()
				&& !$property->getProperty()->isInitialized($entity)) {
				return;
			}
		}

		$primaryKey = $metadata->requirePrimaryKey();
		$value = $primaryKey->getProperty()->getValue($entity);
		if(is_int($value) || is_string($value)) {
			$this->entityCache[$entity::class][$value] = $entity;
		}
	}

	private function rowContains(Row $row, string $propertyName):bool {
		try {
			return $row->contains($propertyName);
		}
		catch(\Throwable) {
			return false;
		}
	}
}
