<?php
namespace Gt\Orm\Test;

use Gt\Database\Database;
use Gt\Database\Result\ResultSet;
use Gt\Database\Result\Row;
use Gt\Orm\Repository;
use Gt\Orm\Test\TestProject\ForeignKeys\University\Department;
use Gt\Orm\Test\TestProject\ForeignKeys\University\Student;
use Gt\Orm\Test\TestProject\ForeignKeys\University\UniversityRepository;
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
//
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
			->willReturnOnConsecutiveCalls(
				$resultSetDepartment,
				$resultSetTeacher,
			);

		$sut = new UniversityRepository($database);
		$department = $sut->fetch(Department::class, 12345);
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
				["coursesAssigned_TODO_JUNCTION_TABLE", true],
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
		self::assertCount(3, $department->headOfDepartment->coursesAssigned);
		self::assertSame("COURSE_FIRST", $department->headOfDepartment->coursesAssigned[0]->id);
		self::assertSame("COURSE_SECOND", $department->headOfDepartment->coursesAssigned[1]->id);
		self::assertSame("COURSE_THIRD", $department->headOfDepartment->coursesAssigned[2]->id);
	}
}
