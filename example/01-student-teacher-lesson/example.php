<?php
use Composer\Autoload\ClassLoader;
use ExampleApp\Model\Lesson;
use ExampleApp\Model\Student;
use ExampleApp\Model\Teacher;
use Gt\Database\Database;
use GT\Orm\Migration\EntityDetector;
use GT\Orm\Migration\Query\SchemaQuerySQLite;
use GT\Orm\Migration\SchemaGenerator;
use GT\Orm\Repository;

/** @var ClassLoader $autoloader */
$autoloader = require(__DIR__ . "/../../vendor/autoload.php");
$autoloader->addPsr4("ExampleApp\\", __DIR__ . "/class");

$db = new Database();
$repository = new Repository($db);

// Let's define the classes we'll use:

// - The Student class represents an individual student
// - The Teacher class represents an individual teacher, which is assigned an array of Lessons
// - The Lesson class represents a lesson, which is assigned an array of Students and a head of department
//
// There is a cyclic dependency between Teacher->Lesson->Teacher, which will test this library's capabilities.

$detector = new EntityDetector();
$classList = $detector->getEntityClassList(__DIR__ . "/class");

// Let's create the schema as an SQLite database.
$generator = new SchemaGenerator();
$schemaTableList = $generator->generateAll($classList);
foreach($schemaTableList as $schemaTable) {
	$db->executeSql(new SchemaQuerySQLite($schemaTable)->generateSql());
}

$student1 = new Student(
	111,
	"Adam Adamson",
	new DateTime("2005-01-01"),
);
$student2 = new Student(
	222,
	"Betty Bettersworth",
	new DateTime("2005-02-02"),
);
$student3 = new Student(
	333,
	"Charlie Charleston",
	new DateTime("2005-03-03"),
);

$lesson1 = new Lesson(
	1,
	"Algebra",
	[$student1, $student2],
);
$lesson2 = new Lesson(
	2,
	"Zoology",
	[$student2, $student3],
);
$lesson3 = new Lesson(
	3,
	"Individuality",
	[$student2],
);

$teacher1 = new Teacher(
	44,
	"Derek Derkslopper",
	[$lesson1],
);
$teacher2 = new Teacher(
	55,
	"Edmond Edmonson",
	[$lesson2, $lesson3],
);

$lesson1 = clone($lesson1, [
	"headOfDepartment" => $teacher1,
]);
$lesson2 = clone($lesson2, [
	"headOfDepartment" => $teacher2,
]);
$lesson3 = clone($lesson3, [
	"headOfDepartment" => $teacher2,
]);

$repository->insert(
	$student1, $student2, $student3,
	$lesson1, $lesson2, $lesson3,
	$teacher1, $teacher2,
);

// Loop over all students and show the lessons that are assigned for each student.
foreach($repository->fetchAll(Student::class) as $student) {
	echo "Student: ", $student->name, PHP_EOL;

	foreach($repository->fetchAll(Lesson::class, /* TODO: Match just the students within this lesson */) as $lesson) {
		echo "\tLesson: ", $lesson->name, PHP_EOL;
	}
}
