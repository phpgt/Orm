<?php
namespace GT\Orm\Persistence;

use GT\Database\Database;
use GT\Orm\Entity;
use GT\Orm\Metadata\ColumnName;
use GT\Orm\Metadata\EntityMetadataFactory;
use GT\SqlBuilder\Condition\Condition;
use InvalidArgumentException;

class EntityWriter {
	private EntityInserter $inserter;
	private EntityUpdater $updater;

	public function __construct(
		Database $database,
		EntityMetadataFactory $metadataFactory,
		ColumnName $columnName,
	) {
		$valueMapper = new EntityValueMapper($metadataFactory);
		$transactionRunner = new TransactionRunner($database);
		$this->inserter = new EntityInserter(
			$database,
			$metadataFactory,
			$columnName,
			$valueMapper,
			$transactionRunner,
		);
		$this->updater = new EntityUpdater(
			$database,
			$metadataFactory,
			$columnName,
			$valueMapper,
			$transactionRunner,
		);
	}

	/**
	 * @template T of Entity
	 * @param T $entity
	 * @return T
	 */
	public function insert(Entity $entity):Entity {
		return $this->inserter->insert($entity);
	}

	/**
	 * @template T of Entity
	 * @param T $entity
	 * @return T
	 */
	public function update(Entity $entity):Entity {
		return $this->updater->update($entity);
	}

	/**
	 * @param class-string<Entity> $className
	 * @param array<bool|int|string|array<string, mixed>|Condition> $arguments
	 */
	public function updateMatching(
		string $className,
		array $arguments,
	):int {
		[$changeList, $match] = $this->splitUpdateArguments($arguments);
		return $this->updater->updateMatching(
			$className,
			$changeList,
			$match,
		);
	}

	/**
	 * @param array<bool|int|string|array<string, mixed>|Condition> $arguments
	 * @return array{array<string, mixed>, array<bool|int|string|Condition>}
	 */
	private function splitUpdateArguments(array $arguments):array {
		$changeList = null;
		$match = [];
		foreach($arguments as $argument) {
			if(!is_array($argument)) {
				$match[] = $argument;
				continue;
			}
			if($changeList !== null) {
				throw new InvalidArgumentException(
					"Update accepts exactly one property change array",
				);
			}

			$changeList = $argument;
		}

		if($changeList === null || $changeList === []) {
			throw new InvalidArgumentException(
				"Update requires a non-empty property change array",
			);
		}
		if($match === []) {
			throw new InvalidArgumentException(
				"Update requires at least one match condition",
			);
		}

		return [$changeList, $match];
	}
}
