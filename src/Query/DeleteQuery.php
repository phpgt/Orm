<?php
namespace GT\Orm\Query;

use GT\SqlBuilder\Condition\Condition;
use GT\SqlBuilder\DeleteBuilder;
use InvalidArgumentException;

class DeleteQuery extends DeleteBuilder {
	/** @var array<string, int|string> */
	private array $parameters = [];

	/** @param int|string|Condition ...$match */
	public function match(
		string $primaryKey,
		int|string|Condition... $match,
	):self {
		$queryMatch = new QueryMatch($primaryKey, ...$match);
		$conditionList = $queryMatch->getConditionList();
		$this->requireCondition($conditionList);
		$this->parameters = $queryMatch->getParameters();
		$this->where(...$conditionList);
		return $this;
	}

	/** @return array<string, int|string> */
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
