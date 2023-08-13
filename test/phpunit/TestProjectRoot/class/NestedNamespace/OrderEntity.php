<?php
namespace Gt\Orm\Test\TestProjectRoot\class\NestedNamespace;

use DateTime;
use Gt\Orm\Entity;
use Gt\Orm\Test\TestProjectRoot\class\PersonEntity;

readonly class OrderEntity extends Entity {
	public function __construct(
		public string $id,
		public PersonEntity $person,
		public DateTime $orderedAt,
		public int $value,
	) {}
}
