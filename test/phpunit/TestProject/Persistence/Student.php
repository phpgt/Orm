<?php
namespace GT\Orm\Test\TestProject\Persistence;

use DateTimeImmutable;
use GT\Orm\Attribute\AutoIncrementPrimaryKey;
use GT\Orm\Entity;

readonly class Student implements Entity {
	#[AutoIncrementPrimaryKey]
	public int $id;

	public function __construct(
		public string $name,
		public DateTimeImmutable $dateOfBirth,
		public bool $active = true,
		public StudentStatus $status = StudentStatus::ACTIVE,
		public ?string $nickname = null,
	) {}
}
