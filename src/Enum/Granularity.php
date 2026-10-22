<?php

declare(strict_types=1);

namespace Ages\GoogleGateway\Enum;

/**
 * Time breakdown of statistics; the value is the matching GAQL `segments.*` field.
 */
enum Granularity: string
{
    case Daily = 'date';
    case Weekly = 'week';
    case Monthly = 'month';
    case Quarterly = 'quarter';
    case Yearly = 'year';
}
