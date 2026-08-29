<?php
namespace GT\Orm\Query;

use GT\SqlBuilder\Condition\Condition;
use GT\SqlBuilder\SelectBuilder;
use InvalidArgumentException;

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
		[$conditionList, $this->parameters, $this->cacheKey] = $this->buildMatch(
			$primaryKey,
			...$match,
		);
		$this->where(...$conditionList);
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

	/**
	 * @param int|string|Condition ...$match
	 * @return array{array<Condition|string>, array<string, int|string>, int|string|null}
	 */
	private function buildMatch(
		string $primaryKey,
		int|string|Condition... $match,
	):array {
		if(count($match) === 1 && !$match[0] instanceof Condition) {
			$value = $match[0];
			return [
				["$primaryKey = :$primaryKey"],
				[$primaryKey => $value],
				$value,
			];
		}

		if(count($match) === 2
			&& is_string($match[0])
			&& !$match[1] instanceof Condition
		) {
			$fieldName = $match[0];
			return [
				["$fieldName = :$fieldName"],
				[$fieldName => $match[1]],
				null,
			];
		}

		$conditionList = array_filter(
			$match,
			fn(int|string|Condition $condition) => $condition instanceof Condition,
		);
		if(count($conditionList) !== count($match)) {
			throw new InvalidArgumentException(
				"Fetch matching must be a primary key, field/value pair, or Condition list",
			);
		}

		return [array_values($conditionList), [], null];
	}
}
