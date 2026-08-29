<?php
namespace GT\Orm\Persistence;

use GT\Database\Database;
use Throwable;

class TransactionRunner {
	public function __construct(
		private readonly Database $database,
	) {}

	/**
	 * @template T
	 * @param callable():T $operation
	 * @return T
	 */
	public function run(callable $operation):mixed {
		$connection = $this->database->getDriver()->getConnection();
		$ownsTransaction = !$connection->inTransaction();
		if($ownsTransaction) {
			$connection->beginTransaction();
		}

		try {
			$result = $operation();
			if($ownsTransaction) {
				$connection->commit();
			}
			return $result;
		}
		catch(Throwable $throwable) {
			if($ownsTransaction) {
				$connection->rollBack();
			}
			throw $throwable;
		}
	}
}
