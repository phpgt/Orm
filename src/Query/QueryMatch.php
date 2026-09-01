<?php
namespace GT\Orm\Query;

use GT\SqlBuilder\Condition\Condition;
use InvalidArgumentException;

class QueryMatch {
	/** @var array<Condition|string> */
	private array $conditionList;
	/** @var array<string, bool|int|string|null> */
	private array $parameters;
	private int|string|null $cacheKey;

	/** @param bool|int|string|array<string, bool|int|string|null>|Condition ...$match */
	public function __construct(
		string $primaryKey,
		bool|int|string|array|Condition... $match,
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

	/** @return array<string, bool|int|string|null> */
	public function getParameters():array {
		return $this->parameters;
	}

	public function getCacheKey():int|string|null {
		return $this->cacheKey;
	}

	/**
	 * @param bool|int|string|array<string, bool|int|string|null>|Condition ...$match
	 * @return array{array<Condition|string>, array<string, bool|int|string|null>, int|string|null}
	 */
	private function build(
		string $primaryKey,
		bool|int|string|array|Condition... $match,
	):array {
		$primaryKeyMatch = $this->primaryKeyMatch($match);
		if($primaryKeyMatch !== null) {
			$value = $primaryKeyMatch[0];
			return [
				["$primaryKey = :$primaryKey"],
				[$primaryKey => $value],
				$value,
			];
		}

		$fieldValueMatch = $this->fieldValueMatch($match);
		if($fieldValueMatch !== null) {
			[$fieldName, $value] = $fieldValueMatch;
			return [
				["$fieldName = :$fieldName"],
				[$fieldName => $this->normaliseValue($value)],
				null,
			];
		}

		return $this->buildConditionList($match);
	}

	/**
	 * @param array<bool|int|string|array<string, bool|int|string|null>|Condition> $match
	 * @return null|array{int|string}
	 */
	private function primaryKeyMatch(array $match):?array {
		if(count($match) !== 1
			|| (!is_int($match[0]) && !is_string($match[0]))) {
			return null;
		}

		return [$match[0]];
	}

	/**
	 * @param array<bool|int|string|array<string, bool|int|string|null>|Condition> $match
	 * @return null|array{string, bool|int|string}
	 */
	private function fieldValueMatch(array $match):?array {
		if(count($match) !== 2
			|| !is_string($match[0])
			|| (!is_bool($match[1])
				&& !is_int($match[1])
				&& !is_string($match[1]))) {
			return null;
		}

		return [$match[0], $match[1]];
	}

	/**
	 * @param array<bool|int|string|array<string, bool|int|string|null>|Condition> $match
	 * @return array{array<Condition|string>, array<string, bool|int|string|null>, null}
	 */
	private function buildConditionList(array $match):array {
		$conditionList = [];
		$parameters = [];
		foreach($match as $condition) {
			if($condition instanceof Condition) {
				$conditionList[] = $condition;
				continue;
			}
			if(!is_array($condition)) {
				throw new InvalidArgumentException(
					"Matching must be a primary key, field/value pair, associative array, or Condition list",
				);
			}

			$this->appendArrayConditions($condition, $conditionList, $parameters);
		}

		return [$conditionList, $parameters, null];
	}

	/**
	 * @param array<string, bool|int|string|null> $match
	 * @param array<Condition|string> $conditionList
	 * @param array<string, bool|int|string|null> $parameters
	 */
	private function appendArrayConditions(
		array $match,
		array &$conditionList,
		array &$parameters,
	):void {
		foreach($match as $fieldName => $value) {
			if($value === null) {
				$conditionList[] = "$fieldName is null";
				continue;
			}
			if(array_key_exists($fieldName, $parameters)) {
				throw new InvalidArgumentException(
					"Matching field $fieldName is repeated",
				);
			}

			$conditionList[] = "$fieldName = :$fieldName";
			$parameters[$fieldName] = $this->normaliseValue($value);
		}
	}

	private function normaliseValue(bool|int|string $value):int|string {
		return is_bool($value) ? (int)$value : $value;
	}
}
