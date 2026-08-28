<?php
use GT\Orm\Attribute\AutoIncrementPrimaryKey;
use GT\Orm\Collection;
use GT\Orm\Entity;
use GT\Orm\Migration\Query\SchemaQuerySQLite;
use GT\Orm\Migration\SchemaGenerator;

require(__DIR__ . "/../vendor/autoload.php");

// Let's define the classes we'll use:
// - The Student class represents an individual student
// - The Lesson class represents a lesson, which is assigned a collection of Students.

readonly class Student implements Entity {
	#[AutoIncrementPrimaryKey]
	public int $id;

	public function __construct(
		public string $name,
		public DateTime $dob,
	) {}
}

readonly class Lesson implements Entity {
	#[AutoIncrementPrimaryKey]
	public int $id;

	public function __construct(
		public string $name,
		public StudentCollection $students,
	) {}
}

/** @extends Collection<int, Student> */
class StudentCollection extends Collection {}

// Let's create the schema as a SQLite database.
$generator = new SchemaGenerator();
$schemaTableList = $generator->generateAll(
	Student::class,
	Lesson::class,
);

$createTableSql = implode(";\n", array_map(
	fn($schemaTable) => new SchemaQuerySQLite($schemaTable)->generateSql(),
	$schemaTableList,
));
echo $createTableSql;
