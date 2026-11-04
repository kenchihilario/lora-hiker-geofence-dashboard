require('./test-preflight.js');
const assert=require('node:assert/strict');
const {layout}=require('../public/assets/js/dashboard-label-layout.js');
const measure=(text,font)=>Array.from(text).reduce((sum,ch)=>sum+(['W','M'].includes(ch)?font*.9:font*.52),0);
for(const [name,x,y] of [['top-left',0,0],['top-right',800,0],['bottom-left',0,470],['bottom-right',800,470]]){
  const label='A lone marker with an unusually long trail junction label near the SVG edge';
  const result=layout(label,{x,y,width:800,height:470,scale:.5,fontPx:11,measureText:measure});
  assert.ok(result.bounds.left>=-1e-8,`${name} label left edge ${result.bounds.left}`);
  assert.ok(result.bounds.right<=800*.5+1e-8,`${name} label right edge ${result.bounds.right}`);
  assert.ok(result.bounds.top>=-1e-8,`${name} label top edge ${result.bounds.top}`);
  assert.ok(result.bounds.bottom<=470*.5+1e-8,`${name} label bottom edge ${result.bounds.bottom}`);
  assert.ok(result.display.length<label.length,`${name} label should be truncated`);
  assert.equal(result.full,label,'full label is retained for the SVG title');
  assert.ok(Math.abs(result.fontSize*.5-11)<1e-8,'rendered label should remain 11 CSS pixels');
}
console.log('PASS lone-marker labels stay inside all SVG extent edges, truncate visually, retain full title, and stay CSS-pixel sized');
