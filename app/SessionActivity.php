<?php
declare(strict_types=1);

namespace Tablo;

/** Server-owned route semantics; caller headers and query flags never select activity. */
enum SessionActivity
{
    case Automatic;
    case HumanPage;
    case DeliberateForm;
}
