<?php
namespace ExampleApp\Model;

use GT\Orm\Entity;

class Teacher implements Entity {
	public function __construct(
		public int $id,
		public string $name,
		/** @var list<Lesson> */
		public array $lessons,
	) {}
}
