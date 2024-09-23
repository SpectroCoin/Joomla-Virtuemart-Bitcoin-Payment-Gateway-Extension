<?php

declare(strict_types=1);

namespace SpectroCoin\SCMerchantClient\Enum;

defined('_JEXEC') or die('Restricted access');

enum OrderStatus: int
{
	case New = 1;
	case Pending = 2;
	case Paid = 3;
	case Failed = 4;
	case Expired = 5;
}