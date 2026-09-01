<?php
namespace GT\Orm\Test\TestProject\Migration\Version2;

use DateTime;
use GT\Orm\Attribute\AutoIncrementPrimaryKey;
use GT\Orm\Entity;

readonly class Student implements Entity {
	#[AutoIncrementPrimaryKey]
	public int $id;

	public function __construct(
		public string $name,
		public DateTime $dob,
	) {}
}
