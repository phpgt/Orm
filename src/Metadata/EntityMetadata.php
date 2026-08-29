<?php
namespace GT\Orm\Metadata;

use GT\Orm\Exception\InvalidPrimaryKeyException;
use ReflectionClass;

readonly class EntityMetadata {
	/**
	 * @param class-string $className
	 * @param array<PropertyMetadata> $propertyList
	 */
	public function __construct(
		private string $className,
		private string $tableName,
		private array $propertyList,
		private ?PropertyMetadata $primaryKey,
	) {}

	/** @return class-string */
	public function getClassName():string {
		return $this->className;
	}

	public function getTableName():string {
		return $this->tableName;
	}

	/** @return array<PropertyMetadata> */
	public function getPropertyList():array {
		return $this->propertyList;
	}

	public function getPrimaryKey():?PropertyMetadata {
		return $this->primaryKey;
	}

	public function requirePrimaryKey():PropertyMetadata {
		if($this->primaryKey === null) {
			throw new InvalidPrimaryKeyException(
				"Entity {$this->className} does not define a primary key",
			);
		}

		return $this->primaryKey;
	}

	/**
	 * @template T of object
	 * @param T $source
	 * @param array<string> $skippedPropertyList
	 * @return T
	 */
	public function copyWithoutProperties(
		object $source,
		array $skippedPropertyList,
	):object {
		$refClass = new ReflectionClass($source);
		$copy = $refClass->newInstanceWithoutConstructor();
		foreach($refClass->getProperties() as $property) {
			if($property->isStatic()
				|| in_array($property->getName(), $skippedPropertyList, true)
				|| !$property->isInitialized($source)) {
				continue;
			}

			$property->setValue($copy, $property->getValue($source));
		}

		return $copy;
	}
}
