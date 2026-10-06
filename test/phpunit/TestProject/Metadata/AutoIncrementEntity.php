<?php
namespace GT\Orm\Test\TestProject\Metadata;

use DateTimeImmutable;
use GT\Orm\Attribute\AutoIncrementPrimaryKey;
use GT\Orm\Attribute\DefaultValue;
use GT\Orm\Entity;

readonly class AutoIncrementEntity extends Entity {
	#[AutoIncrementPrimaryKey]
	public int $id;

	public function __construct(
		public string $name,
		#[DefaultValue("pending")]
		public string $status = "new",
		public DateTimeImmutable $createdAt = new DateTimeImmutable(),
	) {}
}
