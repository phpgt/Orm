This repository is currently in a prototype stage. I'm planning on building it out properly, but it may never get completed, or I might change it completely without notice.

Please don't use on anything real until a stable release is made!

## Notes

Basic table creation is possible like this:

```php
$generator = new SchemaGenerator();
$studentSchemaTable = $generator->generate(Student::class);
```

Then the `$studentSchemaTable` can be passed to the actual underlying database for it to execute as SQL.

For example:

```php
$db->executeSQL($studentSchemaTable);
```

Here's an example of what the `Student` class looks like, and how the `SchemaGenerator` stringifies it as SQLite:

```php
readonly class Student {
	public function __construct(
		public int $id,
		public string $name,
		public DateTime $dob,
	) {}
}
```

```sql
create table `Student` (
	`id` int not null primary key,
	`name` text not null,
	`dob` int not null 
)
```

Some questions I need to answer before I go any further:

- How should the `DateTime` class be cast back and forth between different database engines? MySQL has a `datetime` type, but SQLite has to use a timestamp.
- How should the different constraints be handled? I like the idea of adding an attribute to describe the primary key: `#[PrimaryKey("id", PrimaryKey::AUTOINCREMENT)]`
- Straight-up foreign keys should be easy to implement - use a class as a public property.
- A common OOP technique is to have an array/iterable of objects. For example, the `Lesson` class can have an `array<Student>` or a custom `StudentCollection` class.
- This means a `StudentCollection` must be a differently derived class than `Student`, as it represents a junction table.

One big question I have yet to prototype:

- Cyclic dependencies are OK and sometimes really useful, especially in OOP land, but a recursive SQL query would be really inefficient on big data structures.
- The foreign key should not be loaded until it's used in code (lazy load), but this is going to require some clever programming for a good developer experience.

I think the way this should work is foreign keys are never done using joins - instead, separate queries should always be used. That way, the query that loads the referenced table will not need to be executed until the developer requests that field.

This could be achieved by the Orm generating an anonymous class that extends the referenced class, but takes on a trait to allow `__get` to execute the query... something like that, but I expect weird reflection will be required to make this transparent to the developer. 

# Proudly sponsored by

[JetBrains Open Source sponsorship program](https://www.jetbrains.com/community/opensource/)

[![JetBrains logo.](https://resources.jetbrains.com/storage/products/company/brand/logos/jetbrains.svg)](https://www.jetbrains.com/community/opensource/)
