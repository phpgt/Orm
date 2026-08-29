<?php
namespace GT\Orm\Query;

use GT\SqlBuilder\Condition\Condition;
use InvalidArgumentException;

class QueryMatch {
	/** @var array<Condition|string> */
	private array $conditionList;
	/** @var array<string, int|string> */
	private array $parameters;
	private int|string|null $cacheKey;

	/** @param int|string|Condition ...$match */
	public function __construct(
		string $primaryKey,
		int|string|Condition... $match,
	) {
		[$this->conditionList, $this->parameters, $this->cacheKey] = $this->build(
			$primaryKey,
			...$match,
		);
	}

	/** @return array<Condition|string> */
	public function getConditionList():array {
		return $this->conditionList;
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
	private function build(
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
				"Matching must be a primary key, field/value pair, or Condition list",
			);
		}

		return [array_values($conditionList), [], null];
	}
}
