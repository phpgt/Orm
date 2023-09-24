<?php
use Gt\Orm\Migration\Query\SchemaQuerySQLite;
use Gt\Orm\Migration\SchemaGenerator;
use Gt\Orm\Migration\SchemaTable;

require(__DIR__ . "/../vendor/autoload.php");

// Let's define the classes we'll use:
// - The Student class represents an individual student
// - The Lesson class represents a lesson, which is assigned an array of Students.

readonly class Student {
	public function __construct(
		public int $id,
		public string $name,
		public DateTime $dob,
	) {}
}

readonly class Lesson {
	/** @param array<Student> $students */
	public function __construct(
		public int $id,
		public string $name,
		public array $students,
	) {}
}

// Let's create the schema as a SQLite database.
$generator = new SchemaGenerator();
$studentSchemaTable = $generator->generate(Student::class);
$lessonSchemaTable = $generator->generate(Lesson::class);

$createTableSql = implode(";\n", [
	(new SchemaQuerySQLite($studentSchemaTable))->generateSql(),
	(new SchemaQuerySQLite($lessonSchemaTable))->generateSql(),
]);
echo $createTableSql;
