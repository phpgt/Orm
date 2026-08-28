<?php
namespace GT\Orm\Metadata;

use BackedEnum;
use DateTimeInterface;
use GT\Orm\Collection;
use GT\Orm\Entity;
use GT\Orm\Exception\InvalidCollectionException;
use GT\Orm\Exception\InvalidEntityPropertyException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

class PropertyTypeClassifier {
	public function __construct(
		private readonly PhpDocClassNameResolver $classNameResolver = new PhpDocClassNameResolver(),
	) {}

	/**
	 * @param class-string<Collection<array-key, Entity>> $collectionClassName
	 * @return class-string<Entity>
	 */
	public function collectionItemClass(string $collectionClassName):string {
		$refClass = new ReflectionClass($collectionClassName);
		$docComment = $refClass->getDocComment();
		if($docComment === false
			|| !preg_match(
				'/@extends\s+[^\s<]+<\s*[^,>]+\s*,\s*([\\\\a-zA-Z_][\\\\a-zA-Z0-9_]*)\s*>/',
				$docComment,
				$match,
			)) {
			throw new InvalidCollectionException(
				"Collection $collectionClassName must declare @extends Collection<TKey, Entity>",
			);
		}

		$itemClassName = $this->classNameResolver->resolve($refClass, $match[1]);
		if($itemClassName === null || !is_a($itemClassName, Entity::class, true)) {
			throw new InvalidCollectionException(
				"Collection $collectionClassName item type {$match[1]} must implement " . Entity::class,
			);
		}

		return $itemClassName;
	}

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
			is_a($typeName, Collection::class, true) => PropertyKind::COLLECTION,
			is_a($typeName, Entity::class, true) => PropertyKind::ENTITY,
			default => null,
		};
		if($kind !== null) {
			return $kind;
		}

		throw new InvalidEntityPropertyException(
			"Entity property $className::\${$property->getName()} must be a supported value type, Entity, or Collection",
		);
	}
}
