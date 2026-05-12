<?php
namespace GT\Orm\Test\TestProject\ForeignKeys\University;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * @implements ArrayAccess<int, Course>
 * @implements IteratorAggregate<int, Course>
 */
class CourseList implements ArrayAccess, IteratorAggregate {
	/** @param array<int, Course> $courseArray */
	public function __construct(private readonly array $courseArray) {}

	/** @return Traversable<int, Course> */
	public function getIterator():Traversable {
		return new ArrayIterator($this->courseArray);
	}

	public function offsetExists(mixed $offset):bool {
		return isset($this->courseArray[$offset]);
	}

	public function offsetGet(mixed $offset):Course {
		return $this->courseArray[$offset];
	}

	public function offsetSet(mixed $offset, mixed $value):void {}

	public function offsetUnset(mixed $offset):void {}
}
