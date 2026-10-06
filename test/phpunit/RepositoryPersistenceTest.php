<?php
namespace GT\Orm\Test;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use GT\Database\Database;
use GT\Orm\Exception\InvalidEntityStateException;
use GT\Orm\Migration\Query\SchemaQuerySQLite;
use GT\Orm\Migration\SchemaGenerator;
use GT\Orm\Repository;
use GT\Orm\Test\TestProject\Metadata\CustomPrimaryKeyEntity;
use GT\Orm\Test\TestProject\Persistence\DefaultOnlyEntity;
use GT\Orm\Test\TestProject\Persistence\Lesson;
use GT\Orm\Test\TestProject\Persistence\ManualKeyEntity;
use GT\Orm\Test\TestProject\Persistence\Student;
use GT\Orm\Test\TestProject\Persistence\StudentCollection;
use GT\Orm\Test\TestProject\Persistence\StudentNote;
use GT\Orm\Test\TestProject\Persistence\StudentStatus;
use GT\Orm\Test\TestProject\Persistence\UninitialisedEntity;
use GT\SqlBuilder\Condition\AndCondition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RepositoryPersistenceTest extends TestCase {
	private Database $database;
	private Repository $repository;

	protected function setUp():void {
		$this->database = new Database();
		$this->createSchema(
			Student::class,
			Lesson::class,
			StudentNote::class,
			ManualKeyEntity::class,
			DefaultOnlyEntity::class,
			UninitialisedEntity::class,
			CustomPrimaryKeyEntity::class,
		);
		$this->repository = new Repository($this->database);
	}

	public function testInsertMapsNativeValuesAndInitialisesGeneratedId():void {
		$dateOfBirth = new DateTimeImmutable(
			"2000-01-02 03:04:05.123456",
			new DateTimeZone("America/New_York"),
		);
		$student = new Student(
			"Ada",
			$dateOfBirth,
			false,
			StudentStatus::INACTIVE,
		);

		$result = $this->repository->insert($student);

		self::assertSame($student, $result);
		self::assertSame(1, $student->id);
		$row = $this->requiredRow(<<<SQL
			select * from Student where id = 1
		SQL);
		self::assertSame("Ada", $row["name"]);
		self::assertSame("2000-01-02 08:04:05.123456", $row["dateOfBirth"]);
		self::assertSame(0, $row["active"]);
		self::assertSame("inactive", $row["status"]);
		self::assertNull($row["nickname"]);
	}

	public function testInsertAssignsSuccessiveIds():void {
		$first = $this->repository->insert($this->newStudent("First"));
		$second = $this->repository->insert($this->newStudent("Second"));

		self::assertSame(1, $first->id);
		self::assertSame(2, $second->id);
	}

	public function testDeleteByPrimaryKey():void {
		$entity = $this->repository->insert(
			new ManualKeyEntity("known-key", "old value"),
		);

		self::assertSame(
			1,
			$this->repository->delete($entity),
		);
		self::assertSame(0, $this->scalar(
			"select count(*) from ManualKeyEntity",
		));

		$this->database->executeSql(
			"insert into ManualKeyEntity (id, value) values (:id, :value)",
			["id" => "known-key", "value" => "new value"],
		);
		$fetched = $this->repository->fetch(
			ManualKeyEntity::class,
			"known-key",
		);
		self::assertNotSame($entity, $fetched);
		self::assertSame("new value", $fetched->value);
	}

	public function testDeleteEntityWithGeneratedPrimaryKey():void {
		$ada = $this->repository->insert($this->newStudent("Ada"));
		$grace = $this->repository->insert($this->newStudent("Grace"));

		self::assertSame(1, $this->repository->delete($ada));
		self::assertSame(
			["Grace"],
			array_column($this->rows("select name from Student"), "name"),
		);
		self::assertSame($grace, $this->repository->fetch(Student::class, $grace->id));
	}

	public function testDeleteClassByPrimaryKey():void {
		$ada = $this->repository->insert($this->newStudent("Ada"));
		$this->repository->insert($this->newStudent("Grace"));

		self::assertSame(1, $this->repository->delete(Student::class, $ada->id));
		self::assertSame(
			["Grace"],
			array_column($this->rows("select name from Student"), "name"),
		);
	}

	public function testDeleteRejectsPotentiallyMultipleMatches():void {
		$this->repository->insert($this->newStudent("Greg"));
		$this->repository->insert($this->newStudent("Greg"));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("use deleteAll() explicitly");
		try {
			$this->repository->delete(Student::class, ["name" => "Greg"]);
		}
		finally {
			self::assertSame(2, $this->scalar("select count(*) from Student"));
		}
	}

	public function testDeleteAllAcceptsAssociativeAndBooleanMatches():void {
		$this->repository->insert($this->newStudent("Greg"));
		$this->repository->insert(new Student(
			"Greg",
			new DateTimeImmutable("2000-01-01"),
			false,
		));
		$this->repository->insert($this->newStudent("Grace"));

		self::assertSame(1, $this->repository->deleteAll(
			Student::class,
			["name" => "Greg", "active" => false],
		));
		self::assertSame(
			["Greg", "Grace"],
			array_column($this->rows("select name from Student"), "name"),
		);
	}

	public function testDeleteAllWithoutConditionsIsExplicitlyAllowed():void {
		$this->repository->insert($this->newStudent("Ada"));
		$this->repository->insert($this->newStudent("Grace"));

		self::assertSame(2, $this->repository->deleteAll(Student::class));
		self::assertSame(0, $this->scalar("select count(*) from Student"));
	}

	public function testDeleteRejectsEntityWithoutInitialisedPrimaryKey():void {
		$this->expectException(InvalidEntityStateException::class);
		$this->expectExceptionMessage("Student::\$id is not initialised");
		$this->repository->delete($this->newStudent("Not persisted"));
	}

	public function testUpdateAndDeleteUseCustomNamedPrimaryKey():void {
		$entity = $this->repository->insert(
			new CustomPrimaryKeyEntity("known-code", "Original"),
		);
		$updated = $this->repository->update($entity->with([
			"name" => "Updated",
		]));

		self::assertSame("Updated", $this->scalar(
			"select name from CustomPrimaryKeyEntity where code = 'known-code'",
		));
		self::assertSame(1, $this->repository->delete($updated));
		self::assertSame(0, $this->scalar(
			"select count(*) from CustomPrimaryKeyEntity",
		));
	}

	public function testFetchAllReturnsGeneratorOfAllEntities():void {
		$this->repository->insert($this->newStudent("Ada"));
		$this->repository->insert($this->newStudent("Grace"));
		$this->repository->insert($this->newStudent("Alan"));

		$studentGenerator = $this->repository->fetchAll(Student::class);

		self::assertInstanceOf(Generator::class, $studentGenerator);
		self::assertSame(
			["Ada", "Grace", "Alan"],
			array_map(
				fn(Student $student) => $student->name,
				iterator_to_array($studentGenerator),
			),
		);
	}

	public function testFetchAllByPrimaryKey():void {
		$ada = $this->repository->insert($this->newStudent("Ada"));
		$this->repository->insert($this->newStudent("Grace"));

		$studentList = iterator_to_array(
			$this->repository->fetchAll(Student::class, $ada->id),
		);

		self::assertCount(1, $studentList);
		self::assertSame("Ada", $studentList[0]->name);
	}

	public function testFetchAllByFieldValue():void {
		$this->repository->insert($this->newStudent("Ada"));
		$this->repository->insert($this->newStudent("Grace"));
		$this->repository->insert($this->newStudent("Ada"));

		$studentList = iterator_to_array(
			$this->repository->fetchAll(Student::class, "name", "Ada"),
		);

		self::assertCount(2, $studentList);
		self::assertSame("Ada", $studentList[0]->name);
		self::assertSame("Ada", $studentList[1]->name);
	}

	public function testFetchAllByCondition():void {
		$this->repository->insert($this->newStudent("Ada"));
		$this->repository->insert(new Student(
			"Grace",
			new DateTimeImmutable("2000-01-01"),
			false,
		));

		$studentList = iterator_to_array(
			$this->repository->fetchAll(
				Student::class,
				new AndCondition("active = false"),
			),
		);

		self::assertCount(1, $studentList);
		self::assertSame("Grace", $studentList[0]->name);
	}

	public function testInsertSupportsDeveloperSuppliedPrimaryKey():void {
		$entity = new ManualKeyEntity("known-key", "value");

		self::assertSame($entity, $this->repository->insert($entity));
		self::assertSame(
			["id" => "known-key", "value" => "value"],
			$this->requiredRow("select * from ManualKeyEntity"),
		);
	}

	public function testInsertUsesExplicitSqlDefaultWhenPropertyIsUninitialised():void {
		$entity = $this->repository->insert(new DefaultOnlyEntity());

		self::assertSame(1, $entity->id);
		self::assertFalse(isset($entity->value));
		$fetched = $this->repository->fetch(
			DefaultOnlyEntity::class,
			$entity->id,
		);
		self::assertSame("from database", $fetched->value);
	}

	public function testInitialisedPhpValueTakesPrecedenceOverSqlDefault():void {
		$entity = new DefaultOnlyEntity("from PHP");

		$this->repository->insert($entity);

		self::assertSame(
			"from PHP",
			$this->scalar("select value from DefaultOnlyEntity where id = 1"),
		);
	}

	public function testInsertRejectsUninitialisedRequiredProperty():void {
		$entity = new UninitialisedEntity();

		$this->expectException(InvalidEntityStateException::class);
		$this->expectExceptionMessage("UninitialisedEntity::\$value is not initialised");
		$this->repository->insert($entity);
	}

	public function testInsertRejectsAlreadyInitialisedGeneratedId():void {
		$student = $this->repository->insert($this->newStudent("Ada"));

		try {
			$this->repository->insert($student);
			self::fail("Expected an already initialised generated ID to be rejected");
		}
		catch(InvalidEntityStateException $exception) {
			self::assertStringContainsString(
				"generated primary key is already initialised",
				$exception->getMessage(),
			);
		}

		self::assertSame(1, $this->scalar("select count(*) from Student"));
	}

	public function testInsertStoresRepeatedCollectionItemsInOrder():void {
		$ada = $this->repository->insert($this->newStudent("Ada"));
		$grace = $this->repository->insert($this->newStudent("Grace"));
		$lesson = $this->repository->insert(new Lesson(
			"Databases",
			new StudentCollection([$ada, $grace, $ada]),
		));

		self::assertSame(1, $lesson->id);
		self::assertSame(
			[
				["id" => 1, "Lesson_id" => 1, "Student_id" => 1],
				["id" => 2, "Lesson_id" => 1, "Student_id" => 2],
				["id" => 3, "Lesson_id" => 1, "Student_id" => 1],
			],
			$this->rows("select * from Lesson_students_Student order by id"),
		);

		$fetched = (new Repository($this->database))->fetch(Lesson::class, 1);
		self::assertCount(3, $fetched->students);
		self::assertSame(
			["Ada", "Grace", "Ada"],
			array_map(
				fn(Student $student) => $student->name,
				iterator_to_array($fetched->students),
			),
		);
	}

	public function testFailedCollectionInsertRollsBackEntityAndJunctionRows():void {
		$wrongItem = new ManualKeyEntity("not-a-student", "value");
		$lesson = new Lesson(
			"Invalid lesson",
			new StudentCollection([$wrongItem]),
		);

		try {
			$this->repository->insert($lesson);
			self::fail("Expected the invalid collection item to be rejected");
		}
		catch(InvalidEntityStateException $exception) {
			self::assertStringContainsString(
				"can only contain",
				$exception->getMessage(),
			);
		}

		self::assertFalse(isset($lesson->id));
		self::assertSame(0, $this->scalar("select count(*) from Lesson"));
		self::assertSame(
			0,
			$this->scalar("select count(*) from Lesson_students_Student"),
		);
	}

	public function testUnpersistedCollectionItemRollsBackInsert():void {
		$lesson = new Lesson(
			"Invalid lesson",
			new StudentCollection([$this->newStudent("Not persisted")]),
		);

		$this->expectException(InvalidEntityStateException::class);
		try {
			$this->repository->insert($lesson);
		}
		finally {
			self::assertFalse(isset($lesson->id));
			self::assertSame(0, $this->scalar("select count(*) from Lesson"));
		}
	}

	public function testInsertDoesNotCascadeRelatedEntity():void {
		$note = new StudentNote($this->newStudent("Not persisted"), "A note");

		$this->expectException(InvalidEntityStateException::class);
		try {
			$this->repository->insert($note);
		}
		finally {
			self::assertFalse(isset($note->id));
			self::assertSame(0, $this->scalar("select count(*) from StudentNote"));
		}
	}

	public function testUpdatePersistsEntityAndRefreshesCache():void {
		$student = $this->repository->insert($this->newStudent("Ada"));
		$changed = $student->with(["name" => "Ada King"]);

		$updated = $this->repository->update($changed);

		self::assertSame($changed, $updated);
		self::assertSame("Ada", $student->name);
		self::assertSame("Ada King", $updated->name);
		self::assertSame($student->id, $updated->id);
		self::assertSame($updated, $this->repository->fetch(Student::class, 1));
		self::assertSame(
			"Ada King",
			$this->scalar("select name from Student where id = 1"),
		);
	}

	public function testUpdateClassByPrimaryKey():void {
		$student = $this->repository->insert($this->newStudent("Ada"));

		self::assertSame(1, $this->repository->update(
			Student::class,
			$student->id,
			["name" => "Greg"],
		));
		self::assertSame("Greg", $this->scalar(
			"select name from Student where id = 1",
		));
	}

	public function testUpdateClassByCondition():void {
		$this->repository->insert($this->newStudent("Ada"));
		$this->repository->insert($this->newStudent("Grace"));

		self::assertSame(1, $this->repository->update(
			Student::class,
			["nickname" => "Amazing"],
			new AndCondition("name = 'Grace'"),
		));
		self::assertSame(
			[["name" => "Ada", "nickname" => null], [
				"name" => "Grace",
				"nickname" => "Amazing",
			]],
			$this->rows("select name, nickname from Student order by id"),
		);
	}

	public function testUpdateClassSupportsBooleanFieldValueMatch():void {
		$this->repository->insert($this->newStudent("Active"));
		$this->repository->insert(new Student(
			"Inactive",
			new DateTimeImmutable("2000-01-01"),
			false,
		));

		self::assertSame(1, $this->repository->update(
			Student::class,
			["nickname" => "Matched"],
			"active",
			false,
		));
		self::assertSame("Matched", $this->scalar(
			"select nickname from Student where active = 0",
		));
	}

	public function testUpdateMapsAllSupportedValueKinds():void {
		$first = $this->repository->insert($this->newStudent("First"));
		$second = $this->repository->insert($this->newStudent("Second"));
		$note = $this->repository->insert(new StudentNote($first, "Original"));
		$dateOfBirth = new DateTimeImmutable(
			"2020-06-01 10:30:00",
			new DateTimeZone("Europe/London"),
		);

		$updatedStudent = $this->repository->update($first->with([
			"dateOfBirth" => $dateOfBirth,
			"active" => false,
			"status" => StudentStatus::INACTIVE,
			"nickname" => "A",
		]));
		$updatedNote = $this->repository->update($note->with([
			"student" => $second,
			"text" => "Updated",
		]));

		self::assertSame("2020-06-01 09:30:00.000000", $this->scalar(
			"select dateOfBirth from Student where id = 1",
		));
		self::assertFalse($updatedStudent->active);
		self::assertSame(StudentStatus::INACTIVE, $updatedStudent->status);
		self::assertSame("A", $updatedStudent->nickname);
		self::assertSame($second, $updatedNote->student);
		self::assertSame(
			["student_Student_id" => 2, "text" => "Updated"],
			$this->requiredRow(
				"select student_Student_id, text from StudentNote where id = 1",
			),
		);

		$fetchedNote = (new Repository($this->database))->fetch(StudentNote::class, 1);
		self::assertSame("Second", $fetchedNote->student->name);
		self::assertNull($fetchedNote->reviewer);
		$fetchedStudent = (new Repository($this->database))->fetch(Student::class, 1);
		self::assertSame(
			"2020-06-01 09:30:00.000000+00:00",
			$fetchedStudent->dateOfBirth->format("Y-m-d H:i:s.uP"),
		);
	}

	public function testUpdateRejectsEntityWithoutInitialisedPrimaryKey():void {
		$this->expectException(InvalidEntityStateException::class);
		$this->expectExceptionMessage("Student::\$id is not initialised");
		$this->repository->update($this->newStudent("Not persisted"));
	}

	public function testUpdateRejectsUnpersistedRelatedEntity():void {
		$student = $this->repository->insert($this->newStudent("Persisted"));
		$note = $this->repository->insert(new StudentNote($student, "Original"));

		$this->expectException(InvalidEntityStateException::class);
		try {
			$this->repository->update($note->with([
				"student" => $this->newStudent("Not persisted"),
			]));
		}
		finally {
			self::assertSame(1, $this->scalar(
				"select student_Student_id from StudentNote where id = 1",
			));
		}
	}

	public function testInsertParticipatesInExistingTransaction():void {
		$connection = $this->database->getDriver()->getConnection();
		$connection->beginTransaction();

		$this->repository->insert(new ManualKeyEntity("transactional", "value"));

		self::assertTrue($connection->inTransaction());
		$connection->rollBack();
		self::assertSame(0, $this->scalar(
			"select count(*) from ManualKeyEntity",
		));
	}

	public function testUpdateReturnsPassedEntity():void {
		$student = $this->repository->insert($this->newStudent("Ada"));

		self::assertSame($student, $this->repository->update($student));
	}

	public function testUpdateDoesNotReplaceCollectionRows():void {
		$lesson = $this->repository->insert(new Lesson(
			"Databases",
			new StudentCollection(),
		));

		$updated = $this->repository->update($lesson->with([
			"students" => new StudentCollection(),
		]));

		self::assertSame($lesson->id, $updated->id);
		self::assertSame(0, $this->scalar(
			"select count(*) from Lesson_students_Student",
		));
	}

	/** @param class-string ...$entityList */
	private function createSchema(string ...$entityList):void {
		$tableList = (new SchemaGenerator())->generateAll(...$entityList);
		foreach($tableList as $table) {
			$this->database->executeSql(
				(new SchemaQuerySQLite($table))->generateSql(),
			);
		}
	}

	private function newStudent(string $name):Student {
		return new Student($name, new DateTimeImmutable("2000-01-01"));
	}

	/** @return array<string, mixed> */
	private function requiredRow(string $sql):array {
		$row = $this->database->executeSql($sql)->fetch();
		self::assertNotNull($row);
		return $row->asArray();
	}

	/** @return array<array<string, mixed>> */
	private function rows(string $sql):array {
		return array_map(
			fn($row) => $row->asArray(),
			$this->database->executeSql($sql)->fetchAll(),
		);
	}

	private function scalar(string $sql):mixed {
		$row = $this->database->executeSql($sql)->fetch();
		self::assertNotNull($row);
		return array_values($row->asArray())[0] ?? null;
	}
}
