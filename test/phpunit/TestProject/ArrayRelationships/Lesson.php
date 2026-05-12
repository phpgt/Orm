<?php
namespace GT\Orm\Test\TestProject\ArrayRelationships;

readonly class Lesson {
	public function __construct(
		public int $id,
		public string $name,
		public Teacher $teacher,
	) {}
}
