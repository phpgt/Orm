<?php
namespace Gt\Orm\Test\TestProject\ForeignKeys\University;

use Gt\Orm\Entity;

readonly class Teacher implements Entity {
	public function __construct(
		public string $id,
		public string $firstName,
		public string $lastName,
		public CourseList $coursesAssigned,
	) {}
}
