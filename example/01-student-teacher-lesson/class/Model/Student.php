<?php
namespace ExampleApp\Model;

use DateTime;
use GT\Orm\Entity;

class Student implements Entity {
	public function __construct(
		public int $id,
		public string $name,
		public DateTime $dob,
	) {}
}
