<?php
namespace GT\Orm\Test\TestProject\Persistence;

use GT\Orm\Attribute\AutoIncrementPrimaryKey;
use GT\Orm\Entity;

readonly class Lesson implements Entity {
	#[AutoIncrementPrimaryKey]
	public int $id;

	public function __construct(
		public string $name,
		public StudentCollection $students,
	) {}
}
