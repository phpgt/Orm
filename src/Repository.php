<?php
namespace GT\Orm;

use GT\Database\Database;
use GT\Database\Result\Row;
use GT\Orm\Metadata\ColumnName;
use GT\Orm\Metadata\EntityMetadataFactory;
use GT\Orm\Metadata\PropertyKind;
use GT\Orm\Metadata\PropertyMetadata;
use GT\SqlBuilder\Condition\Condition;
use GT\SqlBuilder\SelectBuilder;
use ReflectionClass;

class Repository {
	/** @var array<class-string, array<int|string, object>> */
	private array $entityCache = [];
	private EntityMetadataFactory $metadataFactory;
	private ColumnName $columnName;

	public function __construct(
		protected Database $database,
		?EntityMetadataFactory $metadataFactory = null,
	) {
		$this->metadataFactory = $metadataFactory ?? new EntityMetadataFactory();
		$this->columnName = new ColumnName();
	}

	/**
	 * Fetch a single object of type T matching the given criteria.
	 *
	 * @template T
	 * @param class-string<T> $className The class name of the class
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
	 * @return null|T
	 */
	public function fetch(
		string $className,
		int|string|Condition... $match,
	) {
		$parameters = [];

		$primaryKey = $this->getPrimaryKey($className);

		if(count($match) === 1 && !$match[0] instanceof Condition) {
			$parameters[$primaryKey] = $match[0];
			if(isset($this->entityCache[$className][$match[0]])) {
				return $this->entityCache[$className][$match[0]];
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
			array_push(
				$columnList,
				$this->getColumnName($propertyMetadata),
			);
		}

		return $columnList;
	}

	/**
	 * @template T
	 * @param class-string<T> $className
	 * @param null|object $instance An existing object reference to hydrate
	 * @return null|T
	 */
	protected function rowToEntity(Row $row, string $className, ?object $instance = null) {
		$refClass = new ReflectionClass($className);
		$metadata = $this->metadataFactory->get($className);

		if($instance === null) {
			$instance = $refClass->newInstanceWithoutConstructor();
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
		$columnName = $this->getColumnName($metadata);
		if(!array_key_exists($columnName, $rowValues)) {
			return;
		}

		if(in_array($metadata->getKind(), [
			PropertyKind::SCALAR,
			PropertyKind::DATE_TIME,
			PropertyKind::BACKED_ENUM,
		], true)) {
			$this->setInstanceProperty(
				$instance,
				$metadata,
				$rowValues[$columnName],
			);
			return;
		}

		if($metadata->getKind() === PropertyKind::COLLECTION) {
			$this->handleLazyCollectionProperty(
				$instance,
				$metadata,
			);
			return;
		}

		$foreignPrimaryKeyValue = $rowValues[$columnName];
		if($foreignPrimaryKeyValue === null) {
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

		$foreignMetadata = $this->metadataFactory->get($metadata->getTypeName());
		$typeName = $foreignMetadata->getClassName();
		$refClassForeign = new ReflectionClass($typeName);
		$lazyGhost = $refClassForeign->newLazyGhost(
			function(object $ghost) use ($foreignMetadata, $typeName, $foreignPrimaryKeyValue) {
				$referencedEntity = $this->fetch($typeName, $foreignPrimaryKeyValue);
				foreach($foreignMetadata->getPropertyList() as $propertyMetadata) {
					$refProperty = $propertyMetadata->getProperty();
					if(!$refProperty->isInitialized($referencedEntity)) {
						continue;
					}

					$value = $refProperty->getValue($referencedEntity);
					$refProperty->setValue($ghost, $value);
				}
			}
		);

		$metadata->getProperty()->setValue($instance, $lazyGhost);
	}

	private function handleLazyCollectionProperty(
		object $instance,
		PropertyMetadata $metadata,
	):void {
		$typeName = $metadata->getTypeName();
		$refClassCollection = new ReflectionClass($typeName);
		$itemClassName = $this->inferCollectionItemClassName($typeName);

		$lazyGhost = $refClassCollection->newLazyGhost(
			function(object $ghost) use ($refClassCollection, $typeName, $itemClassName) {
				$builder = new SelectBuilder();
				$builder->from($this->getTableName($itemClassName))
					->select($this->getPrimaryKey($itemClassName));

				$resultSet = $this->database->executeSql((string)$builder, []);
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

					$itemList[] = $this->rowToPartialEntity($row, $itemClassName);
				}

				$collection = new $typeName($itemList);
				foreach($refClassCollection->getProperties() as $collectionProperty) {
					if(!$collectionProperty->isInitialized($collection)) {
						continue;
					}

					$collectionProperty->setValue($ghost, $collectionProperty->getValue($collection));
				}
			}
		);

		$metadata->getProperty()->setValue($instance, $lazyGhost);
	}

	private function getColumnName(PropertyMetadata $metadata):string {
		if($metadata->getKind() === PropertyKind::COLLECTION) {
			return $this->columnName->junctionPlaceholder($metadata->getName());
		}
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

	private function inferCollectionItemClassName(string $collectionClassName):string {
		$namespace = substr($collectionClassName, 0, (int)strrpos($collectionClassName, "\\"));
		$shortName = substr($collectionClassName, (int)strrpos($collectionClassName, "\\") + 1);

		if(str_ends_with($shortName, "List")) {
			return $namespace . "\\" . substr($shortName, 0, -4);
		}

		return $collectionClassName;
	}

	private function rowToPartialEntity(Row $row, string $className):object {
		$entity = (new ReflectionClass($className))->newInstanceWithoutConstructor();
		$primaryKey = $this->metadataFactory->get($className)->requirePrimaryKey();
		$primaryKey->getProperty()->setValue(
			$entity,
			$primaryKey->fromDatabase($row->get($primaryKey->getName())),
		);

		return $entity;
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
