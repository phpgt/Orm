<?php
use GT\Database\Connection\Settings;
use GT\Database\Database;
use GT\Orm\Attribute\AutoIncrementPrimaryKey;
use GT\Orm\Collection;
use GT\Orm\Entity;
use GT\Orm\Migration\Query\SchemaQuerySQLite;
use GT\Orm\Migration\SchemaGenerator;
use GT\Orm\Repository;

require(__DIR__ . "/../vendor/autoload.php");

// Let's define the classes we'll use:
// - The Student class represents an individual student
// - The Lesson class represents a lesson, which is assigned a collection of Students.

readonly class Student extends Entity {
	#[AutoIncrementPrimaryKey]
	public int $id;

	public function __construct(
		public string $name,
		public DateTime $dob,
	) {}
}

readonly class Lesson extends Entity {
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
echo "$createTableSql;\n\n";

$exampleName = pathinfo(__FILE__, PATHINFO_FILENAME);

$projectRoot = dirname(__FILE__, 2);
$database = new Database();
foreach($schemaTableList as $schemaTable) {
	$database->executeSql(
		new SchemaQuerySQLite($schemaTable)->generateSql(),
	);
}
$repository = new Repository($database);

// Insert the entities referred to by a collection before inserting its owner.
$ada = $repository->insert(new Student(
	"Ada Lovelace",
	new DateTime("1815-12-10"),
));
$grace = $repository->insert(new Student(
	"Grace Hopper",
	new DateTime("1906-12-09"),
));
$alan = $repository->insert(new Student(
	"Alan Turing",
	new DateTime("1912-06-23"),
));

$lesson = $repository->insert(new Lesson(
	"Relational databases",
	new StudentCollection([
		$ada,
		$grace,
		$ada,
		$alan,
	]),
));

// with() creates an immutable copy without changing or persisting the original.
$renamedAda = $ada->with([
	"name" => "Augusta Ada King",
]);
echo "$ada->name is also known as $renamedAda->name.\n\n";

// A new repository ensures this is fetched from SQLite. The collection and
// each student within it remain lazy until they are accessed.
$readRepository = new Repository($database);
$fetchedLesson = $readRepository->fetch(Lesson::class, $lesson->id);
echo "$fetchedLesson->name\n";
foreach($fetchedLesson->students as $student) {
	echo "- $student->name\n";
}

// with() creates the immutable change; update() persists the resulting entity.
$grace = $repository->update($grace->with([
	"name" => "Rear Admiral Grace Hopper",
]));
$updatedLesson = $repository->update($lesson->with([
	"name" => "An introduction to relational databases",
]));

$updatedRepository = new Repository($database);
$grace = $updatedRepository->fetch(Student::class, $grace->id);
$lesson = $updatedRepository->fetch(Lesson::class, $lesson->id);
echo "\nUpdated:\n";
echo "- $grace->name\n";
echo "- $lesson->name\n";

echo "Fetching all students at once:\n";

foreach($repository->fetchAll(Student::class) as $student) {
	echo $student->name, "-";
}
