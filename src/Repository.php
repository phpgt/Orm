<?php
namespace Gt\Orm;

use DateTime;
use DateTimeInterface;
use Gt\Database\Database;
use Gt\Database\Result\Row;
use Gt\SqlBuilder\Condition\Condition;
use Gt\SqlBuilder\SelectBuilder;
use ReflectionClass;
use ReflectionObject;
use ReflectionProperty;
use Stringable;

class Repository {
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
		}

		$builder = new SelectBuilder();
		$builder->from($this->getTableName($className))
			->select(...$this->getColumnList($className))
			->where("id = :id");

		$builder = (string)$builder;

		$resultSet = $this->database->executeSql($builder, $parameters);
		$row = $resultSet->fetch();

		return $this->rowToEntity($row, $className);
	}

	public function getTableName(string $entityClassName):string {
		return (new ReflectionClass($entityClassName))->getShortName();
	}

	/** @param object|class-string $entity */
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
			$refType = $refProperty->getType();

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

		// Create an instance without constructor if none provided
		// This allows setting readonly properties
		if ($instance === null) {
			$instance = $refClass->newInstanceWithoutConstructor();
		}

		$refPropertyArray = $refClass->getProperties(
			ReflectionProperty::IS_PUBLIC,
		);

		$rowValues = [];
		foreach($refPropertyArray as $refProperty) {
			$refTypeName = $refProperty->getType()->getName();
			$propertyName = $refProperty->getName();
			if(class_exists($refTypeName)) {
				$foreignTableName = $this->getTableName($refTypeName);
				$foreignPrimaryKey = $this->getPrimaryKey($refTypeName);

				if(is_subclass_of($refTypeName, \Traversable::class)) {
					// TODO: Look up the junction table for this joined row.
					continue;
					$propertyName = $this->buildJunctionKey($propertyName, $foreignTableName, $foreignPrimaryKey);
				}
				else {
					$propertyName = $this->buildForeignKey($propertyName, $foreignTableName, $foreignPrimaryKey);
				}
			}

			if(!$row->contains($propertyName)) {
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
				$foreignPropertyType = $refProperty->getType()->getName();
				// Skip if not a class type (e.g., string, int, etc.)
				if (!class_exists($foreignPropertyType)) {
					continue;
				}

				$foreignTableName = $this->getTableName($foreignPropertyType);
				$foreignPrimaryKey = $this->getPrimaryKey($foreignPropertyType);
				$columnName = $this->buildForeignKey(
					$propertyName,
					$foreignTableName,
					$foreignPrimaryKey,
				);

				if (!isset($rowValues[$columnName])) {
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
		if(!$refType->isBuiltin()) {
			$typeName = $refType->getName();

			if(is_subclass_of($typeName, DateTimeInterface::class) || $typeName === DateTimeInterface::class) {
				$value = new $typeName($value);
			}
		}

		// Use reflection directly to set the value regardless of readonly status
		$refProperty->setValue($instance, $value);
	}

	private function handleLazyInstanceProperty(
		object $instance,
		ReflectionProperty $refProperty,
		string $typeName,
		null|int|string $foreignPrimaryKeyValue,
	):void {
		if(is_null($foreignPrimaryKeyValue)) {
			// Leave the property unset
			return;
		}

		$refClassForeign = new ReflectionClass($typeName);

		// Create the lazy ghost with a callback that will load the entity when accessed
		$lazyGhost = $refClassForeign->newLazyGhost(
			function(object $ghost) use ($refClassForeign, $typeName, $foreignPrimaryKeyValue) {
				$referencedEntity = $this->fetch($typeName, $foreignPrimaryKeyValue);
				foreach($refClassForeign->getProperties(ReflectionProperty::IS_PUBLIC) as $refProperty) {
					if($refProperty->isLazy($referencedEntity)) {
						// Lazy property
						die("LAZY!");
					}
					else {
						$value = $refProperty->getValue($referencedEntity);
					}
					$refProperty->setValue($ghost, $value);
				}
//				$referencedResultSet = $this->database->executeSql($builder, [
//					"id" => $foreignPrimaryKeyValue,
//				]);
//				$referencedRow = $referencedResultSet->fetch();

				// Hydrate the ghost object directly
//				if ($referencedRow) {
//					$this->rowToEntity($referencedRow, $typeName, $ghost);
//				}
			}
		);

		// Set the ghost object as the property value
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

	private function buildJunctionKey(
		string $propertyName,
		string $foreignTableName,
		string $foreignPrimaryKey,
	):string {
		return implode("_", [
			$propertyName,
			"junction",
			$foreignTableName,
			$foreignPrimaryKey,
		]);
	}
}