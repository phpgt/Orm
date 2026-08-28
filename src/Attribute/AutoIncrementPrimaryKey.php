<?php
namespace GT\Orm\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class AutoIncrementPrimaryKey {}
