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
	 * 	treated as the primary key (equivalent to getById)
	 * string, int|string
	 * 	first argument is the field name,
	 * 	second argument is the value/comparison to match
	 * Condition[]
	 * 	used to build the where/join clauses, and/or handled
	 * 	by the Condition implementation
	 *
	 * @return null|T
	 */
	public function fetch(
		string $className,
		int|string|Condition... $match,
	):?object {
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

	/** @param class-string $entity */
	private function getPrimaryKey(string $entity):string {
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
			array_push($columnList, "{$name}_{$referencedTableName}_$referencedPrimaryKey");
		}

		return $columnList;
	}

	/**
	 * @template T
	 * @param class-string<T> $className
	 * @param null|object $instance An existing object reference to hydrate
	 * @return null|T
	 */
	protected function rowToEntity(Row $row, string $className, ?object $instance = null):?object {
		$refClass = new ReflectionClass($className);
		$refPropertyArray = $refClass->getProperties(
			ReflectionProperty::IS_PUBLIC,
		);

		$propertyValues = [];
		foreach($refPropertyArray as $refProperty) {
			$propertyName = $refProperty->getName();

			if(!$row->contains($propertyName)) {
				continue;
			}
			$propertyValues[$propertyName] = $row->get($propertyName);
		}

		$instance = $instance ?? $refClass->newInstanceWithoutConstructor();

		foreach($refPropertyArray as $refProperty) {
			$propertyName = $refProperty->getName();
			if(isset($propertyValues[$propertyName])) {
				$this->setInstanceProperty(
					$instance,
					$refClass,
					$propertyName,
					$propertyValues[$propertyName],
				);
			}
			else {
				$this->handleLazyInstanceProperty(
					$instance,
					$refClass,
					$propertyName,
					$row,
				);
			}
		}

		return $instance;
	}

	private function setInstanceProperty(
		object $instance,
		ReflectionClass $refClass,
		string $propertyName,
		string $value,
	):void {
		if(!$refClass->hasProperty($propertyName)) {
			return;
		}

		$refProperty = $refClass->getProperty($propertyName);
		$refType = $refProperty->getType();
		if(!$refType->isBuiltin()) {
			$typeName = $refType->getName();

			if(is_subclass_of($typeName, DateTimeInterface::class) || $typeName === DateTimeInterface::class) {
				$value = new $typeName($value);
			}
		}
		$refProperty->setValue($instance, $value);
	}

	private function handleLazyInstanceProperty(
		object $instance,
		ReflectionClass $refClass,
		string $propertyName,
		Row $row,
	):void {
		$refProperty = $refClass->getProperty($propertyName);
		$refType = $refProperty->getType();
		$typeName = $refType->getName();

		$refClassForeign = new ReflectionClass($typeName);
		$lazyProperty = $refClassForeign->newLazyGhost(function(object $ghost)use($typeName, $propertyName) {
			$referencedTableName = $this->getTableName($typeName);
			$referencedPrimaryKey = $this->getPrimaryKey($typeName);
			$referencedPrimaryKeyValue = "{$propertyName}_{$referencedTableName}_{$referencedPrimaryKey}";

			$builder = new SelectBuilder();
			$builder->from($this->getTableName($typeName))
				->select(...$this->getColumnList($typeName))
				->where("id = :id");
			$referencedResultSet = $this->database->executeSql($builder, [
				"id" => $referencedPrimaryKeyValue,
			]);
			$referencedRow = $referencedResultSet->fetch();
			$this->rowToEntity($referencedRow, $typeName, $ghost);
		});

		$refProperty->setValue($instance, $lazyProperty);
	}

	private function initGhost(...$args):void {
		var_dump($args);
		sleep(5);
		var_dump($args);
	}

}
