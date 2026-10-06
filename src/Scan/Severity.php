<?php

declare(strict_types=1);

namespace Magna\Seo\Scan;

/**
 * How serious a scan finding is. `error` = a real indexing problem, `warning` =
 * likely to hurt, `notice` = worth a look.
 */
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';
}
