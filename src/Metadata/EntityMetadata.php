<?php
namespace GT\Orm\Metadata;

use GT\Orm\Exception\InvalidPrimaryKeyException;

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
}
