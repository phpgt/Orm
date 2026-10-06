<?php
namespace GT\Orm\Test\TestProject\Persistence;

use GT\Orm\Entity;

readonly class ManualKeyEntity extends Entity {
	public function __construct(
		public string $id,
		public string $value,
	) {}
}
