require('./test-preflight.js');
const assert=require('node:assert/strict');
const {bufferTrackRings,bufferTrack,validateRings}=require('../public/assets/js/safe-zone-geometry.js');

// The real PUP oval route (13 points, first == last). It is a LOOP trail, so the corridor must be a ring.
const OVAL=[[14.5984572,121.0115997],[14.5980101,121.0116443],[14.5975654,121.0117268],[14.5973889,121.0119648],[14.5974087,121.0122211],[14.5976152,121.0124218],[14.5979353,121.0124309],[14.5983832,121.0123653],[14.5986655,121.012307],[14.5988001,121.01214],[14.5988167,121.0118694],[14.598665,121.0116463],[14.5984551,121.0115975]].map(([latitude,longitude])=>({latitude,longitude}));
const K=111320,cosL=Math.cos(14.5984*Math.PI/180);
const xy=p=>({x:p.longitude*cosL*K,y:p.latitude*K});
const ll=q=>({latitude:q.y/K,longitude:q.x/(cosL*K)});
// Same even-odd rule as src/geofence.php, extended over several rings.
function inside(pt,rings){const q=xy(pt);let r=false;for(const ring of rings){const P=ring.map(xy);for(let i=0,j=P.length-1;i<P.length;j=i++){const a=P[i],b=P[j];if((a.y>q.y)!==(b.y>q.y)&&q.x<(b.x-a.x)*(q.y-a.y)/(b.y-a.y)+a.x)r=!r;}}return r;}
function distToTrack(pt,track){const q=xy(pt),T=track.map(xy);let best=1e9;for(let i=0;i<T.length-1;i++){const a=T[i],b=T[i+1],dx=b.x-a.x,dy=b.y-a.y,t=Math.max(0,Math.min(1,((q.x-a.x)*dx+(q.y-a.y)*dy)/(dx*dx+dy*dy)));best=Math.min(best,Math.hypot(q.x-a.x-t*dx,q.y-a.y-t*dy));}return best;}

const W=10,res=bufferTrackRings(OVAL,W);
assert.equal(res.closed,true);assert.equal(res.rings.length,2);assert.equal(res.rings[0].length,12);assert.equal(res.rings[1].length,12);
assert.equal(validateRings(res.rings),'');
console.log('PASS closed oval becomes an outer ring and an inner ring');

assert.ok(OVAL.slice(0,12).every(p=>inside(p,res.rings)),'every track point must be inside the corridor');
for(let i=0;i<12;i++){const a=xy(OVAL[i]),b=xy(OVAL[i+1]);assert.ok(inside(ll({x:(a.x+b.x)/2,y:(a.y+b.y)/2}),res.rings));}
console.log('PASS the red line itself is inside the corridor');

const c=OVAL.slice(0,12).reduce((s,p)=>({x:s.x+xy(p).x/12,y:s.y+xy(p).y/12}),{x:0,y:0});
assert.equal(inside(ll(c),res.rings),false);assert.equal(inside(ll(c),[res.rings[0]]),true);
console.log('PASS middle of the oval is OUTSIDE (it would be inside if the loop were filled)');

let nIn=0,nOut=0;const base=xy(OVAL[0]);
for(let dx=-80;dx<=140;dx+=3)for(let dy=-70;dy<=70;dy+=3){const p=ll({x:base.x+dx,y:base.y+dy-45}),d=distToTrack(p,OVAL);
  if(d<=W*.95){assert.equal(inside(p,res.rings),true,`within ${d.toFixed(1)} m should be inside`);nIn++;}
  if(d>=W*1.1){assert.equal(inside(p,res.rings),false,`${d.toFixed(1)} m away should be outside`);nOut++;}}
assert.ok(nIn>100&&nOut>100);
console.log(`PASS corridor width holds: ${nIn} points within the margin inside, ${nOut} points beyond it outside`);

assert.equal(bufferTrack(OVAL,W).length,12);
assert.throws(()=>bufferTrackRings(OVAL,4),/5–50/);
const tiny=[[0,0],[30,0],[30,30],[0,30],[0,0]].map(([x,y])=>({latitude:14.8+y/110540,longitude:121+x/(Math.cos(14.8*Math.PI/180)*111320)}));
assert.throws(()=>bufferTrackRings(tiny,20),/too large/);
console.log('PASS half-width limits and a too-wide corridor for a small loop are rejected');

const bad=[res.rings[0],res.rings[0].map(v=>({latitude:v.latitude+.0005,longitude:v.longitude}))];
assert.notEqual(validateRings(bad),'');
assert.notEqual(validateRings([res.rings[0],res.rings[0].slice(0,2)]),'');
console.log('PASS rings that cross, or a ring with fewer than 3 points, are rejected');

const p=(x,y)=>({latitude:14.8+y/110540,longitude:121+x/(Math.cos(14.8*Math.PI/180)*111320)});
const elbow=bufferTrackRings([p(0,0),p(100,0),p(100,100)],10);
assert.equal(elbow.closed,false);assert.equal(elbow.rings[0].length,6);assert.equal(validateRings(elbow.rings),'');
const q=ll({x:xy(p(100,0)).x+9,y:xy(p(100,0)).y-9});
assert.equal(inside(q,elbow.rings),true);
console.log('PASS open L-shaped trail keeps full width at the corner');
