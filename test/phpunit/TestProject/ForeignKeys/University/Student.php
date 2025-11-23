<?php
namespace Gt\Orm\Test\TestProject\ForeignKeys\University;

use DateTime;
use Gt\Orm\Entity;

readonly class Student extends Entity {
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
