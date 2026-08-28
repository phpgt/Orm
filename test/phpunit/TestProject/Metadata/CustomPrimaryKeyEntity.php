<?php
namespace GT\Orm\Test\TestProject\Metadata;

use GT\Orm\Attribute\PrimaryKey;
use GT\Orm\Entity;

readonly class CustomPrimaryKeyEntity implements Entity {
	public function __construct(
		#[PrimaryKey]
		public string $code,
		public string $name,
	) {}
}
