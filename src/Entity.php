<?php
namespace GT\Orm;

use GT\Orm\Exception\InvalidEntityChangeException;
use GT\Orm\Metadata\EntityMetadataFactory;

abstract readonly class Entity {
	/**
	 * Return an immutable copy containing the supplied property changes.
	 *
	 * @param array<string, mixed> $changeList
	 */
	final public function with(
		array $changeList,
		bool $allowPrimaryKey = false,
	):static {
		$primaryKey = (new EntityMetadataFactory())
			->get($this)
			->getPrimaryKey();
		if(!$allowPrimaryKey
			&& $primaryKey !== null
			&& array_key_exists($primaryKey->getName(), $changeList)
			&& (!$primaryKey->getProperty()->isInitialized($this)
				|| $primaryKey->getProperty()->getValue($this)
				!== $changeList[$primaryKey->getName()])) {
			$className = $this::class;
			$propertyName = $primaryKey->getName();
			throw new InvalidEntityChangeException(
				"Primary key $className::\$$propertyName cannot be changed",
			);
		}

		return clone($this, $changeList);
	}
}
