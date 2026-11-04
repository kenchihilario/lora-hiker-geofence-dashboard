<?php
require_once __DIR__.'/../src/Services/RegistryService.php';
if(registry_location(['latitude'=>'14.6','longitude'=>'121.0'])!==[14.6,121.0])throw new RuntimeException('Valid coordinates were rejected.');
if(registry_location(['latitude'=>'','longitude'=>''])!==[null,null])throw new RuntimeException('Unspecified coordinates must remain null.');
foreach([['latitude'=>12],['latitude'=>'91','longitude'=>'1'],['latitude'=>'bad','longitude'=>'1']] as $input){$failed=false;try{registry_location($input);}catch(InvalidArgumentException $e){$failed=true;}if(!$failed)throw new RuntimeException('Invalid or partial coordinates were accepted.');}
if(registry_boolean(['active'=>'0'],'active',true)!==0||registry_boolean([],'active',true)!==1)throw new RuntimeException('Boolean parsing failed.');
$failed=false;try{registry_text(['label'=>str_repeat('x',101)],'label',100);}catch(InvalidArgumentException $e){$failed=true;}if(!$failed)throw new RuntimeException('Oversized label was accepted.');
echo "PASS registry validation: coordinates, null defaults, booleans, and label bounds.\n";
