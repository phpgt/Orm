<?php
namespace GT\Orm\Metadata;

use GT\Orm\Attribute\AutoIncrementPrimaryKey;
use GT\Orm\Attribute\DefaultValue;
use GT\Orm\Attribute\PrimaryKey;
use GT\Orm\Exception\InvalidAutoIncrementException;
use GT\Orm\Exception\InvalidEntityPropertyException;
use GT\Orm\Exception\InvalidPrimaryKeyException;
use ReflectionClass;
use ReflectionProperty;

class EntityMetadataFactory {
	/** @var array<class-string, EntityMetadata> */
	private array $cache = [];

	public function __construct(
		private readonly PropertyMetadataFactory $propertyFactory = new PropertyMetadataFactory(),
	) {}

	public function get(object|string $entity):EntityMetadata {
		$className = is_object($entity) ? $entity::class : $entity;
		if(isset($this->cache[$className])) {
			return $this->cache[$className];
		}

		$metadata = $this->create($className);
		$this->cache[$className] = $metadata;
		return $metadata;
	}

	/** @param class-string $className */
	private function create(string $className):EntityMetadata {
		$refClass = new ReflectionClass($className);
		$allPropertyList = $refClass->getProperties();
		$this->validateAttributedProperties($allPropertyList);
		$propertyList = array_values(array_filter(
			$allPropertyList,
			fn(ReflectionProperty $property) => $this->isPersistent($property),
		));
		$primaryKeyName = $this->findPrimaryKeyName($className, $propertyList);
		$metadataList = [];
		$primaryKey = null;

		foreach($propertyList as $property) {
			$metadata = $this->propertyFactory->create(
				$className,
				$property,
				$property->getName() === $primaryKeyName,
			);
			$metadataList[] = $metadata;
			if($metadata->isPrimaryKey()) {
				$primaryKey = $metadata;
			}
		}

		return new EntityMetadata(
			$className,
			$refClass->getShortName(),
			$metadataList,
			$primaryKey,
		);
	}

	private function isPersistent(ReflectionProperty $property):bool {
		return $property->isPublic() && !$property->isStatic();
	}

	/** @param array<ReflectionProperty> $propertyList */
	private function validateAttributedProperties(array $propertyList):void {
		foreach($propertyList as $property) {
			if($this->hasMappingAttribute($property)
				&& !$this->isPersistent($property)) {
				throw new InvalidEntityPropertyException(
					"Mapped property {$property->getDeclaringClass()->getName()}::\${$property->getName()} must be public and non-static",
				);
			}
		}
	}

	private function hasMappingAttribute(ReflectionProperty $property):bool {
		return $property->getAttributes(PrimaryKey::class) !== []
			|| $property->getAttributes(AutoIncrementPrimaryKey::class) !== []
			|| $property->getAttributes(DefaultValue::class) !== [];
	}

	/**
	 * @param class-string $className
	 * @param array<ReflectionProperty> $propertyList
	 */
	private function findPrimaryKeyName(
		string $className,
		array $propertyList,
	):?string {
		foreach($propertyList as $property) {
			if(count($property->getAttributes(PrimaryKey::class)) > 1) {
				throw new InvalidPrimaryKeyException(
					"PrimaryKey cannot be repeated on $className::\${$property->getName()}",
				);
			}
			if(count($property->getAttributes(AutoIncrementPrimaryKey::class)) > 1) {
				throw new InvalidAutoIncrementException(
					"AutoIncrementPrimaryKey cannot be repeated on $className::\${$property->getName()}",
				);
			}
		}

		$explicitPrimaryKeyList = array_values(array_filter(
			$propertyList,
			fn(ReflectionProperty $property) => $property->getAttributes(PrimaryKey::class) !== []
				|| $property->getAttributes(AutoIncrementPrimaryKey::class) !== [],
		));

		if(count($explicitPrimaryKeyList) > 1) {
			throw new InvalidPrimaryKeyException(
				"Entity $className defines more than one primary key",
			);
		}

		if($explicitPrimaryKeyList) {
			return $explicitPrimaryKeyList[0]->getName();
		}

		$firstProperty = $propertyList[0] ?? null;
		return $firstProperty?->getName() === "id" ? "id" : null;
	}
}
