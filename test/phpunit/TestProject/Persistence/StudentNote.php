<?php
namespace GT\Orm\Test\TestProject\Persistence;

use GT\Orm\Attribute\AutoIncrementPrimaryKey;
use GT\Orm\Entity;

readonly class StudentNote implements Entity {
	#[AutoIncrementPrimaryKey]
	public int $id;

	public function __construct(
		public Student $student,
		public string $text,
		public ?Student $reviewer = null,
	) {}
}
