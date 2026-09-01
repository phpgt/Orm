<?php
namespace GT\Orm\Migration;

use GT\Database\Database;
use GT\Orm\Migration\Exception\UnsafeMigrationException;
use GT\Orm\Migration\Query\SchemaDiffSql;
use GT\Orm\Migration\Query\SchemaDiffSqlFactory;
use GT\Orm\Persistence\TransactionRunner;

class OrmMigrator {
	private SchemaGenerator $schemaGenerator;
	private OrmSchemaStore $schemaStore;
	private SchemaDiffer $schemaDiffer;
	private SchemaDiffSql $sqlGenerator;
	private TransactionRunner $transactionRunner;

	public function __construct(
		private readonly Database $database,
		?SchemaGenerator $schemaGenerator = null,
		?OrmSchemaStore $schemaStore = null,
		?SchemaDiffer $schemaDiffer = null,
		?SchemaDiffSql $sqlGenerator = null,
		?TransactionRunner $transactionRunner = null,
	) {
		$this->schemaGenerator = $schemaGenerator ?? new SchemaGenerator();
		$this->schemaStore = $schemaStore ?? new OrmSchemaStore($database);
		$this->schemaDiffer = $schemaDiffer ?? new SchemaDiffer();
		$this->sqlGenerator = $sqlGenerator
			?? (new SchemaDiffSqlFactory())->create($database);
		$this->transactionRunner = $transactionRunner ?? new TransactionRunner($database);
	}

	/** @param object|class-string ...$entityList */
	public function plan(object|string ...$entityList):MigrationPlan {
		$record = $this->schemaStore->latest();
		$before = $record === null ? new Schema() : $record->schema;
		$after = $this->schemaGenerator->generateSchema(...$entityList);
		$diff = $this->schemaDiffer->diff($before, $after);
		$sqlList = $diff->isSafe()
			? $this->sqlGenerator->generateSqlList($diff)
			: [];
		return new MigrationPlan(
			$record?->schemaHash,
			$after,
			$diff,
			$sqlList,
		);
	}

	public function apply(MigrationPlan $plan):int {
		if($plan->isEmpty()) {
			return 0;
		}
		if(!$plan->isExecutable()) {
			throw new UnsafeMigrationException(
				"ORM migration contains changes that require explicit data handling",
			);
		}

		$this->schemaStore->requireCurrent($plan->previousHash);

		return $this->transactionRunner->run(function() use ($plan):int {
			foreach($plan->getSqlList() as $sql) {
				$this->database->executeSql($sql);
			}
			$this->schemaStore->append($plan->schema, $plan->previousHash);
			return count($plan->getSqlList());
		});
	}

	/** @param object|class-string ...$entityList */
	public function migrate(object|string ...$entityList):int {
		return $this->apply($this->plan(...$entityList));
	}

	/** @param object|class-string ...$entityList */
	public function baseline(object|string ...$entityList):OrmSchemaRecord {
		return $this->schemaStore->baseline(
			$this->schemaGenerator->generateSchema(...$entityList),
		);
	}
}
