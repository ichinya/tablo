<?php
declare(strict_types=1);
require __DIR__ . '/synthetic.php';
require_once dirname(__DIR__, 2) . '/app/RuntimeIni.php';
$nestedChecks = 0;
function nested_check(bool $value): void {
    global $nestedChecks; $nestedChecks++;
    if (!$value) { throw new RuntimeException('Nested scalar diagnostic control failed.'); }
}
$root=dirname(__DIR__,2);
$actor=file_get_contents(__DIR__.'/actor.php');
$owners=['app/ServerTableAdmission.php'=>6,'app/DatabaseTransferVerification.php'=>9,'app/SchemaShape.php'=>9,'app/DatabaseTransferWire.php'=>3,'app/DatabaseTransferRows.php'=>12,'app/DatabaseTransferArchive.php'=>11,'app/DatabaseTransferPreflight.php'=>2];
\Tablo\RuntimeIni::$coldNestedProbe = null;
foreach($owners as$owner=>$expectedCount){
    $text=str_replace("\r\n","\n",file_get_contents($root.'/'.$owner));
    preg_match_all('/^ *\/\/ COLD_NESTED_BEGIN ([0-9]+)\n(.*?)^ *\/\/ COLD_NESTED_END \1\n/ms',$text,$matches,PREG_SET_ORDER);
    nested_check(count($matches)===$expectedCount);
    foreach($matches as$match){
        $compact=preg_replace('/\s+/','',$match[2]);
        $expected='RuntimeIni::$coldNestedProbe?->__invoke('.$match[1].',';
        nested_check(str_starts_with($compact,$expected) && str_contains($compact,',memory_get_peak_usage(false)'));
        nested_check(substr_count($compact,'memory_get_peak_usage(false)')===1);
        $newCurrent = in_array((int)$match[1], [640,641,642,643,700,701,704,705,710,711,712,713,714,715,716,800,801], true);
        nested_check(substr_count($compact,'memory_get_usage(false)') === ($newCurrent ? 1 : 0));
        if ($newCurrent) { nested_check(str_ends_with($compact, ',memory_get_usage(false));')); }
        // Execute only the actual authored guarded probe block, never the application owner.
        eval('namespace Tablo;' . $match[2]);
        nested_check(!isset(\Tablo\RuntimeIni::$coldNestedProbe));
    }
}
preg_match('/    \/\/ COLD_NESTED_SETUP_BEGIN\n(.*?)    \/\/ COLD_NESTED_SETUP_END\n/s',$actor,$setup);
nested_check(isset($setup[1]) && strpos($actor,'COLD_NESTED_SETUP_END')<strpos($actor,'$baseline = memory_get_usage(false);'));
foreach(['PDO','Subprocess','DatabaseTransfer','require','file_','memory_reset','memory_get']as$forbidden){nested_check(!str_contains($setup[1],$forbidden));}
// The exact trusted setup contains scalar initializers and callback construction only.
eval($setup[1]);
$slots=['tablo_cold_nested_peak','tablo_cold_nested_step','tablo_cold_nested_table','tablo_cold_nested_outer','tablo_cold_nested_active_outer','tablo_cold_nested_current','tablo_cold_nested_previous_step','tablo_cold_nested_previous_peak','tablo_cold_nested_last_step','tablo_cold_nested_last_peak'];
foreach($slots as$slot){nested_check(is_int($GLOBALS[$slot]) && $GLOBALS[$slot]===0);}
$keysBefore=array_values(array_filter(array_keys($GLOBALS), static fn (string $key): bool => str_starts_with($key, 'tablo_cold_nested_')));
$GLOBALS['tablo_cold_nested_peak']=1000;$GLOBALS['tablo_cold_nested_active_outer']=4;
(\Tablo\RuntimeIni::$coldNestedProbe)(120,1500,'sites');
nested_check($GLOBALS['tablo_cold_nested_peak']===1500 && $GLOBALS['tablo_cold_nested_step']===120 && $GLOBALS['tablo_cold_nested_table']===12 && $GLOBALS['tablo_cold_nested_outer']===4);
(\Tablo\RuntimeIni::$coldNestedProbe)(150,1500,'users');
nested_check($GLOBALS['tablo_cold_nested_step']===120 && $GLOBALS['tablo_cold_nested_table']===12);
(\Tablo\RuntimeIni::$coldNestedProbe)(1,1600);
nested_check($GLOBALS['tablo_cold_nested_peak']===1600 && $GLOBALS['tablo_cold_nested_step']===1 && $GLOBALS['tablo_cold_nested_table']===0);
$GLOBALS['tablo_cold_nested_active_outer']=9;(\Tablo\RuntimeIni::$coldNestedProbe)(406,1700,'sites');
(\Tablo\RuntimeIni::$coldNestedProbe)(210,1700);
nested_check($GLOBALS['tablo_cold_nested_step']===406 && $GLOBALS['tablo_cold_nested_outer']===9);
(\Tablo\RuntimeIni::$coldNestedProbe)(408,1800,'PRIVATE_SQL_PATH_SECRET');
nested_check($GLOBALS['tablo_cold_nested_table']===20);
nested_check($keysBefore === array_values(array_filter(array_keys($GLOBALS), static fn (string $key): bool => str_starts_with($key, 'tablo_cold_nested_'))));
foreach($slots as$slot){nested_check(is_int($GLOBALS[$slot]));}
foreach(['nestedStep','nestedTable','nestedOuterPhase','nestedPreviousStep']as$field){
    foreach(['PRIVATE_SQL_PATH_SECRET',[],false,1,1.0,null]as$bad){
        $mutant=$record;$mutant[$field]=$bad;
        $safe=cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport);
        nested_check($safe['measurementComplete']===false);
        nested_check(!str_contains(json_encode($safe,JSON_THROW_ON_ERROR),'PRIVATE_SQL_PATH_SECRET'));
    }
}
foreach ([624 => 'CODEC_ASCII_FRAMING_AFTER', 625 => 'CODEC_ASCII_TYPED_AFTER'] as $id => $label) {
    nested_check(str_contains($actor, $id . " => '" . $label . "'"));
    $mutant=$record;$mutant['nestedStep']=$label;
    nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
}
// A tie updates the last boundary, while the first winning witness remains unchanged.
$GLOBALS['tablo_cold_nested_peak']=1000;$GLOBALS['tablo_cold_nested_last_peak']=1000;
$GLOBALS['tablo_cold_nested_last_step']=0;$GLOBALS['tablo_cold_nested_current']=0;
(\Tablo\RuntimeIni::$coldNestedProbe)(700,1500,'sites',1200);
nested_check($GLOBALS['tablo_cold_nested_previous_step']===0 && $GLOBALS['tablo_cold_nested_previous_peak']===1000);
(\Tablo\RuntimeIni::$coldNestedProbe)(701,1500,'sites',1100);
nested_check($GLOBALS['tablo_cold_nested_step']===700 && $GLOBALS['tablo_cold_nested_last_step']===701 && $GLOBALS['tablo_cold_nested_last_peak']===1500);
(\Tablo\RuntimeIni::$coldNestedProbe)(704,1600,'sites',1400);
nested_check($GLOBALS['tablo_cold_nested_step']===704 && $GLOBALS['tablo_cold_nested_previous_step']===701 && $GLOBALS['tablo_cold_nested_previous_peak']===1500 && $GLOBALS['tablo_cold_nested_current']===1400);
(\Tablo\RuntimeIni::$coldNestedProbe)(705,1700,'sites',1300);
nested_check($GLOBALS['tablo_cold_nested_previous_step']===704 && $GLOBALS['tablo_cold_nested_previous_peak']===1600 && $GLOBALS['tablo_cold_nested_current']===1300);
(\Tablo\RuntimeIni::$coldNestedProbe)(640,1700,'sites',1200);
(\Tablo\RuntimeIni::$coldNestedProbe)(641,1800,'sites',1000);
nested_check($GLOBALS['tablo_cold_nested_previous_step']===640 && $GLOBALS['tablo_cold_nested_previous_peak']===1700);
// Current is sampled after peak but before callback body; normalize a newly observed growth.
(\Tablo\RuntimeIni::$coldNestedProbe)(642,1900,'sites',2000);
nested_check($GLOBALS['tablo_cold_nested_peak']===2000 && $GLOBALS['tablo_cold_nested_last_peak']===2000 && $GLOBALS['tablo_cold_nested_current']===2000);
(\Tablo\RuntimeIni::$coldNestedProbe)(620,2100);
nested_check($GLOBALS['tablo_cold_nested_current']===0 && $GLOBALS['tablo_cold_nested_previous_step']===642 && $GLOBALS['tablo_cold_nested_previous_peak']===2000);
nested_check($keysBefore === array_values(array_filter(array_keys($GLOBALS), static fn (string $key): bool => str_starts_with($key, 'tablo_cold_nested_'))));
foreach (['nestedCurrentPhpBytes','nestedPreviousPeakPhpBytes'] as $field) {
    $mutant=$record;$mutant[$field]=null;
    nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
    foreach (['PRIVATE_SQL_PATH_SECRET',[],false,true,1.0,-1,0,1_073_741_825,$record['peakPhpBytes']+1] as $bad) {
        $mutant=$record;$mutant[$field]=$bad;
        $safe=cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport);
        nested_check($safe['measurementComplete']===false);
        nested_check(!str_contains(json_encode($safe,JSON_THROW_ON_ERROR),'PRIVATE_SQL_PATH_SECRET'));
    }
}
$mutant=$record;$mutant['nestedPreviousPeakPhpBytes']=$record['peakPhpBytes'];
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===false);
$mutant=$record;$mutant['nestedPreviousStep']='BASELINE';$mutant['nestedPreviousPeakPhpBytes']=$record['baselinePhpBytes'];
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "700 => 'ARCHIVE_CHUNK_LINE_BEFORE'"));
$mutant=$record;$mutant['nestedStep']='ARCHIVE_CHUNK_LINE_BEFORE';$mutant['nestedPreviousStep']='ARCHIVE_CHUNK_LINE_BEFORE';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "701 => 'ARCHIVE_CHUNK_LINE_AFTER'"));
$mutant=$record;$mutant['nestedStep']='ARCHIVE_CHUNK_LINE_AFTER';$mutant['nestedPreviousStep']='ARCHIVE_CHUNK_LINE_AFTER';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "704 => 'ARCHIVE_CONSUMER_BEFORE'"));
$mutant=$record;$mutant['nestedStep']='ARCHIVE_CONSUMER_BEFORE';$mutant['nestedPreviousStep']='ARCHIVE_CONSUMER_BEFORE';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "705 => 'ARCHIVE_CONSUMER_AFTER'"));
$mutant=$record;$mutant['nestedStep']='ARCHIVE_CONSUMER_AFTER';$mutant['nestedPreviousStep']='ARCHIVE_CONSUMER_AFTER';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "710 => 'ARCHIVE_BODY_READ_BEFORE'"));
$mutant=$record;$mutant['nestedStep']='ARCHIVE_BODY_READ_BEFORE';$mutant['nestedPreviousStep']='ARCHIVE_BODY_READ_BEFORE';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "711 => 'ARCHIVE_BODY_READ_AFTER'"));
$mutant=$record;$mutant['nestedStep']='ARCHIVE_BODY_READ_AFTER';$mutant['nestedPreviousStep']='ARCHIVE_BODY_READ_AFTER';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "712 => 'ARCHIVE_BODY_CONCAT_RELEASE_AFTER'"));
$mutant=$record;$mutant['nestedStep']='ARCHIVE_BODY_CONCAT_RELEASE_AFTER';$mutant['nestedPreviousStep']='ARCHIVE_BODY_CONCAT_RELEASE_AFTER';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "713 => 'ARCHIVE_LF_READ_BEFORE'"));
$mutant=$record;$mutant['nestedStep']='ARCHIVE_LF_READ_BEFORE';$mutant['nestedPreviousStep']='ARCHIVE_LF_READ_BEFORE';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "714 => 'ARCHIVE_LF_READ_AFTER'"));
$mutant=$record;$mutant['nestedStep']='ARCHIVE_LF_READ_AFTER';$mutant['nestedPreviousStep']='ARCHIVE_LF_READ_AFTER';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "715 => 'ARCHIVE_LINE_HASH_BEFORE'"));
$mutant=$record;$mutant['nestedStep']='ARCHIVE_LINE_HASH_BEFORE';$mutant['nestedPreviousStep']='ARCHIVE_LINE_HASH_BEFORE';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "716 => 'ARCHIVE_LINE_HASH_AFTER'"));
$mutant=$record;$mutant['nestedStep']='ARCHIVE_LINE_HASH_AFTER';$mutant['nestedPreviousStep']='ARCHIVE_LINE_HASH_AFTER';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "800 => 'PREFLIGHT_SQLITE_INSERT_BEFORE'"));
$mutant=$record;$mutant['nestedStep']='PREFLIGHT_SQLITE_INSERT_BEFORE';$mutant['nestedPreviousStep']='PREFLIGHT_SQLITE_INSERT_BEFORE';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "801 => 'PREFLIGHT_SQLITE_INSERT_AFTER'"));
$mutant=$record;$mutant['nestedStep']='PREFLIGHT_SQLITE_INSERT_AFTER';$mutant['nestedPreviousStep']='PREFLIGHT_SQLITE_INSERT_AFTER';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "640 => 'ROWS_INSERT_PREPARE_AFTER'"));
$mutant=$record;$mutant['nestedStep']='ROWS_INSERT_PREPARE_AFTER';$mutant['nestedPreviousStep']='ROWS_INSERT_PREPARE_AFTER';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "641 => 'ROWS_INSERT_BIND_AFTER'"));
$mutant=$record;$mutant['nestedStep']='ROWS_INSERT_BIND_AFTER';$mutant['nestedPreviousStep']='ROWS_INSERT_BIND_AFTER';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "642 => 'ROWS_INSERT_EXECUTE_AFTER'"));
$mutant=$record;$mutant['nestedStep']='ROWS_INSERT_EXECUTE_AFTER';$mutant['nestedPreviousStep']='ROWS_INSERT_EXECUTE_AFTER';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
nested_check(str_contains($actor, "643 => 'ROWS_INSERT_CLOSE_AFTER'"));
$mutant=$record;$mutant['nestedStep']='ROWS_INSERT_CLOSE_AFTER';$mutant['nestedPreviousStep']='ROWS_INSERT_CLOSE_AFTER';
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===true);
$mutant=$record;$mutant['nestedPeakPhpBytes']--;
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)."\n",'',$transport)['measurementComplete']===false);
echo json_encode(['schema'=>'tablo/cold-nested-controls/v1','status'=>'passed','checks'=>$nestedChecks,
    'guardedAppObservations'=>52,'baselineGuardNoop'=>true,'strictLargestAndFirstTie'=>true,'outerFallback'=>true,
    'actorLaunchCount'=>0,'databaseCalls'=>0,'issueAccepted'=>false],JSON_THROW_ON_ERROR),"\n";
