<?php
namespace Gt\Orm\Migration;

use ReflectionClass;
use ReflectionProperty;

class SchemaGenerator {
	public function generate(object|string $class):SchemaTable {
		$className = is_object($class) ? $class::class : $class;

		$refClass = new ReflectionClass($className);
		$propertyList = $refClass->getProperties(ReflectionProperty::IS_PUBLIC);
		usort($propertyList, function(ReflectionProperty $propA, ReflectionProperty $propB):int {
			if($propB->isPromoted() && !$propA->isPromoted()) {
				return 1;
			}
			return 0;
		});

		$classNameOffset = strrpos($className, "\\");
		if($classNameOffset) {
			$classNameOffset += 1;
		}
		else {
			$classNameOffset = 0;
		}
		$tableName = substr($className, $classNameOffset);
		foreach($refClass->getAttributes("TableName") as $attr) {
			$tableName = $attr->getArguments()[0];
		}
		$table = new SchemaTable($tableName);

// TODO: Handle promoted properties here.

		foreach($propertyList as $i => $property) {
			$propertyName = $property->getName();

			$field = new SchemaField($propertyName);
			$table->addField($field);

			if($propertyName === "id") {
// TODO: We shouldn't just blindly use "id" as the PK. We should find the PK before this foreach, so we know in advance which property matches our expectations.
				$table->setPrimaryKey($field);
			}

			$refType = $property->getType();
			$typeName = $refType->getName();

			if(class_exists($typeName)) {
// TODO: Add foreign key here. Change property name to {className}Id, and type accordingly. Maybe we need a ClassReflector::primaryKeyCache<class-name, ReflectionProperty> adding?
			}

			$value = null;
			if(is_object($class)) {
				$value = $property->getValue($class);
			}

			$field->setType($typeName);
			$field->setNullable($refType->allowsNull());
			if($property->hasDefaultValue()) {
				$field->setDefaultValue($property->getDefaultValue());
			}
		}

		return $table;
	}
}
