<?php
namespace GT\Orm\Test\TestProject\Metadata;

use DateTimeImmutable;
use GT\Orm\Entity;

readonly class TemporalEntity implements Entity {
	public function __construct(
		public int $id,
		public DateTimeImmutable $createdAt,
	) {}
}
