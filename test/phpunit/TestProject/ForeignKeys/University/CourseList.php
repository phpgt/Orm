<?php
namespace Gt\Orm\Test\TestProject\ForeignKeys\University;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<Course> */
readonly class CourseList implements IteratorAggregate {
	public function __construct(
		/** @var array<Course> */
		private array $courseArray = [],
	) {
		var_dump($this->courseArray);
	}

	public function getIterator():Traversable {
		return new ArrayIterator($this->courseArray);
	}
}
