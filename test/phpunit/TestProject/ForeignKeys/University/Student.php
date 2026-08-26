<?php
namespace GT\Orm\Test\TestProject\ForeignKeys\University;

use DateTime;
use GT\Orm\Entity;

readonly class Student implements Entity {
	private string $password;

	public function __construct(
		public int $id,
		public string $firstName,
		public string $lastName,
		public DateTime $dateOfBirth,
		public CourseList $coursesTaught,
	) {
	}
}
