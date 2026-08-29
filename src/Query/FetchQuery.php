<?php
namespace GT\Orm\Query;

use GT\SqlBuilder\Condition\Condition;
use GT\SqlBuilder\SelectBuilder;
class FetchQuery extends SelectBuilder {
	/** @var array<string, int|string> */
	private array $parameters = [];
	private int|string|null $cacheKey = null;

	/**
	 * @param int|string|Condition ...$match
	 */
	public function match(
		string $primaryKey,
		int|string|Condition... $match,
	):self {
		$queryMatch = new QueryMatch(
			$primaryKey,
			...$match,
		);
		$this->parameters = $queryMatch->getParameters();
		$this->cacheKey = $queryMatch->getCacheKey();
		$this->where(...$queryMatch->getConditionList());
		return $this;
	}

	public function where(string|Condition... $conditionList):static {
		return parent::__call("where", $conditionList);
	}

	/** @return array<string, int|string> */
	public function getParameters():array {
		return $this->parameters;
	}

	public function getCacheKey():int|string|null {
		return $this->cacheKey;
	}
}
