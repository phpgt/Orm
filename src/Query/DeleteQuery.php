<?php
namespace GT\Orm\Query;

use GT\SqlBuilder\Condition\Condition;
use GT\SqlBuilder\DeleteBuilder;
use InvalidArgumentException;

class DeleteQuery extends DeleteBuilder {
	/** @var array<string, bool|int|string|null> */
	private array $parameters = [];

	/** @param bool|int|string|array<string, bool|int|string|null>|Condition ...$match */
	public function match(
		string $primaryKey,
		bool|int|string|array|Condition... $match,
	):self {
		$queryMatch = new QueryMatch($primaryKey, ...$match);
		$conditionList = $queryMatch->getConditionList();
		$this->requireCondition($conditionList);
		$this->parameters = $queryMatch->getParameters();
		$this->where(...$conditionList);
		return $this;
	}

	/** @return array<string, bool|int|string|null> */
	public function getParameters():array {
		return $this->parameters;
	}

	/** @param array<Condition|string> $conditionList */
	private function requireCondition(array $conditionList):void {
		foreach($conditionList as $condition) {
			$value = $condition instanceof Condition
				? $condition->getCondition()
				: $condition;
			if(trim($value) !== "") {
				return;
			}
		}

		throw new InvalidArgumentException(
			"Delete requires at least one non-empty match condition",
		);
	}
}
