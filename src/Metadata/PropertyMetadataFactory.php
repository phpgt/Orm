<?php
namespace GT\Orm\Metadata;

use GT\Orm\Attribute\AutoIncrementPrimaryKey;
use GT\Orm\Attribute\DefaultValue;
use GT\Orm\Exception\InvalidAutoIncrementException;
use GT\Orm\Exception\InvalidDefaultValueException;
use GT\Orm\Exception\InvalidEntityPropertyException;
use GT\Orm\Exception\InvalidPrimaryKeyException;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;

class PropertyMetadataFactory {
	public function __construct(
		private readonly PropertyTypeClassifier $typeClassifier = new PropertyTypeClassifier(),
	) {}

	/** @param class-string $className */
	public function create(
		string $className,
		ReflectionProperty $property,
		bool $primaryKey,
	):PropertyMetadata {
		$type = $property->getType();
		if(!$type instanceof ReflectionNamedType) {
			throw new InvalidEntityPropertyException(
				"Entity property $className::\${$property->getName()} must have one named type",
			);
		}

		$kind = $this->typeClassifier->classify($className, $property, $type);
		$collectionItemClassName = $kind === PropertyKind::COLLECTION
			? $this->typeClassifier->collectionItemClass($type->getName())
			: null;
		$autoIncrement = $this->getAutoIncrementPrimaryKey($property);
		$defaultAttribute = $this->getDefaultValue($className, $property);
		$this->validatePrimaryKey($className, $property, $type, $kind, $primaryKey);
		$this->validateAutoIncrement($className, $property, $type, $primaryKey, $autoIncrement);
		$this->validateDefaultValue($className, $property, $type, $kind, $autoIncrement, $defaultAttribute);

		return new PropertyMetadata(
			$property,
			$type,
			$kind,
			$collectionItemClassName,
			$primaryKey,
			$autoIncrement,
			$defaultAttribute !== null,
			$defaultAttribute?->value,
		);
	}

	/** @param class-string $className */
	private function validatePrimaryKey(
		string $className,
		ReflectionProperty $property,
		ReflectionNamedType $type,
		PropertyKind $kind,
		bool $primaryKey,
	):void {
		if(!$primaryKey) {
			return;
		}

		$typeName = $type->getName();
		if($kind !== PropertyKind::SCALAR
			|| !in_array($typeName, ["int", "string"], true)
			|| $type->allowsNull()) {
			throw new InvalidPrimaryKeyException(
				"Primary key $className::\${$property->getName()} must be a non-nullable int or string",
			);
		}
	}

	/** @param class-string $className */
	private function validateAutoIncrement(
		string $className,
		ReflectionProperty $property,
		ReflectionNamedType $type,
		bool $primaryKey,
		bool $autoIncrement,
	):void {
		if(!$autoIncrement) {
			return;
		}

		$propertyName = $property->getName();
		if(!$primaryKey) {
			throw new InvalidAutoIncrementException(
				"Auto-increment property $className::\$$propertyName must be the primary key",
			);
		}
		if($type->getName() !== "int") {
			throw new InvalidAutoIncrementException(
				"Auto-increment property $className::\$$propertyName must have type int",
			);
		}
		if($property->isPromoted() || $property->hasDefaultValue()) {
			throw new InvalidAutoIncrementException(
				"Auto-increment property $className::\$$propertyName must be declared outside the constructor without a PHP default",
			);
		}
	}

	/** @param class-string $className */
	private function validateDefaultValue(
		string $className,
		ReflectionProperty $property,
		ReflectionNamedType $type,
		PropertyKind $kind,
		bool $autoIncrement,
		?DefaultValue $defaultValue,
	):void {
		if($defaultValue === null) {
			return;
		}

		$propertyName = $property->getName();
		if($autoIncrement) {
			throw new InvalidDefaultValueException(
				"Auto-increment property $className::\$$propertyName cannot define a SQL default",
			);
		}
		if($defaultValue->value === null && !$type->allowsNull()) {
			throw new InvalidDefaultValueException(
				"Non-nullable property $className::\$$propertyName cannot default to null",
			);
		}
		if(!$this->defaultMatchesType($type, $kind, $defaultValue->value)) {
			throw new InvalidDefaultValueException(
				"SQL default for $className::\$$propertyName does not match its PHP type",
			);
		}
	}

	private function defaultMatchesType(
		ReflectionNamedType $type,
		PropertyKind $kind,
		bool|int|float|string|null $value,
	):bool {
		if($value === null) {
			return true;
		}
		if($kind === PropertyKind::DATE_TIME) {
			return is_string($value);
		}
		if($kind === PropertyKind::BACKED_ENUM) {
			$backingType = (new ReflectionEnum($type->getName()))
				->getBackingType()
				->getName();
			return $backingType === "int" ? is_int($value) : is_string($value);
		}
		if($kind === PropertyKind::SCALAR) {
			return $this->scalarDefaultMatchesType($type, $value);
		}

		return false;
	}

	private function scalarDefaultMatchesType(
		ReflectionNamedType $type,
		bool|int|float|string $value,
	):bool {
		return match($type->getName()) {
			"bool" => is_bool($value),
			"float" => is_float($value) || is_int($value),
			"int" => is_int($value),
			"string" => is_string($value),
			default => false,
		};
	}

	private function getAutoIncrementPrimaryKey(
		ReflectionProperty $property,
	):bool {
		return $property->getAttributes(AutoIncrementPrimaryKey::class) !== [];
	}

	/** @param class-string $className */
	private function getDefaultValue(
		string $className,
		ReflectionProperty $property,
	):?DefaultValue {
		$attributeList = $property->getAttributes(DefaultValue::class);
		if(count($attributeList) > 1) {
			throw new InvalidDefaultValueException(
				"DefaultValue cannot be repeated on $className::\${$property->getName()}",
			);
		}

		$attribute = $attributeList[0] ?? null;
		return $attribute?->newInstance();
	}
}
