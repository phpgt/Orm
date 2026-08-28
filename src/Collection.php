<?php
namespace GT\Orm;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @template TKey of array-key
 * @template TValue of Entity
 * @implements ArrayAccess<TKey, TValue>
 * @implements IteratorAggregate<TKey, TValue>
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate {
	/**
	 * @param array<TKey, TValue> $itemList
	 */
	public function __construct(
		protected array $itemList = [],
	) {}

	public function count():int {
		return count($this->itemList);
	}

	/** @return Traversable<TKey, TValue> */
	public function getIterator():Traversable {
		return new ArrayIterator($this->itemList);
	}

	/** @param TKey $offset */
	public function offsetExists(mixed $offset):bool {
		return isset($this->itemList[$offset]);
	}

	/**
	 * @param TKey $offset
	 * @return TValue
	 */
	public function offsetGet(mixed $offset):mixed {
		return $this->itemList[$offset];
	}

	/**
	 * @param ?TKey $offset
	 * @param TValue $value
	 */
	public function offsetSet(mixed $offset, mixed $value):void {
		if($offset === null) {
			$this->itemList[] = $value;
		}
		else {
			$this->itemList[$offset] = $value;
		}
	}

	/** @param TKey $offset */
	public function offsetUnset(mixed $offset):void {
		unset($this->itemList[$offset]);
	}
}
