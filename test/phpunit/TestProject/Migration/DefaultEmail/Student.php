<?php
namespace GT\Orm\Test\TestProject\Migration\DefaultEmail;

use DateTime;
use GT\Orm\Attribute\AutoIncrementPrimaryKey;
use GT\Orm\Attribute\DefaultValue;
use GT\Orm\Entity;

readonly class Student implements Entity {
	#[AutoIncrementPrimaryKey]
	public int $id;

	public function __construct(
		public string $name,
		public DateTime $dob,
		#[DefaultValue("unknown@example.invalid")]
		public string $email,
	) {}
}
