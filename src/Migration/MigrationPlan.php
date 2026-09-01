<?php
namespace GT\Orm\Migration;

readonly class MigrationPlan {
	/** @param array<string> $sqlList */
	public function __construct(
		public ?string $previousHash,
		public Schema $schema,
		public SchemaDiff $diff,
		private array $sqlList,
	) {}

	public function isEmpty():bool {
		return $this->diff->isEmpty();
	}

	public function isExecutable():bool {
		return $this->diff->isSafe();
	}

	/** @return array<string> */
	public function getSqlList():array {
		return $this->sqlList;
	}
}
