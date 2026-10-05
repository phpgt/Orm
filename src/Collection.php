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
 * @implements ArrayAccess<int|TKey, TValue>
 * @implements IteratorAggregate<int|TKey, TValue>
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate {
	/**
	 * @param array<int|TKey, TValue> $itemList
	 */
	public function __construct(
		protected array $itemList = [],
	) {}

	public function count():int {
		return count($this->itemList);
	}

	/** @return ArrayIterator<int|TKey, TValue> */
	public function getIterator():Traversable {
		return new ArrayIterator($this->itemList);
	}

	/** @param int|TKey $offset */
	public function offsetExists(mixed $offset):bool {
		return isset($this->itemList[$offset]);
	}

	/**
	 * @param int|TKey $offset
	 * @return TValue
	 */
	public function offsetGet(mixed $offset):mixed {
		return $this->itemList[$offset];
	}

	/**
	 * @param int|TKey|null $offset
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

	/** @param int|TKey $offset */
	public function offsetUnset(mixed $offset):void {
		unset($this->itemList[$offset]);
	}
}
