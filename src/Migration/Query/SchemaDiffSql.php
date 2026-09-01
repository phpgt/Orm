<?php
namespace GT\Orm\Migration\Query;

use GT\Orm\Migration\SchemaDiff;

interface SchemaDiffSql {
	/** @return array<string> */
	public function generateSqlList(SchemaDiff $diff):array;
}
