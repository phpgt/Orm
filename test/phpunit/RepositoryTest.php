<?php
namespace GT\Orm\Test;

use DateTimeImmutable;
use Gt\Database\Database;
use Gt\Database\Result\ResultSet;
use Gt\Database\Result\Row;
use GT\Orm\Repository;
use GT\Orm\Test\TestProject\ForeignKeys\University\Department;
use GT\Orm\Test\TestProject\ForeignKeys\University\Student;
use GT\Orm\Test\TestProject\ForeignKeys\University\UniversityRepository;
use GT\Orm\Test\TestProject\Metadata\CustomPrimaryKeyEntity;
use GT\Orm\Test\TestProject\Metadata\EntityStatus;
use GT\Orm\Test\TestProject\Metadata\EnumEntity;
use GT\Orm\Test\TestProject\Metadata\TemporalEntity;
use Gt\SqlBuilder\Condition\AndCondition;
use Gt\SqlBuilder\Condition\OrCondition;
use Gt\SqlBuilder\SelectBuilder;
use PHPUnit\Framework\TestCase;

class RepositoryTest extends TestCase {
	public function testFetch_customPrimaryKey():void {
		$row = self::createStub(Row::class);
		$row->method("contains")->willReturn(true);
		$row->method("get")->willReturnMap([
			["code", "PERSON_ONE"],
			["name", "Ada"],
		]);
		$resultSet = self::createMock(ResultSet::class);
		$resultSet->expects(self::once())->method("fetch")->willReturn($row);
		$database = self::createMock(Database::class);
		$database->expects(self::once())
			->method("executeSql")
			->willReturnCallback(function(string $query, array $args)use($resultSet) {
				$query = str_replace(["\n", "\t", "  "], " ", trim($query));
				self::assertSame(
					"select code, name from CustomPrimaryKeyEntity where code = :code",
					$query,
				);
				self::assertSame(["code" => "PERSON_ONE"], $args);
				return $resultSet;
			});

		$entity = (new Repository($database))->fetch(
			CustomPrimaryKeyEntity::class,
			"PERSON_ONE",
		);

		self::assertSame("PERSON_ONE", $entity->code);
		self::assertSame("Ada", $entity->name);
	}

	public function testFetch_matchFieldValue():void {
		$row = self::createStub(Row::class);
		$row->method("contains")->willReturn(true);
		$row->method("get")->willReturnMap([
			["code", "PERSON_ONE"],
			["name", "Ada"],
		]);
		$resultSet = self::createMock(ResultSet::class);
		$resultSet->expects(self::once())->method("fetch")->willReturn($row);
		$database = self::createMock(Database::class);
		$database->expects(self::once())
			->method("executeSql")
			->willReturnCallback(function(string $query, array $args)use($resultSet) {
				$query = str_replace(["\n", "\t", "  "], " ", trim($query));
				self::assertSame(
					"select code, name from CustomPrimaryKeyEntity where name = :name",
					$query,
				);
				self::assertSame(["name" => "Ada"], $args);
				return $resultSet;
			});

		$entity = (new Repository($database))->fetch(
			CustomPrimaryKeyEntity::class,
			"name",
			"Ada",
		);

		self::assertSame("PERSON_ONE", $entity->code);
		self::assertSame("Ada", $entity->name);
	}

	public function testFetch_matchConditions():void {
		$row = self::createStub(Row::class);
		$row->method("contains")->willReturn(true);
		$row->method("get")->willReturnMap([
			["code", "PERSON_ONE"],
			["name", "Ada"],
		]);
		$resultSet = self::createMock(ResultSet::class);
		$resultSet->expects(self::once())->method("fetch")->willReturn($row);
		$database = self::createMock(Database::class);
		$database->expects(self::once())
			->method("executeSql")
			->willReturnCallback(function(string $query, array $args)use($resultSet) {
				$query = str_replace(["\n", "\t", "  "], " ", trim($query));
				self::assertSame(
					"select code, name from CustomPrimaryKeyEntity where name = 'Ada' or code = 'PERSON_ONE'",
					$query,
				);
				self::assertSame([], $args);
				return $resultSet;
			});

		$entity = (new Repository($database))->fetch(
			CustomPrimaryKeyEntity::class,
			new AndCondition("name = 'Ada'"),
			new OrCondition("code = 'PERSON_ONE'"),
		);

		self::assertSame("PERSON_ONE", $entity->code);
		self::assertSame("Ada", $entity->name);
	}

	public function testFetch_nativeDateTime():void {
		$row = self::createStub(Row::class);
		$row->method("contains")->willReturn(true);
		$row->method("get")->willReturnMap([
			["id", "42"],
			["createdAt", "2026-08-26 12:34:56.123456+00:00"],
		]);
		$resultSet = self::createMock(ResultSet::class);
		$resultSet->expects(self::once())->method("fetch")->willReturn($row);
		$database = self::createMock(Database::class);
		$database->expects(self::once())
			->method("executeSql")
			->willReturn($resultSet);

		$entity = (new Repository($database))->fetch(TemporalEntity::class, 42);

		self::assertSame(42, $entity->id);
		self::assertInstanceOf(DateTimeImmutable::class, $entity->createdAt);
		self::assertSame(
			"2026-08-26 12:34:56.123456+00:00",
			$entity->createdAt->format("Y-m-d H:i:s.uP"),
		);
	}

	public function testFetch_backedEnum():void {
		$row = self::createStub(Row::class);
		$row->method("contains")->willReturn(true);
		$row->method("get")->willReturnMap([
			["id", "7"],
			["status", "active"],
		]);
		$resultSet = self::createMock(ResultSet::class);
		$resultSet->expects(self::once())->method("fetch")->willReturn($row);
		$database = self::createMock(Database::class);
		$database->expects(self::once())
			->method("executeSql")
			->willReturn($resultSet);

		$entity = (new Repository($database))->fetch(EnumEntity::class, 7);

		self::assertSame(7, $entity->id);
		self::assertSame(EntityStatus::ACTIVE, $entity->status);
	}

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
		$row = self::createStub(Row::class);
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
		$rowDepartment = self::createStub(Row::class);
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

		$rowTeacher = self::createStub(Row::class);
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
		$rowDepartment = self::createStub(Row::class);
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

		$rowTeacher = self::createStub(Row::class);
		$rowTeacher->method("contains")
			->willReturnMap([
				["id", true],
				["firstName", true],
				["lastName", true],
			]);
		$rowTeacher->method("get")
			->willReturnMap([
				["id", "TEACHER_JOHN"],
				["firstName", "John"],
				["lastName", "Johnson"],
			]);

		$rowCourse1 = self::createStub(Row::class);
		$rowCourse1->method("get")->willReturn("COURSE_FIRST");
		$rowCourse2 = self::createStub(Row::class);
		$rowCourse2->method("get")->willReturn("COURSE_FIRST");
		$rowCourse3 = self::createStub(Row::class);
		$rowCourse3->method("get")->willReturn("COURSE_SECOND");

		$rowCourseDetails = self::createStub(Row::class);
		$rowCourseDetails->method("contains")->willReturnMap([
			["id", true],
			["title", true],
			["department_Department_id", false],
			["parent_Course_id", false],
		]);
		$rowCourseDetails->method("get")->willReturnMap([
			["id", "COURSE_FIRST"],
			["title", "First course"],
		]);

		$resultSetDepartment = self::createMock(ResultSet::class);
		$resultSetDepartment->expects(self::once())
			->method("fetch")
			->willReturn($rowDepartment);

		$resultSetTeacher = self::createMock(ResultSet::class);
		$resultSetTeacher->expects(self::once())
			->method("fetch")
			->willReturn($rowTeacher);

		$resultSetCourse = self::createStub(ResultSet::class);
		$resultSetCourse
			->method("fetch")
			->willReturnOnConsecutiveCalls(
				$rowCourse1,
				$rowCourse2,
				$rowCourse3,
			);
		$resultSetCourseDetails = self::createMock(ResultSet::class);
		$resultSetCourseDetails->expects(self::once())
			->method("fetch")
			->willReturn($rowCourseDetails);

		$database = self::createMock(Database::class);
		$database->expects(self::exactly(4))
			->method("executeSql")
			->willReturnCallback(function(string $query, array $args)use(
				$resultSetDepartment,
				$resultSetTeacher,
				$resultSetCourse,
				$resultSetCourseDetails,
			) {
				$query = str_replace(["\n", "\t", "  "], " ", trim($query));
				return match($query) {
					"select id, name, headOfDepartment_Teacher_id from Department where id = :id" => $resultSetDepartment,
					"select id, firstName, lastName from Teacher where id = :id" => $resultSetTeacher,
					"select Course_id from Teacher_coursesAssigned_Course where Teacher_id = :Teacher_id order by id" => $this->junctionResult(
						$args,
						$resultSetCourse,
					),
					"select id, title, department_Department_id, parent_Course_id from Course where id = :id" => $this->courseResult(
						$args,
						$resultSetCourseDetails,
					),
				};
			});

		$sut = new UniversityRepository($database);
		$department = $sut->fetch(Department::class, 12345);
		self::assertSame("DEPARTMENT_COMPUTING", $department->id);
		$headOfDepartment = $department->headOfDepartment;
		self::assertCount(3, $headOfDepartment->coursesAssigned);
		self::assertSame("COURSE_FIRST", $headOfDepartment->coursesAssigned[0]->id);
		self::assertSame("COURSE_FIRST", $headOfDepartment->coursesAssigned[1]->id);
		self::assertSame("COURSE_SECOND", $headOfDepartment->coursesAssigned[2]->id);
		self::assertSame("First course", $headOfDepartment->coursesAssigned[0]->title);
	}

	/** @return ResultSet */
	private function junctionResult(array $args, ResultSet $resultSet):ResultSet {
		self::assertSame(["Teacher_id" => "TEACHER_JOHN"], $args);
		return $resultSet;
	}

	/** @return ResultSet */
	private function courseResult(array $args, ResultSet $resultSet):ResultSet {
		self::assertSame(["id" => "COURSE_FIRST"], $args);
		return $resultSet;
	}
}
