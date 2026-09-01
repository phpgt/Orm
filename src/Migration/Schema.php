<?php
namespace GT\Orm\Migration;

use LogicException;

readonly class Schema {
	/** @var array<string, SchemaTable> */
	private array $tableList;

	public function __construct(SchemaTable... $tableList) {
		$indexedTableList = [];
		foreach($tableList as $table) {
			$name = $table->getName();
			if(isset($indexedTableList[$name])) {
				throw new LogicException("Schema already contains table $name");
			}
			$indexedTableList[$name] = $table;
		}
		$this->tableList = $indexedTableList;
	}

	/** @return array<string, SchemaTable> */
	public function getTableList():array {
		return $this->tableList;
	}

	public function getTable(string $name):?SchemaTable {
		return $this->tableList[$name] ?? null;
	}

	public function equals(self $other):bool {
		return (new SchemaSerializer())->serialize($this)
			=== (new SchemaSerializer())->serialize($other);
	}
}
