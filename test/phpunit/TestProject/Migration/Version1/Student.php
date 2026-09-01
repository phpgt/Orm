<?php
namespace GT\Orm\Test\TestProject\Migration\Version1;

use DateTime;
use GT\Orm\Entity;

readonly class Student implements Entity {
	public function __construct(
		public int $id,
		public string $name,
		public DateTime $dob,
	) {}
}
