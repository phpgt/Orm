<?php
namespace ExampleApp\Model;

use GT\Orm\Entity;

class Lesson implements Entity {
	public function __construct(
		public int $id,
		public string $name,
		/** @var list<Student> */
		public array $students,
		public ?Teacher $headOfDepartment = null,
	) {}
}
