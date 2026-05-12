<?php
namespace GT\Orm\Migration;

use GT\Orm\Attribute\PrimaryKey;
use GT\Orm\EntityInspector;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;

class SchemaGenerator {
	private EntityInspector $entityInspector;

	public function __construct() {
		$this->entityInspector = new EntityInspector();
	}

	public function generate(object|string $class):SchemaTable {
		$className = is_object($class) ? $class::class : $class;
		$refClass = new ReflectionClass($className);
		$tableName = $this->entityInspector->getTableName($className);
		$table = new SchemaTable($tableName);
		$fieldList = $this->generateFields($class, $refClass, $table);

		$refConstructor = $refClass->getMethod("__construct");
		$promotedParams = array_filter(
			$refConstructor->getParameters(),
			fn($param) => $param->isPromoted()
		);

		foreach($promotedParams as $param) {
			$paramName = $param->getName();
			if(!isset($fieldList[$paramName])) {
				continue;
			}

			$field = $fieldList[$paramName];

			try {
				$default = $param->getDefaultValue();
				$field->setDefaultValue($default);
			} catch(ReflectionException) {
				// Ignore fields without a default value.
			}
		}

		foreach($fieldList as $field) {
			$table->addField($field);
		}

		return $table;
	}

	/**
	 * @param array<object|string> $classList
	 * @return array<int, SchemaTable>
	 */
	public function generateAll(array $classList):array {
		$tableList = [];
		/** @var array<class-string, bool> $generatedClassList */
		$generatedClassList = [];

		foreach($classList as $class) {
			$this->appendTablesForClass($tableList, $generatedClassList, $class);
		}

		return array_values($tableList);
	}

	/**
	 * @param ReflectionClass<object> $refClass
	 * @return array<string, SchemaField>
	 */
	private function generateFields(
		object|string $class,
		ReflectionClass $refClass,
		SchemaTable $table,
	):array {
		$refPublicPropertyList = $refClass->getProperties(
			ReflectionProperty::IS_PUBLIC
		);
		usort(
			$refPublicPropertyList,
			fn(
				ReflectionProperty $a,
				ReflectionProperty $b
			) => $b->isPromoted() && !$a->isPromoted()
				? 1
				: 0
		);

		$fieldList = [];

		foreach($refPublicPropertyList as $refProperty) {
			$propertyName = $refProperty->getName();
			$field = $this->createField($refProperty, $table);
			if(is_null($field)) {
				continue;
			}

			if($refProperty->hasDefaultValue()) {
				$field->setDefaultValue($refProperty->getDefaultValue());
			}
			elseif(is_object($class)) {
// TODO: Unit test this section - I'm not sure it needs to be here, and whether
// we even need the $class variable at all.
				$value = $refProperty->getValue($class);
				if(is_object($value) && $field->isForeignKey()) {
					$primaryKeyName = $this->entityInspector->getPrimaryKeyName($value);
					$value = $value->$primaryKeyName;
				}

				$field->setDefaultValue($value);
			}

			$fieldList[$propertyName] = $field;
		}

		return $fieldList;
	}

	/**
	 * @param array<string, SchemaTable> $tableList
	 * @param array<string, bool> $generatedClassList
	 */
	private function appendTablesForClass(
		array &$tableList,
		array &$generatedClassList,
		object|string $class,
	):void {
		$className = is_object($class) ? $class::class : $class;
		if(isset($generatedClassList[$className])) {
			return;
		}

		$generatedClassList[$className] = true;
		$table = $this->generate($className);
		$tableList[$table->getName()] = $table;

		$refClass = new ReflectionClass($className);
		foreach($refClass->getProperties(ReflectionProperty::IS_PUBLIC) as $refProperty) {
			$relatedClassName = $this->getRelatedClassName($refProperty);
			if($relatedClassName) {
				$this->appendTablesForClass(
					$tableList,
					$generatedClassList,
					$relatedClassName,
				);
			}

			$junctionTable = $this->createJunctionTable($className, $refProperty);
			if($junctionTable) {
				$tableList[$junctionTable->getName()] = $junctionTable;
			}
		}
	}

	private function createField(
		ReflectionProperty $refProperty,
		SchemaTable $table,
	):?SchemaField {
		$propertyName = $refProperty->getName();
		$refType = $refProperty->getType();
		if(!$refType instanceof ReflectionNamedType) {
			return null;
		}

		$listItemClassName = $this->entityInspector->getListItemClassName($refProperty);
		if($listItemClassName) {
			return null;
		}

		$field = new SchemaField($propertyName);
		$field->setNullable($refType->allowsNull());
		$typeName = $refType->getName();

		if(!$refType->isBuiltin()
			&& !$this->entityInspector->isPlainColumnType($typeName)
		) {
			$referencedTableName = $this->entityInspector->getTableName($typeName);
			$referencedPrimaryKey = $this->entityInspector->getPrimaryKeyName($typeName);
			$referencedProperty = $this->entityInspector->getPrimaryKeyProperty($typeName);
			$referencedType = $referencedProperty->getType();
			if($referencedType instanceof ReflectionNamedType) {
				$fieldName = $this->entityInspector->buildForeignKeyName(
					$propertyName,
					$referencedTableName,
					$referencedPrimaryKey,
				);
				$field = new SchemaField($fieldName);
				$field->setType($referencedType->getName());
				$field->setNullable($refType->allowsNull());
				$field->setForeignKeyReference($referencedTableName, $referencedPrimaryKey);
			}
		}
		else {
			$field->setType($typeName);
		}

		if($propertyName === $this->entityInspector->getPrimaryKeyName($refProperty->getDeclaringClass()->getName())) {
			$table->setPrimaryKey($field);
			$primaryKeyDefinition = $this->entityInspector->getPrimaryKeyDefinition(
				$refProperty->getDeclaringClass()->getName(),
			);
			$field->setAutoIncrement($primaryKeyDefinition->autoIncrement);
			$field->setUlid($primaryKeyDefinition->ulid);
		}

		return $field;
	}

	private function createJunctionTable(
		string $ownerClassName,
		ReflectionProperty $refProperty,
	):?SchemaTable {
		$itemClassName = $this->entityInspector->getListItemClassName($refProperty);
		if(!$itemClassName) {
			return null;
		}

		$ownerTableName = $this->entityInspector->getTableName($ownerClassName);
		$itemTableName = $this->entityInspector->getTableName($itemClassName);
		$tableName = $this->entityInspector->buildJunctionTableName(
			$ownerTableName,
			$refProperty->getName(),
			$itemTableName,
		);
		$table = new SchemaTable($tableName);

		$ownerPrimaryKeyName = $this->entityInspector->getPrimaryKeyName($ownerClassName);
		$ownerPrimaryKey = $this->entityInspector->getPrimaryKeyProperty($ownerClassName);
		$ownerType = $ownerPrimaryKey->getType();
		if($ownerType instanceof ReflectionNamedType) {
			$field = new SchemaField(
				$this->entityInspector->buildJunctionKeyName(
					$ownerTableName,
					$ownerPrimaryKeyName,
				),
			);
			$field->setType($ownerType->getName());
			$field->setNullable(false);
			$field->setForeignKeyReference($ownerTableName, $ownerPrimaryKeyName);
			$table->addField($field);
		}

		$itemPrimaryKeyName = $this->entityInspector->getPrimaryKeyName($itemClassName);
		$itemPrimaryKey = $this->entityInspector->getPrimaryKeyProperty($itemClassName);
		$itemType = $itemPrimaryKey->getType();
		if($itemType instanceof ReflectionNamedType) {
			$field = new SchemaField(
				$this->entityInspector->buildJunctionKeyName(
					$itemTableName,
					$itemPrimaryKeyName,
				),
			);
			$field->setType($itemType->getName());
			$field->setNullable(false);
			$field->setForeignKeyReference($itemTableName, $itemPrimaryKeyName);
			$table->addField($field);
		}

		return $table;
	}

	private function getRelatedClassName(ReflectionProperty $refProperty):?string {
		$refType = $refProperty->getType();
		if($refType instanceof ReflectionNamedType
			&& !$refType->isBuiltin()
			&& !$this->entityInspector->isPlainColumnType($refType->getName())
		) {
			return $refType->getName();
		}

		return $this->entityInspector->getListItemClassName($refProperty);
	}
}
