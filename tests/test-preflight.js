const fs=require('node:fs');
const path=require('node:path');
const {execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..');
const modeFile=path.join(root,'data','mode.json');
if(process.env.TRAILWATCH_SCRATCH_TEST!=='1'&&fs.existsSync(modeFile)){
  let mode='DEMO';
  try{const value=JSON.parse(fs.readFileSync(modeFile,'utf8')).mode;if(value==='LIVE'||value==='DEMO')mode=value;}catch{}
  if(mode==='LIVE')throw new Error('REFUSED: tests are disabled while LIVE mode is active.');
}
execFileSync('C:\\xampp\\php\\php.exe',[path.join(__dirname,'test_profile_probe.php')],{stdio:'pipe'});
