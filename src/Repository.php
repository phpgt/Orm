<?php
namespace GT\Orm;

use DateTime;
use DateTimeInterface;
use Gt\Database\Database;
use Gt\Database\Result\Row;
use Gt\SqlBuilder\Condition\Condition;
use Gt\SqlBuilder\SelectBuilder;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Stringable;

class Repository {
	/** @var array<class-string, array<int|string, object>> */
	private array $entityCache = [];

	public function __construct(
		protected Database $database,
	) {
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

		if(count($match) === 1 && (is_int($match[0]) || is_string($match[0]))) {
			$parameters[$primaryKey] = $match[0];
			if(isset($this->entityCache[$className][$match[0]])) {
				return $this->entityCache[$className][$match[0]];
			}
		}

		$builder = new SelectBuilder();
		$builder->from($this->getTableName($className))
			->select(...$this->getColumnList($className))
			->where("id = :id");

		$resultSet = $this->database->executeSql((string)$builder, $parameters);
		$row = $resultSet->fetch();

		$entity = $this->rowToEntity($row, $className);
		if(isset($parameters[$primaryKey])) {
			$this->entityCache[$className][$parameters[$primaryKey]] = $entity;
		}

		return $entity;
	}

	public function getTableName(string $entityClassName):string {
		return (new ReflectionClass($entityClassName))->getShortName();
	}

	/** @param object|class-string $entity */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	private function getPrimaryKey(object|string $entity):string {
		// TODO: How do we detect primary keys that are not called "id"?
		return "id";
	}

	/** @return array<string> */
	public function getColumnList(string $entityClassName):array {
		$columnList = [];

		$refClass = new ReflectionClass($entityClassName);
		foreach($refClass->getProperties() as $refProperty) {
			if(!$refProperty->isPublic()) {
				continue;
			}
			if(!$refProperty->hasType()) {
				continue;
			}

			$name = $refProperty->getName();
			$refType = $this->getNamedPropertyType($refProperty);
			if($refType === null) {
				continue;
			}

			if($refType->isBuiltin()) {
				array_push($columnList, $name);
				continue;
			}

			$type = $refType->getName();
			$allowedPlainClasses = [
				DateTimeInterface::class,
				Stringable::class,
			];

			foreach($allowedPlainClasses as $plainClass) {
				if(is_subclass_of($type, $plainClass) || $type === $plainClass) {
					array_push($columnList, $name);
					continue(2);
				}
			}

			$referencedPrimaryKey = $this->getPrimaryKey($type);
			$referencedTableName = $this->getTableName($type);
			array_push($columnList, $this->buildForeignKey($name, $referencedTableName, $referencedPrimaryKey));
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

		if($instance === null) {
			$instance = $refClass->newInstanceWithoutConstructor();
		}

		$refPropertyArray = $refClass->getProperties(
			ReflectionProperty::IS_PUBLIC,
		);

		$rowValues = [];
		foreach($refPropertyArray as $refProperty) {
			$refType = $this->getNamedPropertyType($refProperty);
			if($refType === null) {
				continue;
			}

			$refTypeName = $refType->getName();
			$propertyName = $refProperty->getName();
			if(class_exists($refTypeName)) {
				if(is_subclass_of($refTypeName, \Traversable::class)) {
					$propertyName = $this->buildJunctionPlaceholderKey($propertyName);
				}
				else {
					$foreignTableName = $this->getTableName($refTypeName);
					$foreignPrimaryKey = $this->getPrimaryKey($refTypeName);
					$propertyName = $this->buildForeignKey($propertyName, $foreignTableName, $foreignPrimaryKey);
				}
			}

			if(!$this->rowContains($row, $propertyName)) {
				continue;
			}
			$rowValues[$propertyName] = $row->get($propertyName);
		}

		foreach($refPropertyArray as $refProperty) {
			$propertyName = $refProperty->getName();
			if(isset($rowValues[$propertyName])) {
				$this->setInstanceProperty(
					$instance,
					$refProperty,
					$rowValues[$propertyName],
				);
			}
			else {
				$refType = $this->getNamedPropertyType($refProperty);
				if($refType === null) {
					continue;
				}

				$foreignPropertyType = $refType->getName();
				if(!class_exists($foreignPropertyType)) {
					continue;
				}

				if(is_subclass_of($foreignPropertyType, \Traversable::class)) {
					$columnName = $this->buildJunctionPlaceholderKey($propertyName);
					if(!array_key_exists($columnName, $rowValues)) {
						continue;
					}

					$this->handleLazyCollectionProperty(
						$instance,
						$refProperty,
						$foreignPropertyType,
					);
					continue;
				}

				$foreignTableName = $this->getTableName($foreignPropertyType);
				$foreignPrimaryKey = $this->getPrimaryKey($foreignPropertyType);
				$columnName = $this->buildForeignKey(
					$propertyName,
					$foreignTableName,
					$foreignPrimaryKey,
				);

				if(!isset($rowValues[$columnName])) {
					continue;
				}

				$foreignPrimaryKeyValue = $rowValues[$columnName];
				$this->handleLazyInstanceProperty(
					$instance,
					$refProperty,
					$foreignPropertyType,
					$foreignPrimaryKeyValue,
				);
			}
		}

		return $instance;
	}

	private function setInstanceProperty(
		object $instance,
		ReflectionProperty $refProperty,
		string $value,
	):void {
		$refType = $refProperty->getType();
		if(!$refType instanceof ReflectionNamedType) {
			return;
		}

		if(!$refType->isBuiltin()) {
			$typeName = $refType->getName();

			if(is_subclass_of($typeName, DateTimeInterface::class) || $typeName === DateTimeInterface::class) {
				$value = new $typeName($value);
			}
		}

		$refProperty->setValue($instance, $value);
	}

	/** @param class-string $typeName */
	private function handleLazyInstanceProperty(
		object $instance,
		ReflectionProperty $refProperty,
		string $typeName,
		null|int|string $foreignPrimaryKeyValue,
	):void {
		if(is_null($foreignPrimaryKeyValue)) {
			return;
		}

		$refClassForeign = new ReflectionClass($typeName);
		$lazyGhost = $refClassForeign->newLazyGhost(
			function(object $ghost) use ($refClassForeign, $typeName, $foreignPrimaryKeyValue) {
				$referencedEntity = $this->fetch($typeName, $foreignPrimaryKeyValue);
				foreach($refClassForeign->getProperties(ReflectionProperty::IS_PUBLIC) as $refProperty) {
					if(!$refProperty->isInitialized($referencedEntity)) {
						continue;
					}

					$value = $refProperty->getValue($referencedEntity);
					$refProperty->setValue($ghost, $value);
				}
			}
		);

		$refProperty->setValue($instance, $lazyGhost);
	}

	/** @param class-string $typeName */
	private function handleLazyCollectionProperty(
		object $instance,
		ReflectionProperty $refProperty,
		string $typeName,
	):void {
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

		$refProperty->setValue($instance, $lazyGhost);
	}

	private function buildForeignKey(
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

	private function buildJunctionPlaceholderKey(string $propertyName):string {
		return implode("_", [
			$propertyName,
			"TODO",
			"JUNCTION",
			"TABLE",
		]);
	}

	/** @return class-string */
	private function inferCollectionItemClassName(string $collectionClassName):string {
		$namespace = substr($collectionClassName, 0, (int)strrpos($collectionClassName, "\\"));
		$shortName = substr($collectionClassName, (int)strrpos($collectionClassName, "\\") + 1);

		if(str_ends_with($shortName, "List")) {
			return $namespace . "\\" . substr($shortName, 0, -4);
		}

		return $collectionClassName;
	}

	/** @param class-string $className */
	private function rowToPartialEntity(Row $row, string $className):object {
		$entity = (new ReflectionClass($className))->newInstanceWithoutConstructor();
		$primaryKey = $this->getPrimaryKey($className);
		$property = new ReflectionProperty($className, $primaryKey);
		$property->setValue($entity, $row->get($primaryKey));

		return $entity;
	}

	private function rowContains(Row $row, string $propertyName):bool {
		try {
			return $row->contains($propertyName);
		}
		catch(\TypeError) {
			return false;
		}
	}

	private function getNamedPropertyType(ReflectionProperty $refProperty):?ReflectionNamedType {
		$refType = $refProperty->getType();
		if(!$refType instanceof ReflectionNamedType) {
			return null;
		}

		return $refType;
	}
}
