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
$owners=['app/ServerTableAdmission.php'=>6,'app/DatabaseTransferVerification.php'=>9,'app/SchemaShape.php'=>9,'app/DatabaseTransferWire.php'=>3,'app/DatabaseTransferRows.php'=>8];
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
$slots=['tablo_cold_nested_peak','tablo_cold_nested_step','tablo_cold_nested_table','tablo_cold_nested_outer','tablo_cold_nested_active_outer'];
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
foreach(['nestedStep','nestedTable','nestedOuterPhase']as$field){
    foreach(['PRIVATE_SQL_PATH_SECRET',[],false,1,1.0,null]as$bad){
        $mutant=$record;$mutant[$field]=$bad;
        $safe=cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR)."\n",'',$transport);
        nested_check($safe['measurementComplete']===false);
        nested_check(!str_contains(json_encode($safe,JSON_THROW_ON_ERROR),'PRIVATE_SQL_PATH_SECRET'));
    }
}
foreach ([624 => 'CODEC_ASCII_FRAMING_AFTER', 625 => 'CODEC_ASCII_TYPED_AFTER'] as $id => $label) {
    nested_check(str_contains($actor, $id . " => '" . $label . "'"));
    $mutant=$record;$mutant['nestedStep']=$label;
    nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR)."\n",'',$transport)['measurementComplete']===true);
}
$mutant=$record;$mutant['nestedPeakPhpBytes']--;
nested_check(cold_phase_project('mysql',json_encode($mutant,JSON_THROW_ON_ERROR)."\n",'',$transport)['measurementComplete']===false);
echo json_encode(['schema'=>'tablo/cold-nested-controls/v1','status'=>'passed','checks'=>$nestedChecks,
    'guardedAppObservations'=>35,'baselineGuardNoop'=>true,'strictLargestAndFirstTie'=>true,'outerFallback'=>true,
    'actorLaunchCount'=>0,'databaseCalls'=>0,'issueAccepted'=>false],JSON_THROW_ON_ERROR),"\n";
