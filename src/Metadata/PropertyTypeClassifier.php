<?php
namespace GT\Orm\Metadata;

use BackedEnum;
use DateTimeInterface;
use GT\Orm\Entity;
use GT\Orm\Exception\InvalidEntityPropertyException;
use ReflectionNamedType;
use ReflectionProperty;
use Traversable;

class PropertyTypeClassifier {
	/** @param class-string $className */
	public function classify(
		string $className,
		ReflectionProperty $property,
		ReflectionNamedType $type,
	):PropertyKind {
		$typeName = $type->getName();
		if($type->isBuiltin()) {
			return $this->classifyBuiltin($className, $property, $typeName);
		}

		return $this->classifyClass($className, $property, $typeName);
	}

	/** @param class-string $className */
	private function classifyBuiltin(
		string $className,
		ReflectionProperty $property,
		string $typeName,
	):PropertyKind {
		if(in_array($typeName, ["bool", "float", "int", "string"], true)) {
			return PropertyKind::SCALAR;
		}

		throw new InvalidEntityPropertyException(
			"Entity property $className::\${$property->getName()} has unsupported type $typeName",
		);
	}

	/** @param class-string $className */
	private function classifyClass(
		string $className,
		ReflectionProperty $property,
		string $typeName,
	):PropertyKind {
		$kind = match(true) {
			is_a($typeName, DateTimeInterface::class, true) => PropertyKind::DATE_TIME,
			is_a($typeName, BackedEnum::class, true) => PropertyKind::BACKED_ENUM,
			is_a($typeName, Traversable::class, true) => PropertyKind::COLLECTION,
			is_a($typeName, Entity::class, true) => PropertyKind::ENTITY,
			default => null,
		};
		if($kind !== null) {
			return $kind;
		}

		throw new InvalidEntityPropertyException(
			"Entity property $className::\${$property->getName()} must be a supported value type, Entity, or Traversable collection",
		);
	}
}
