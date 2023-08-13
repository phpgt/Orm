<?php
namespace Gt\Orm\Test\TestProjectRoot\class;

use DateTime;
use Gt\Orm\Entity;

readonly class PersonEntity extends Entity {
	public function __construct(
		public string $id,
		public string $name,
		public DateTime $createdAt = new DateTime(),
	) {}
}
