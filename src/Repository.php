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
	private EntityInspector $entityInspector;

	public function __construct(
		protected Database $database,
	) {
		$this->entityInspector = new EntityInspector();
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
	):?object {
		[$parameters, $builderCallback, $cacheKey] = $this->buildMatchQuery($className, ...$match);
		if(!is_null($cacheKey) && isset($this->entityCache[$className][$cacheKey])) {
			return $this->entityCache[$className][$cacheKey];
		}

		$entityList = $this->fetchByQuery(
			$className,
			$parameters,
			$builderCallback,
			true,
		);
		return $entityList[0] ?? null;
	}

	/**
	 * @template T
	 * @param class-string<T> $class
	 * @param int|string $match $match can take variable arguments:
	 * single int|string
	 *     treated as the primary key
	 * string, int|string
	 *     first argument is the field name,
	 *     second argument is the value/comparison to match
	 * Condition[]
	 *     used to build the where clause
	 * @return list<T>
	 */
	public function fetchAll(
		string $class,
		int|string|Condition...$match,
	):array {
		[$parameters, $builderCallback] = $this->buildMatchQuery($class, ...$match);
		return $this->fetchByQuery($class, $parameters, $builderCallback);
	}

	/**
	 * @template T
	 * @param class-string<T> $className
	 * @param array<string, int|string> $parameters
	 * @param null|callable(SelectBuilder):void $builderCallback
	 * @param bool $single
	 * @return list<T>
	 */
	private function fetchByQuery(
		string $className,
		array $parameters = [],
		?callable $builderCallback = null,
		bool $single = false,
	):array {
		$builder = new SelectBuilder();
		$builder->from($this->getTableName($className))
			->select(...$this->getColumnList($className));

		if($builderCallback) {
			$builderCallback($builder);
		}

		$resultSet = $this->database->executeSql((string)$builder, $parameters);
		$entityList = [];
		$primaryKey = $this->getPrimaryKey($className);

		if($single) {
			$row = $resultSet->fetch();
			$entity = $this->rowToEntity($row, $className);
			if($entity) {
				$entityList[] = $entity;
			}
		}
		else {
			while($row = $resultSet->fetch()) {
				$entity = $this->rowToEntity($row, $className);
				if(!$entity) {
					continue;
				}

				$entityList[] = $entity;
			}
		}

		if(isset($parameters[$primaryKey], $entityList[0])) {
			$this->entityCache[$className][$parameters[$primaryKey]] = $entityList[0];
		}

		return $entityList;
	}

	/**
	 * @param class-string $className
	 * @param int|string|Condition ...$match
	 * @return array{0: array<string, int|string>, 1: null|callable(SelectBuilder):void, 2: null|int|string}
	 */
	private function buildMatchQuery(
		string $className,
		int|string|Condition...$match,
	):array {
		if(empty($match)) {
			return [[], null, null];
		}

		$primaryKey = $this->getPrimaryKey($className);
		if(count($match) === 1 && (is_int($match[0]) || is_string($match[0]))) {
			$parameters = [$primaryKey => $match[0]];
			return [
				$parameters,
				fn(SelectBuilder $builder) => $builder->where("$primaryKey = :$primaryKey"),
				$match[0],
			];
		}

		if(count($match) === 2
			&& is_string($match[0])
			&& (is_int($match[1]) || is_string($match[1]))
		) {
			$fieldName = $match[0];
			$parameters = [$fieldName => $match[1]];
			return [
				$parameters,
				fn(SelectBuilder $builder) => $builder->where("$fieldName = :$fieldName"),
				null,
			];
		}

		$conditionList = array_map(
			fn(Condition $condition) => $condition->getCondition(),
			$match,
		);
		return [
			[],
			fn(SelectBuilder $builder) => $builder->where(...$conditionList),
			null,
		];
	}

	public function insert(Entity...$entityList):void {
		foreach($entityList as $entity) {
			$className = $entity::class;
			$tableName = $this->getTableName($className);
			$insertData = $this->getInsertData($entity);

			$columnList = array_keys($insertData);
			$placeholderList = array_map(
				fn(string $columnName) => ":" . $columnName,
				$columnList,
			);

			$sql = implode(" ", [
				"insert into",
				$tableName,
				"(",
				implode(", ", $columnList),
				")",
				"values",
				"(",
				implode(", ", $placeholderList),
				")",
			]);

			$this->database->executeSql($sql, $insertData);
		}
	}

	public function getTableName(string $entityClassName):string {
		return $this->entityInspector->getTableName($entityClassName);
	}

	/** @param object|class-string $entity */
	private function getPrimaryKey(object|string $entity):string {
		return $this->entityInspector->getPrimaryKeyName($entity);
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

				if($refType->getName() === "array"
					&& $this->entityInspector->getListItemClassName($refProperty)
				) {
					continue;
				}

				if($this->isCollectionRelationshipProperty($refProperty)) {
					continue;
				}

				if($refType->isBuiltin()) {
					array_push($columnList, $name);
					continue;
			}

			$type = $refType->getName();
			if($this->entityInspector->isPlainColumnType($type)) {
				array_push($columnList, $name);
				continue;
			}

			$referencedPrimaryKey = $this->getPrimaryKey($type);
			$referencedTableName = $this->getTableName($type);
			array_push(
				$columnList,
				$this->buildForeignKey($name, $referencedTableName, $referencedPrimaryKey),
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
	protected function rowToEntity(?Row $row, string $className, ?object $instance = null):?object {
		if(!$row) {
			return null;
		}

		$refClass = new ReflectionClass($className);
		$containsArrayRelationship = $this->containsArrayRelationship($refClass);
		$primaryKey = $this->getPrimaryKey($className);

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
			if($this->isArrayRelationshipProperty($refProperty)) {
				continue;
			}

			if($this->isCollectionRelationshipProperty($refProperty)) {
				continue;
			}

			if(class_exists($refTypeName)) {
				if(!$this->entityInspector->isPlainColumnType($refTypeName)) {
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

		if($instance === null) {
			if($containsArrayRelationship) {
				$instance = $this->createEntityGhost(
					$className,
					$rowValues[$primaryKey] ?? null,
				);
			}
			else {
				$instance = $refClass->newInstanceWithoutConstructor();
			}
		}

		$setRawValues = $containsArrayRelationship;

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

				if($this->isArrayRelationshipProperty($refProperty)) {
					continue;
				}

				if($this->isCollectionRelationshipProperty($refProperty)) {
					$this->handleLazyCollectionProperty(
						$instance,
						$refProperty,
						$setRawValues,
					);
					continue;
				}

				$foreignPropertyType = $refType->getName();
				if($this->entityInspector->isPlainColumnType($foreignPropertyType)) {
					continue;
				}

				if(!class_exists($foreignPropertyType)) {
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
					$setRawValues,
				);
			}
		}

		return $instance;
	}

	private function setInstanceProperty(
		object $instance,
		ReflectionProperty $refProperty,
		string $value,
		bool $setRawValue = false,
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

		$this->assignPropertyValue($instance, $refProperty, $value, $setRawValue);
	}

	/** @param class-string $typeName */
	private function handleLazyInstanceProperty(
		object $instance,
		ReflectionProperty $refProperty,
		string $typeName,
		null|int|string $foreignPrimaryKeyValue,
		bool $setRawValue = false,
	):void {
		if(is_null($foreignPrimaryKeyValue)) {
			return;
		}

		$lazyGhost = $this->createEntityReference($typeName, $foreignPrimaryKeyValue);
		$this->assignPropertyValue($instance, $refProperty, $lazyGhost, $setRawValue);
	}

	private function handleLazyCollectionProperty(
		object $instance,
		ReflectionProperty $refProperty,
		bool $setRawValue = false,
	):void {
		$typeName = $this->getNamedPropertyType($refProperty)?->getName();
		if(is_null($typeName)) {
			return;
		}

		$refClassCollection = new ReflectionClass($typeName);
		$itemClassName = $this->getCollectionItemClassName($refProperty);
		if(is_null($itemClassName)) {
			return;
		}

		$lazyGhost = $refClassCollection->newLazyGhost(
			function(object $ghost) use ($instance, $refClassCollection, $typeName, $itemClassName, $refProperty) {
				$itemList = $this->fetchRelationshipItemReferences(
					$instance::class,
					$instance,
					$refProperty,
					$itemClassName,
				);
				$collection = new $typeName($itemList);
				foreach($refClassCollection->getProperties() as $collectionProperty) {
					if(!$collectionProperty->isInitialized($collection)) {
						continue;
					}

					$collectionProperty->setRawValueWithoutLazyInitialization(
						$ghost,
						$collectionProperty->getValue($collection),
					);
				}
			}
		);

		$this->assignPropertyValue($instance, $refProperty, $lazyGhost, $setRawValue);
	}

	private function buildForeignKey(
		string $propertyName,
		string $foreignTableName,
		string $foreignPrimaryKey,
	):string {
		return $this->entityInspector->buildForeignKeyName(
			$propertyName,
			$foreignTableName,
			$foreignPrimaryKey,
		);
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
		$primaryKey = $this->getPrimaryKey($className);
		return $this->createEntityReference(
			$className,
			$row->get($primaryKey),
		);
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

	/** @param class-string $className */
	private function createEntityGhost(
		string $className,
		int|string|null $primaryKeyValue = null,
	):object {
		$refClass = new ReflectionClass($className);
		return $refClass->newLazyGhost(
			function(object $ghost) use ($className, $primaryKeyValue) {
				$this->initializeArrayRelationshipProperties(
					$ghost,
					$className,
					$primaryKeyValue,
				);
			}
		);
	}

	/** @param class-string $className */
	private function createEntityReference(
		string $className,
		int|string $primaryKeyValue,
	):object {
		$refClassForeign = new ReflectionClass($className);
		$lazyGhost = $refClassForeign->newLazyGhost(
				function(object $ghost) use ($refClassForeign, $className, $primaryKeyValue) {
					$referencedEntity = $this->fetch($className, $primaryKeyValue);
					foreach($refClassForeign->getProperties(ReflectionProperty::IS_PUBLIC) as $refProperty) {
						if(!$refProperty->isInitialized($referencedEntity)) {
							continue;
						}
						if($refProperty->isInitialized($ghost)) {
							continue;
						}

						$refProperty->setRawValueWithoutLazyInitialization(
							$ghost,
							$refProperty->getValue($referencedEntity),
					);
				}
			}
		);

		$primaryKeyProperty = new ReflectionProperty($className, $this->getPrimaryKey($className));
		$primaryKeyProperty->setRawValueWithoutLazyInitialization(
			$lazyGhost,
			$primaryKeyValue,
		);

		return $lazyGhost;
	}

	/** @param class-string $className */
	private function initializeArrayRelationshipProperties(
		object $ghost,
		string $className,
		int|string|null $primaryKeyValue,
	):void {
		$refClass = new ReflectionClass($className);
		foreach($refClass->getProperties(ReflectionProperty::IS_PUBLIC) as $refProperty) {
			if(!$this->isArrayRelationshipProperty($refProperty)) {
				continue;
			}

			$itemClassName = $this->getCollectionItemClassName($refProperty);
			if(is_null($itemClassName)) {
				continue;
			}

				$itemList = $this->fetchRelationshipItemReferences(
					$className,
					$ghost,
					$refProperty,
					$itemClassName,
					$primaryKeyValue,
				);
			$refProperty->setRawValueWithoutLazyInitialization($ghost, $itemList);
		}
	}

	/**
	 * @param class-string $ownerClassName
	 * @return array<int, object>
	 */
	private function fetchRelationshipItemReferences(
		string $ownerClassName,
		object $owner,
		ReflectionProperty $refProperty,
		string $itemClassName,
		int|string|null $ownerPrimaryKeyValue = null,
	):array {
		$ownerTableName = $this->getTableName($ownerClassName);
		$ownerPrimaryKey = $this->getPrimaryKey($ownerClassName);
		$itemTableName = $this->getTableName($itemClassName);
		$itemPrimaryKey = $this->getPrimaryKey($itemClassName);
		$junctionTableName = $this->entityInspector->buildJunctionTableName(
			$ownerTableName,
			$refProperty->getName(),
			$itemTableName,
		);
		$ownerJunctionKey = $this->entityInspector->buildJunctionKeyName(
			$ownerTableName,
			$ownerPrimaryKey,
		);
		$itemJunctionKey = $this->entityInspector->buildJunctionKeyName(
			$itemTableName,
			$itemPrimaryKey,
		);
		if(is_null($ownerPrimaryKeyValue)) {
			$ownerPrimaryKeyProperty = new ReflectionProperty($ownerClassName, $ownerPrimaryKey);
			$ownerPrimaryKeyValue = $ownerPrimaryKeyProperty->getRawValue($owner);
		}
		$sql = implode(" ", [
			"select",
			"$itemJunctionKey as $itemPrimaryKey",
			"from",
			$junctionTableName,
			"where",
			"$ownerJunctionKey = :$ownerJunctionKey",
		]);
		$resultSet = $this->database->executeSql($sql, [
			$ownerJunctionKey => $ownerPrimaryKeyValue,
		]);
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

		return $itemList;
	}

	private function isArrayRelationshipProperty(ReflectionProperty $refProperty):bool {
		$refType = $this->getNamedPropertyType($refProperty);
		return !is_null($refType)
			&& $refType->getName() === "array"
			&& !is_null($this->entityInspector->getListItemClassName($refProperty));
	}

	private function isCollectionRelationshipProperty(ReflectionProperty $refProperty):bool {
		$refType = $this->getNamedPropertyType($refProperty);
		if(is_null($refType)) {
			return false;
		}

		$typeName = $refType->getName();
		if(!class_exists($typeName)) {
			return false;
		}

		return is_subclass_of($typeName, \Traversable::class);
	}

	private function getCollectionItemClassName(ReflectionProperty $refProperty):?string {
		$itemClassName = $this->entityInspector->getListItemClassName($refProperty);
		if($itemClassName) {
			return $itemClassName;
		}

		$refType = $this->getNamedPropertyType($refProperty);
		if(is_null($refType)) {
			return null;
		}

		$typeName = $refType->getName();
		if(!class_exists($typeName) || !is_subclass_of($typeName, \Traversable::class)) {
			return null;
		}

		return $this->inferCollectionItemClassName($typeName);
	}

	/** @param ReflectionClass<object> $refClass */
	private function containsArrayRelationship(ReflectionClass $refClass):bool {
		foreach($refClass->getProperties(ReflectionProperty::IS_PUBLIC) as $refProperty) {
			if($this->isArrayRelationshipProperty($refProperty)) {
				return true;
			}
		}

		return false;
	}

	private function assignPropertyValue(
		object $instance,
		ReflectionProperty $refProperty,
		mixed $value,
		bool $setRawValue,
	):void {
		if($setRawValue) {
			$refProperty->setRawValueWithoutLazyInitialization($instance, $value);
			return;
		}

		$refProperty->setValue($instance, $value);
	}

	/** @return array<string, null|int|string|float|bool> */
	private function getInsertData(object $entity):array {
		$insertData = [];
		$refClass = new ReflectionClass($entity);

		foreach($refClass->getProperties(ReflectionProperty::IS_PUBLIC) as $refProperty) {
			$refType = $this->getNamedPropertyType($refProperty);
			if(is_null($refType) || !$refProperty->isInitialized($entity)) {
				continue;
			}

			if($this->isArrayRelationshipProperty($refProperty)
				|| $this->isCollectionRelationshipProperty($refProperty)
			) {
				continue;
			}

			$propertyName = $refProperty->getName();
			$value = $refProperty->getValue($entity);
			if($refType->isBuiltin()) {
				$insertData[$propertyName] = $value;
				continue;
			}

			$typeName = $refType->getName();
			if($value instanceof DateTimeInterface) {
				$insertData[$propertyName] = $value->format(DateTimeInterface::ATOM);
				continue;
			}

			if($value instanceof Stringable) {
				$insertData[$propertyName] = (string)$value;
				continue;
			}

			$foreignPrimaryKey = $this->getPrimaryKey($typeName);
			$foreignTableName = $this->getTableName($typeName);
			$columnName = $this->buildForeignKey(
				$propertyName,
				$foreignTableName,
				$foreignPrimaryKey,
			);
			$foreignPrimaryKeyProperty = new ReflectionProperty($typeName, $foreignPrimaryKey);
			$insertData[$columnName] = is_null($value)
				? null
				: $foreignPrimaryKeyProperty->getValue($value);
		}

		return $insertData;
	}
}
