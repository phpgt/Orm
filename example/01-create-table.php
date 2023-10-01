<?php
use Gt\Orm\Attribute\PrimaryKey;
use Gt\Orm\Migration\Query\SchemaQuerySQLite;
use Gt\Orm\Migration\SchemaGenerator;
use Gt\Orm\Migration\SchemaTable;

require(__DIR__ . "/../vendor/autoload.php");

// Let's define the classes we'll use:
// - The Student class represents an individual student
// - The Lesson class represents a lesson, which is assigned an array of Students.

#[PrimaryKey("id", PrimaryKey::AUTOINCREMENT)]
readonly class Student {
	public function __construct(
		public int $id,
		public string $name,
		public DateTime $dob,
	) {}
}

#[PrimaryKey("id", PrimaryKey::AUTOINCREMENT)]
readonly class Lesson {
	public function __construct(
		public int $id,
		public string $name,
//		public StudentCollection $students,
	) {
		$firstStudent = $this->students->offsetGet(123);
		$id = $firstStudent->id;

		foreach($this->students as $student) {
			$id = $student->id;
		}
	}
}

///** @extends Collection<int, Student> */
//class StudentCollection extends Collection {}
//
///**
// * @template TKey of array-key
// * @template TValue
// * @implements ArrayAccess<TKey, TValue>
// * @implements Iterator<TKey, TValue>
// * @implements Generator<TKey, TValue>
// */
//class Collection implements ArrayAccess, Iterator {
//	/**
//	 * @phpstan-param array<TKey, TValue> $items
//	 * @param array<mixed, mixed> $items
//	 */
//	public function __construct(
//		/**
//		 * @phpstan-var array<TKey, TValue>
//		 * @var array
//		 */
//		private array $items
//	) {
//	}
//
//	/**
//	 * @phpstan-param TKey $offset
//	 * @return TValue
//	 */
//	public function offsetGet(mixed $offset):mixed {
//		return $this->items[$offset];
//	}
//
//	/** @return TValue */
//	public function current():mixed {
//		// TODO: Implement current() method.
//	}
//}

// Let's create the schema as a SQLite database.
$generator = new SchemaGenerator();
$studentSchemaTable = $generator->generate(Student::class);
//$lessonSchemaTable = $generator->generate(Lesson::class);

$createTableSql = implode(";\n", [
	(new SchemaQuerySQLite($studentSchemaTable))->generateSql(),
//	(new SchemaQuerySQLite($lessonSchemaTable))->generateSql(),
]);
echo $createTableSql;
