<?php
namespace GT\Orm\Migration;

readonly class OrmSchemaRecord {
	public function __construct(
		public string $migratedAt,
		public string $schemaHash,
		public ?string $previousHash,
		public Schema $schema,
	) {}
}
