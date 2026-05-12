<?php
namespace GT\Orm\Attribute;

use Attribute;

#[Attribute]
readonly class PrimaryKey {
	const AUTOINCREMENT = "autoincrement";
	const ULID = "ulid";

	public bool $autoIncrement;
	public bool $ulid;

	public function __construct(
		public string $fieldName,
		string...$modifiers
	) {
		$this->autoIncrement = in_array(self::AUTOINCREMENT, $modifiers);
		$this->ulid = in_array(self::ULID, $modifiers);
	}
}
