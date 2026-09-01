<?php
namespace GT\Orm\Migration;

readonly class SchemaDiff {
	/** @param array<SchemaChange> $changeList */
	public function __construct(
		private array $changeList,
	) {}

	/** @return array<SchemaChange> */
	public function getChangeList():array {
		return $this->changeList;
	}

	public function isEmpty():bool {
		return $this->changeList === [];
	}

	public function isSafe():bool {
		foreach($this->changeList as $change) {
			if($change->safety() !== SchemaChangeSafety::SAFE) {
				return false;
			}
		}
		return true;
	}
}
