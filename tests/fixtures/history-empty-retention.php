<?php
declare(strict_types=1);

// Represent an explicitly empty declaration, including Windows' empty putenv qualification.
putenv('TABLO_HISTORY_RETENTION_DAYS=');
$_ENV['TABLO_HISTORY_RETENTION_DAYS'] = '';
