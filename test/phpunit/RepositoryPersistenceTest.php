<?php
namespace GT\Orm\Test;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use GT\Database\Database;
use GT\Orm\Exception\InvalidEntityChangeException;
use GT\Orm\Exception\InvalidEntityStateException;
use GT\Orm\Migration\Query\SchemaQuerySQLite;
use GT\Orm\Migration\SchemaGenerator;
use GT\Orm\Repository;
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
use PHPUnit\Framework\Attributes\DataProvider;
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
			$this->repository->delete(ManualKeyEntity::class, "known-key"),
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

	public function testDeleteByFieldValue():void {
		$this->repository->insert($this->newStudent("Ada"));
		$this->repository->insert($this->newStudent("Grace"));

		self::assertSame(
			1,
			$this->repository->delete(Student::class, "name", "Ada"),
		);
		self::assertSame(
			["Grace"],
			array_column($this->rows("select name from Student"), "name"),
		);
	}

	public function testDeleteByCondition():void {
		$this->repository->insert($this->newStudent("Ada"));
		$this->repository->insert($this->newStudent("Grace"));

		self::assertSame(
			1,
			$this->repository->delete(
				Student::class,
				new AndCondition("name = 'Grace'"),
			),
		);
		self::assertSame(
			["Ada"],
			array_column($this->rows("select name from Student"), "name"),
		);
	}

	public function testDeleteRequiresMatch():void {
		$this->repository->insert($this->newStudent("Ada"));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(
			"Delete requires at least one non-empty match condition",
		);
		try {
			$this->repository->delete(Student::class);
		}
		finally {
			self::assertSame(1, $this->scalar(
				"select count(*) from Student",
			));
		}
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
		$entity = new DefaultOnlyEntity();
		$entity->value = "from PHP";

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

	public function testUpdateReturnsReadonlyReplacementAndRefreshesCache():void {
		$student = $this->repository->insert($this->newStudent("Ada"));

		$updated = $this->repository->update($student, ["name" => "Ada King"]);

		self::assertNotSame($student, $updated);
		self::assertSame("Ada", $student->name);
		self::assertSame("Ada King", $updated->name);
		self::assertSame($student->id, $updated->id);
		self::assertSame($updated, $this->repository->fetch(Student::class, 1));
		self::assertSame(
			"Ada King",
			$this->scalar("select name from Student where id = 1"),
		);
	}

	public function testUpdateMapsAllSupportedValueKinds():void {
		$first = $this->repository->insert($this->newStudent("First"));
		$second = $this->repository->insert($this->newStudent("Second"));
		$note = $this->repository->insert(new StudentNote($first, "Original"));
		$dateOfBirth = new DateTimeImmutable(
			"2020-06-01 10:30:00",
			new DateTimeZone("Europe/London"),
		);

		$updatedStudent = $this->repository->update($first, [
			"dateOfBirth" => $dateOfBirth,
			"active" => false,
			"status" => StudentStatus::INACTIVE,
			"nickname" => "A",
		]);
		$updatedNote = $this->repository->update($note, [
			"student" => $second,
			"text" => "Updated",
		]);

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
		$this->repository->update($this->newStudent("Not persisted"), [
			"name" => "Changed",
		]);
	}

	public function testUpdateRejectsUnpersistedRelatedEntity():void {
		$student = $this->repository->insert($this->newStudent("Persisted"));
		$note = $this->repository->insert(new StudentNote($student, "Original"));

		$this->expectException(InvalidEntityStateException::class);
		try {
			$this->repository->update($note, [
				"student" => $this->newStudent("Not persisted"),
			]);
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

	public function testUpdateWithNoChangesReturnsOriginalEntity():void {
		$student = $this->repository->insert($this->newStudent("Ada"));

		self::assertSame($student, $this->repository->update($student, []));
	}

	/** @param array<string, mixed> $changeList */
	#[DataProvider("invalidChangeProvider")]
	public function testUpdateRejectsInvalidChanges(
		array $changeList,
		string $message,
	):void {
		$student = $this->repository->insert($this->newStudent("Ada"));

		try {
			$this->repository->update($student, $changeList);
			self::fail("Expected the invalid change to be rejected");
		}
		catch(InvalidEntityChangeException $exception) {
			self::assertStringContainsString($message, $exception->getMessage());
		}

		self::assertSame("Ada", $this->scalar(
			"select name from Student where id = 1",
		));
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function invalidChangeProvider():array {
		return [
			"unknown property" => [["missing" => "value"], "does not have"],
			"primary key" => [["id" => 2], "cannot be updated"],
			"wrong PHP type" => [["name" => 123], "does not match its PHP type"],
		];
	}

	public function testUpdateRejectsCollectionChanges():void {
		$lesson = $this->repository->insert(new Lesson(
			"Databases",
			new StudentCollection(),
		));

		$this->expectException(InvalidEntityChangeException::class);
		$this->expectExceptionMessage("cannot be updated as a row");
		$this->repository->update($lesson, [
			"students" => new StudentCollection(),
		]);
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
