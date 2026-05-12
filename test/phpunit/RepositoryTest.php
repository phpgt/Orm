<?php
namespace GT\Orm\Test;

use Gt\Database\Database;
use Gt\Database\Result\ResultSet;
use Gt\Database\Result\Row;
use GT\Orm\Repository;
use GT\Orm\Test\TestProject\ArrayRelationships\Teacher;
use GT\Orm\Test\TestProject\ForeignKeys\University\Department;
use GT\Orm\Test\TestProject\ForeignKeys\University\Student;
use GT\Orm\Test\TestProject\ForeignKeys\University\UniversityRepository;
use Gt\SqlBuilder\Condition\AndCondition;
use Gt\SqlBuilder\SelectBuilder;
use PHPUnit\Framework\TestCase;

class RepositoryTest extends TestCase {
	/**
	 * This test ensures that when an entity class refers to another class,
	 * the other class's table isn't queried if the property is not
	 * accessed; the Department class has a "headOfDepartment" property,
	 * referring to the Teacher class, but this should never be called.
	 * The "never" assertion is done by asserting the executeSql method is
	 * only ever called exactly once, and the sql it is called with does
	 * not refer to the headOfDepartment property.
	 */
	public function testFetch_byId():void {
		$row = self::createMock(Row::class);
		$row->method("contains")
			->willReturnMap([
				["id", true],
				["name", true],
			// the actual name of a referenced property should not be present
				["headOfDepartment", false],
			// but a foreign key should
				["headOfDepartment_Teacher_id", true],
			]);
		$row->method("get")
			->willReturnMap([
				["id", "DEPARTMENT_COMPUTING"],
				["name", "Computing"],
				["headOfDepartment_Teacher_id", "TEACHER_JOHN"],
			]);

		$resultSet = self::createMock(ResultSet::class);
		$resultSet->expects(self::once())
			->method("fetch")
			->willReturn($row);

		$database = self::createMock(Database::class);
		$database->expects(self::once())
			->method("executeSql")
			->willReturnCallback(function(string $query, array $args)use($resultSet) {
				$query = str_replace(["\n", "\t", "  "], " ", trim($query));
				self::assertSame("select id, name, headOfDepartment_Teacher_id from Department where id = :id", $query);
				self::assertSame(["id" => "DEPARTMENT_COMPUTING"], $args);
				return $resultSet;
			});

		$sut = new UniversityRepository($database);
		$department = $sut->fetch(Department::class, "DEPARTMENT_COMPUTING");
		self::assertSame("DEPARTMENT_COMPUTING", $department->id);
		self::assertSame("Computing", $department->name);
	}

	/**
	 * This tests the lazy "headOfDepartment" property on the Department
	 * class. Any property with a type of another class in your code will
	 * represent a joined table, but to prevent huge, slow, cyclic queries,
	 * any joined tables are only selected if/when the property is accessed.
	 *
	 * Similarly to the test above, the headOfDepartment has a nested
	 * property "coursesTaught", which represents a junction table that
	 * should not be queried unless the property is accessed.
	 *
	 * TODO: After a lazy query has been executed, cache the entities by ID.
	 */
	public function testFetch_lazyProperty():void {
		$rowDepartment = self::createMock(Row::class);
		$rowDepartment->method("contains")
			->willReturnMap([
				["id", true],
				["name", true],
			// the actual name of a referenced property should not be present
				["headOfDepartment", false],
			// but a foreign key should
				["headOfDepartment_Teacher_id", true],
			]);
		$rowDepartment->method("get")
			->willReturnMap([
				["id", "DEPARTMENT_COMPUTING"],
				["name", "Computing"],
				["headOfDepartment_Teacher_id", "TEACHER_JOHN"],
			]);

		$rowTeacher = self::createMock(Row::class);
		$rowTeacher->method("contains")
			->willReturnMap([
				["id", true],
				["firstName", true],
				["lastName", true],
				["coursesAssigned", false],
			]);
		$rowTeacher->method("get")
			->willReturnMap([
				["id", "TEACHER_JOHN"],
				["firstName", "John"],
				["lastName", "Johnson"],
			]);

		$resultSetDepartment = self::createMock(ResultSet::class);
		$resultSetDepartment->expects(self::once())
			->method("fetch")
			->willReturn($rowDepartment);

		$resultSetTeacher = self::createMock(ResultSet::class);
		$resultSetTeacher->expects(self::once())
			->method("fetch")
			->willReturn($rowTeacher);

		$database = self::createMock(Database::class);
			$database->expects(self::exactly(2))
				->method("executeSql")
				->willReturnCallback(function(string $query, array $args)use($resultSetDepartment, $resultSetTeacher) {
					$query = str_replace(["\n", "\t", "  "], " ", trim($query));
					return match($query) {
						"select id, name, headOfDepartment_Teacher_id from Department where id = :id" => $resultSetDepartment,
						"select id, firstName, lastName from Teacher where id = :id" => $resultSetTeacher,
						default => throw new \UnexpectedValueException($query),
					};
				});

		$sut = new UniversityRepository($database);
		$department = $sut->fetch(Department::class, "DEPARTMENT_COMPUTING");
		self::assertSame("DEPARTMENT_COMPUTING", $department->id);
		self::assertSame("Computing", $department->name);
		self::assertSame("TEACHER_JOHN", $department->headOfDepartment->id);
		self::assertSame("John", $department->headOfDepartment->firstName);
		self::assertSame("Johnson", $department->headOfDepartment->lastName);
	}

	/**
	 * Following on from the tests above, this test loads the lazy property
	 * of headOfDepartment (Teacher class), and then loads the lazy property
	 * coursesTaught on the Teacher.
	 *
	 * Not only is this test performing a nested lazy load, but the
	 * coursesTaught property is an array, and will need to produce a
	 * query via a junction table.
	 *
	 * TODO: After a lazy query has been executed, cache the entities by ID.
	 */
	public function testFetch_lazyPropertyNested():void {
		$rowDepartment = self::createMock(Row::class);
		$rowDepartment->method("contains")
			->willReturnMap([
				["id", true],
				["name", true],
				["headOfDepartment_Teacher_id", true],
				["headOfDepartment", false],
			]);
		$rowDepartment->method("get")
			->willReturnMap([
				["id", "DEPARTMENT_COMPUTING"],
				["name", "Computing"],
				["headOfDepartment_Teacher_id", "TEACHER_JOHN"],
			]);

		$rowTeacher = self::createMock(Row::class);
			$rowTeacher->method("contains")
				->willReturnMap([
					["id", true],
					["firstName", true],
					["lastName", true],
					["coursesAssigned", false],
				]);
		$rowTeacher->method("get")
			->willReturnMap([
				["id", "TEACHER_JOHN"],
				["firstName", "John"],
				["lastName", "Johnson"],
			]);

		$rowCourse1 = self::createMock(Row::class);
		$rowCourse1->method("get")->with("id")->willReturn("COURSE_FIRST");
		$rowCourse2 = self::createMock(Row::class);
		$rowCourse2->method("get")->with("id")->willReturn("COURSE_SECOND");
		$rowCourse3 = self::createMock(Row::class);
		$rowCourse3->method("get")->with("id")->willReturn("COURSE_THIRD");

		$resultSetDepartment = self::createMock(ResultSet::class);
		$resultSetDepartment->expects(self::once())
			->method("fetch")
			->willReturn($rowDepartment);

		$resultSetTeacher = self::createMock(ResultSet::class);
		$resultSetTeacher->expects(self::once())
			->method("fetch")
			->willReturn($rowTeacher);

		$resultSetCourse = self::createMock(ResultSet::class);
		$resultSetCourse
			->method("fetch")
			->willReturnOnConsecutiveCalls(
				$rowCourse1,
				$rowCourse2,
				$rowCourse3,
			);

		$database = self::createMock(Database::class);
		$database->expects(self::exactly(3))
			->method("executeSql")
			->willReturnOnConsecutiveCalls(
				$resultSetDepartment,
				$resultSetTeacher,
				$resultSetCourse,
			);

		$sut = new UniversityRepository($database);
		$department = $sut->fetch(Department::class, 12345);
		self::assertSame("DEPARTMENT_COMPUTING", $department->id);
		$headOfDepartment = $department->headOfDepartment;
		self::assertCount(3, $headOfDepartment->coursesAssigned);
		self::assertSame("COURSE_FIRST", $headOfDepartment->coursesAssigned[0]->id);
		self::assertSame("COURSE_SECOND", $headOfDepartment->coursesAssigned[1]->id);
		self::assertSame("COURSE_THIRD", $headOfDepartment->coursesAssigned[2]->id);
	}

	public function testFetch_lazyArrayRelationship():void {
		$rowTeacher = self::createMock(Row::class);
		$rowTeacher->method("contains")
			->willReturnMap([
				["id", true],
				["name", true],
			]);
		$rowTeacher->method("get")
			->willReturnMap([
				["id", "101"],
				["name", "Mrs Example"],
			]);

		$rowLesson1 = self::createMock(Row::class);
		$rowLesson1->method("get")->with("id")->willReturn("501");
		$rowLesson2 = self::createMock(Row::class);
		$rowLesson2->method("get")->with("id")->willReturn("502");

		$resultSetTeacher = self::createMock(ResultSet::class);
		$resultSetTeacher->expects(self::once())
			->method("fetch")
			->willReturn($rowTeacher);

		$resultSetLesson = self::createMock(ResultSet::class);
		$resultSetLesson->method("fetch")
			->willReturnOnConsecutiveCalls(
				$rowLesson1,
				$rowLesson2,
				null,
			);

		$database = self::createMock(Database::class);
		$database->expects(self::exactly(2))
			->method("executeSql")
			->willReturnCallback(function(string $query, array $args) use ($resultSetTeacher, $resultSetLesson) {
				$query = str_replace(["\n", "\t", "  "], " ", trim($query));

				if($query === "select id, name from Teacher where id = :id") {
					return $resultSetTeacher;
				}

				if($query === "select Lesson_id as id from Teacher_lessons_Lesson where Teacher_id = :Teacher_id") {
					self::assertSame(["Teacher_id" => "101"], $args);
					return $resultSetLesson;
				}

				throw new \UnexpectedValueException($query);
			});

		$sut = new Repository($database);
		$teacher = $sut->fetch(Teacher::class, 101);
		self::assertSame(101, $teacher->id);
		self::assertSame("Mrs Example", $teacher->name);
		self::assertCount(2, $teacher->lessons);
		self::assertSame(501, $teacher->lessons[0]->id);
		self::assertSame(502, $teacher->lessons[1]->id);
	}

	public function testFetchAll():void {
		$rowDepartmentOne = self::createMock(Row::class);
		$rowDepartmentOne->method("contains")
			->willReturnMap([
				["id", true],
				["name", true],
				["headOfDepartment", false],
				["headOfDepartment_Teacher_id", true],
			]);
		$rowDepartmentOne->method("get")
			->willReturnMap([
				["id", "DEPARTMENT_COMPUTING"],
				["name", "Computing"],
				["headOfDepartment_Teacher_id", "TEACHER_JOHN"],
			]);

		$rowDepartmentTwo = self::createMock(Row::class);
		$rowDepartmentTwo->method("contains")
			->willReturnMap([
				["id", true],
				["name", true],
				["headOfDepartment", false],
				["headOfDepartment_Teacher_id", true],
			]);
		$rowDepartmentTwo->method("get")
			->willReturnMap([
				["id", "DEPARTMENT_MATHS"],
				["name", "Mathematics"],
				["headOfDepartment_Teacher_id", "TEACHER_JANE"],
			]);

		$resultSet = self::createMock(ResultSet::class);
		$resultSet->expects(self::exactly(3))
			->method("fetch")
			->willReturnOnConsecutiveCalls(
				$rowDepartmentOne,
				$rowDepartmentTwo,
				null,
			);

		$database = self::createMock(Database::class);
		$database->expects(self::once())
			->method("executeSql")
			->willReturnCallback(function(string $query, array $args)use($resultSet) {
				$query = str_replace(["\n", "\t", "  "], " ", trim($query));
				self::assertSame("select id, name, headOfDepartment_Teacher_id from Department", $query);
				self::assertSame([], $args);
				return $resultSet;
			});

		$sut = new UniversityRepository($database);
		$departmentList = $sut->fetchAll(Department::class);
		self::assertCount(2, $departmentList);
		self::assertSame("DEPARTMENT_COMPUTING", $departmentList[0]->id);
		self::assertSame("Computing", $departmentList[0]->name);
		self::assertSame("DEPARTMENT_MATHS", $departmentList[1]->id);
		self::assertSame("Mathematics", $departmentList[1]->name);
	}

	public function testFetchAll_matchFieldValue():void {
		$rowDepartment = self::createMock(Row::class);
		$rowDepartment->method("contains")
			->willReturnMap([
				["id", true],
				["name", true],
				["headOfDepartment", false],
				["headOfDepartment_Teacher_id", true],
			]);
		$rowDepartment->method("get")
			->willReturnMap([
				["id", "DEPARTMENT_COMPUTING"],
				["name", "Computing"],
				["headOfDepartment_Teacher_id", "TEACHER_JOHN"],
			]);

		$resultSet = self::createMock(ResultSet::class);
		$resultSet->expects(self::exactly(2))
			->method("fetch")
			->willReturnOnConsecutiveCalls(
				$rowDepartment,
				null,
			);

		$database = self::createMock(Database::class);
		$database->expects(self::once())
			->method("executeSql")
			->willReturnCallback(function(string $query, array $args)use($resultSet) {
				$query = str_replace(["\n", "\t", "  "], " ", trim($query));
				self::assertSame("select id, name, headOfDepartment_Teacher_id from Department where name = :name", $query);
				self::assertSame(["name" => "Computing"], $args);
				return $resultSet;
			});

		$sut = new UniversityRepository($database);
		$departmentList = $sut->fetchAll(Department::class, "name", "Computing");
		self::assertCount(1, $departmentList);
		self::assertSame("DEPARTMENT_COMPUTING", $departmentList[0]->id);
	}

	public function testFetchAll_matchCondition():void {
		$rowDepartment = self::createMock(Row::class);
		$rowDepartment->method("contains")
			->willReturnMap([
				["id", true],
				["name", true],
				["headOfDepartment", false],
				["headOfDepartment_Teacher_id", true],
			]);
		$rowDepartment->method("get")
			->willReturnMap([
				["id", "DEPARTMENT_COMPUTING"],
				["name", "Computing"],
				["headOfDepartment_Teacher_id", "TEACHER_JOHN"],
			]);

		$resultSet = self::createMock(ResultSet::class);
		$resultSet->expects(self::exactly(2))
			->method("fetch")
			->willReturnOnConsecutiveCalls(
				$rowDepartment,
				null,
			);

		$database = self::createMock(Database::class);
		$database->expects(self::once())
			->method("executeSql")
			->willReturnCallback(function(string $query, array $args)use($resultSet) {
				$query = str_replace(["\n", "\t", "  "], " ", trim($query));
				self::assertSame("select id, name, headOfDepartment_Teacher_id from Department where name = :name", $query);
				self::assertSame([], $args);
				return $resultSet;
			});

		$sut = new UniversityRepository($database);
		$departmentList = $sut->fetchAll(Department::class, new AndCondition("name = :name"));
		self::assertCount(1, $departmentList);
		self::assertSame("DEPARTMENT_COMPUTING", $departmentList[0]->id);
	}

	public function testInsert_scalarEntity():void {
		$database = self::createMock(Database::class);
		$database->expects(self::once())
			->method("executeSql")
			->willReturnCallback(function(string $query, array $args) {
				$query = str_replace(["\n", "\t", "  "], " ", trim($query));
				self::assertSame(
					"insert into Student ( id, firstName, lastName, dateOfBirth ) values ( :id, :firstName, :lastName, :dateOfBirth )",
					$query,
				);
				self::assertSame([
					"id" => 123,
					"firstName" => "Ada",
					"lastName" => "Lovelace",
					"dateOfBirth" => "1815-12-10T00:00:00+00:00",
				], $args);

				return self::createMock(ResultSet::class);
			});

		$sut = new Repository($database);
		$sut->insert(new Student(
			123,
			"Ada",
			"Lovelace",
			new \DateTime("1815-12-10", new \DateTimeZone("UTC")),
			new \GT\Orm\Test\TestProject\ForeignKeys\University\CourseList([]),
		));
	}
}
