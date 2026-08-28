<?php
namespace GT\Orm\Test\TestProject\Metadata;

use GT\Orm\Entity;

readonly class EnumEntity implements Entity {
	public function __construct(
		public int $id,
		public EntityStatus $status,
	) {}
}
