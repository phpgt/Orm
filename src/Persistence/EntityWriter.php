<?php
namespace GT\Orm\Persistence;

use GT\Database\Database;
use GT\Orm\Entity;
use GT\Orm\Metadata\ColumnName;
use GT\Orm\Metadata\EntityMetadataFactory;

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
	 * @param array<string, mixed> $changeList
	 * @return T
	 */
	public function update(Entity $entity, array $changeList):Entity {
		return $this->updater->update($entity, $changeList);
	}
}
