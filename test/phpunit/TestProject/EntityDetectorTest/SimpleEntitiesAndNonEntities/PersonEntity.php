<?php
namespace GT\Orm\Test\TestProject\EntityDetectorTest\SimpleEntitiesAndNonEntities;

use DateTime;
use GT\Orm\Entity;

readonly class PersonEntity implements Entity {
	public function __construct(
		public string $id,
		public string $name,
		public DateTime $createdAt = new DateTime(),
	) {}
}
