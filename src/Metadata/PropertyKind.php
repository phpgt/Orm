<?php
namespace GT\Orm\Metadata;

enum PropertyKind {
	case SCALAR;
	case DATE_TIME;
	case BACKED_ENUM;
	case ENTITY;
	case COLLECTION;
}
