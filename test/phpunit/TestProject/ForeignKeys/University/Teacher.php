<?php
namespace GT\Orm\Test\TestProject\ForeignKeys\University;

use GT\Orm\Entity;

readonly class Teacher implements Entity {
	public function __construct(
		public string $id,
		public string $firstName,
		public string $lastName,
		public CourseList $coursesAssigned,
	) {}
}
