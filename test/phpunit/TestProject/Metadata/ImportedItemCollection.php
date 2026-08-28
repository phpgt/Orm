<?php
namespace GT\Orm\Test\TestProject\Metadata;

use GT\Orm\Collection;
use GT\Orm\Test\TestProject\ForeignKeys\University\Course as Subject;

/** @extends Collection<int, Subject> */
class ImportedItemCollection extends Collection {}
