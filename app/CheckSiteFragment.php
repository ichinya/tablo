<?php
declare(strict_types=1);

namespace Tablo;

final class CheckSiteFragment
{
    public static function prepare(CheckFormat $format,CheckProgress $progress,#[\SensitiveParameter] array $site,#[\SensitiveParameter] array $state): void
    {
        if ($format !== CheckFormat::Json) { return; }
        $progress->phase='serializing';
        $progress->record=CheckOutput::site($site,'checked','ok',false,new CheckSiteObservation($state));
        CheckOutput::encode($progress->record); // Validate before any store attempt.
    }
    public static function accepted(CheckFormat $format,CheckProgress $progress,CheckSettlementState $state): void
    {
        if ($format !== CheckFormat::Json) { return; }
        $progress->phase='serializing';
        CheckSettlementOutput::settle($progress->record,$state);
        $progress->fragments[]=CheckOutput::encode($progress->record);
    }
    public static function paused(CheckFormat $format,CheckProgress $progress,#[\SensitiveParameter] array $site): void
    {
        if ($format === CheckFormat::Json) { $progress->fragments[]=CheckOutput::encode(CheckOutput::site($site,'paused','disabled',null)); }
    }
    public static function invalid(CheckFormat $format,CheckProgress $progress,#[\SensitiveParameter] array $site): void
    {
        if ($format === CheckFormat::Json) { $progress->fragments[]=CheckOutput::encode(CheckOutput::site($site,'failed','site-validation-failed',true)); }
    }
}
