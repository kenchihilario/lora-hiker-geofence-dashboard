require('./test-preflight.js');
const fs=require('node:fs');const path=require('node:path');const root=path.resolve(__dirname,'..');
const files=['public/index.php','public/safe-zone.php','public/settings.php','public/assets/js/dashboard.js','public/assets/js/dashboard-label-layout.js','public/assets/js/source-flags.js','public/assets/js/safe-zone.js','public/assets/js/safe-zone-geometry.js','public/assets/js/site-settings.js','public/assets/css/dashboard.css'];
let fail=false;for(const file of files){const text=fs.readFileSync(path.join(root,file),'utf8');const remote=/(https?:\/\/(?!127\.0\.0\.1|localhost|\[::1\])|tile\.openstreetmap\.org|L\.tileLayer|\/tiles(?:\/|["' ]))/i.test(text);console.log(`${remote?'FAIL':'PASS'} ${file} contains no external URLs or tile layer`);fail ||= remote;}
process.exitCode=fail?1:0;
