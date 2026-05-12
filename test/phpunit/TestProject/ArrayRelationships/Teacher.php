<?php
namespace GT\Orm\Test\TestProject\ArrayRelationships;

readonly class Teacher {
	/** @param list<Lesson> $lessons */
	public function __construct(
		public int $id,
		public string $name,
		/** @var list<Lesson> */
		public array $lessons,
	) {}
}
