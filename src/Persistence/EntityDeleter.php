<?php
namespace GT\Orm\Persistence;

use GT\Database\Database;
use GT\Orm\Entity;
use GT\Orm\Exception\InvalidEntityStateException;
use GT\Orm\Metadata\EntityMetadataFactory;
use GT\Orm\Query\QueryFactory;
use GT\SqlBuilder\Condition\Condition;
use InvalidArgumentException;

class EntityDeleter {
	public function __construct(
		private readonly Database $database,
		private readonly EntityMetadataFactory $metadataFactory,
		private readonly QueryFactory $queryFactory,
	) {}

	/**
	 * @param Entity|class-string<Entity> $target
	 * @param bool|int|string|array<string, bool|int|string|null>|Condition ...$match
	 */
	public function delete(
		Entity|string $target,
		bool|int|string|array|Condition... $match,
	):int {
		if($target instanceof Entity) {
			return $this->deleteEntity($target, $match);
		}

		$className = $this->metadataFactory->requireEntityClassName($target);
		$primaryKey = $this->metadataFactory->get($className)
			->requirePrimaryKey()
			->getName();
		if(!$this->isSingleEntityMatch($primaryKey, $match)) {
			throw new InvalidArgumentException(
				"Delete may affect multiple entities; use deleteAll() explicitly",
			);
		}

		return $this->deleteMatching($className, $match);
	}

	/**
	 * @param class-string<Entity> $className
	 * @param bool|int|string|array<string, bool|int|string|null>|Condition ...$match
	 */
	public function deleteAll(
		string $className,
		bool|int|string|array|Condition... $match,
	):int {
		$className = $this->metadataFactory->requireEntityClassName($className);
		return $this->deleteMatching($className, $match, true);
	}

	/**
	 * @param array<
	 *     bool|int|string|array<string, bool|int|string|null>|Condition
	 * > $match
	 */
	private function deleteEntity(Entity $entity, array $match):int {
		if($match !== []) {
			throw new InvalidArgumentException(
				"Entity deletes do not accept additional matching arguments",
			);
		}

		$className = $entity::class;
		$primaryKey = $this->metadataFactory->get($entity)->requirePrimaryKey();
		$property = $primaryKey->getProperty();
		if(!$property->isInitialized($entity)) {
			$propertyName = $primaryKey->getName();
			throw new InvalidEntityStateException(
				"Entity property $className::\$$propertyName is not initialised",
			);
		}

		$value = $property->getValue($entity);
		if(!is_int($value) && !is_string($value)) {
			throw new InvalidEntityStateException(
				"Entity $className does not have a valid primary key",
			);
		}

		return $this->deleteMatching($className, [$value]);
	}

	/**
	 * @param class-string<Entity> $className
	 * @param array<bool|int|string|array<string, bool|int|string|null>|Condition> $match
	 */
	private function deleteMatching(
		string $className,
		array $match,
		bool $allowEmpty = false,
	):int {
		$metadata = $this->metadataFactory->get($className);
		$query = $this->queryFactory->delete();
		$query->from($metadata->getTableName());
		if($match !== []) {
			$query->match($metadata->requirePrimaryKey()->getName(), ...$match);
		}
		elseif(!$allowEmpty) {
			throw new InvalidArgumentException(
				"Delete requires at least one match condition",
			);
		}

		return $this->database->executeSql(
			(string)$query,
			$query->getParameters(),
		)->affectedRows();
	}

	/**
	 * @param array<bool|int|string|array<string, bool|int|string|null>|Condition> $match
	 */
	private function isSingleEntityMatch(
		string $primaryKey,
		array $match,
	):bool {
		if(count($match) === 1) {
			return $this->isSingleArgumentMatch($primaryKey, $match[0]);
		}

		return count($match) === 2
			&& $match[0] === $primaryKey
			&& (is_bool($match[1])
				|| is_int($match[1])
				|| is_string($match[1]));
	}

	/** @param bool|int|string|array<string, bool|int|string|null>|Condition $match */
	private function isSingleArgumentMatch(
		string $primaryKey,
		bool|int|string|array|Condition $match,
	):bool {
		return is_int($match)
			|| is_string($match)
			|| (is_array($match)
				&& count($match) === 1
				&& array_key_exists($primaryKey, $match));
	}
}
