<?php
namespace Gt\Orm\Migration;

use Gt\Orm\Entity;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;

class SchemaGenerator {
	public function generate(object|string $class):SchemaTable {
		$className = is_object($class) ? $class::class : $class;
		$refClass = new ReflectionClass($className);
		$tableName = $this->getTableName($className);
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
				$fieldList[$paramName] = new SchemaField($paramName);
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

	private function getTableName(string $className):string {
		$classNameOffset = strrpos($className, "\\");

		if($classNameOffset) {
			$classNameOffset += 1;
		}
		else {
			$classNameOffset = 0;
		}

		return substr($className, $classNameOffset);
	}

	/**
	 * @param ReflectionClass<Entity> $refClass
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
			$field = new SchemaField($propertyName);

			if($propertyName === "id") {
				$table->setPrimaryKey($field);
			}

			$refType = $refProperty->getType();
			if($refType instanceof ReflectionNamedType) {
				$typeName = $refType->getName();
				$field->setType($typeName);
			}
			$field->setNullable($refType->allowsNull());

			if($refProperty->hasDefaultValue()) {
				$field->setDefaultValue($refProperty->getDefaultValue());
			}
			elseif(is_object($class)) {
// TODO: Unit test this section - I'm not sure it needs to be here, and whether
// we even need the $class variable at all.
				$value = $refProperty->getValue($class);
				$field->setDefaultValue($value);
			}

			$fieldList[$propertyName] = $field;
		}

		return $fieldList;
	}
}
