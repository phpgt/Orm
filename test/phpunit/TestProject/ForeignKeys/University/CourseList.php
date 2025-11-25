<?php
namespace Gt\Orm\Test\TestProject\ForeignKeys\University;

use ArrayAccess;
use ArrayIterator;
use ArrayObject;
use IteratorAggregate;
use Traversable;

/**
 * @implements ArrayAccess<Course>
 * @implements IteratorAggregate<Course>
 */
class CourseList implements ArrayAccess, IteratorAggregate {
	public function __construct(private readonly array $courseArray) {}

	public function getIterator():Traversable {
		return new ArrayIterator($this->courseArray);
	}

	public function offsetExists(mixed $offset):bool {
		return isset($this->courseArray[$offset]);
	}

	public function offsetGet(mixed $offset):mixed {
		return $this->courseArray[$offset];
	}

	public function offsetSet(mixed $offset, mixed $value):void {}

	public function offsetUnset(mixed $offset):void {}
}
