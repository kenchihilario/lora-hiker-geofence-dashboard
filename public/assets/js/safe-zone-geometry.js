(function(root,factory){const api=factory();if(typeof module==='object'&&module.exports)module.exports=api;else root.TrailGeometry=api;})(typeof globalThis!=='undefined'?globalThis:this,function(){
const M_LAT=110540,M_LON=111320,MITER_LIMIT=3,CLOSE_M=.5;

function checkPoints(points){
  if(!Array.isArray(points)||points.length<2)throw new Error('track needs at least two points');
  if(points.some(p=>!Number.isFinite(p.latitude)||!Number.isFinite(p.longitude)||Math.abs(p.latitude)>90||Math.abs(p.longitude)>180))throw new Error('track coordinates must be valid latitude/longitude pairs');
}
// Local flat frame in meters around the first point (same projection the editor and dashboard use).
function frame(points){
  const origin=points[0],cos=Math.cos(origin.latitude*Math.PI/180);
  return{origin,cos,
    toXY:p=>({x:(p.longitude-origin.longitude)*cos*M_LON,y:(p.latitude-origin.latitude)*M_LAT}),
    toLL:p=>({latitude:origin.latitude+p.y/M_LAT,longitude:origin.longitude+p.x/(cos*M_LON)})};
}
function signedArea(xy){let s=0;for(let i=0;i<xy.length;i++){const a=xy[i],b=xy[(i+1)%xy.length];s+=a.x*b.y-b.x*a.y;}return s/2;}
function unit(a,b){const dx=b.x-a.x,dy=b.y-a.y,l=Math.hypot(dx,dy)||1;return{x:dx/l,y:dy/l};}
const leftNormal=u=>({x:-u.y,y:u.x});
function dedupe(xy){return xy.filter((p,i)=>i===0||Math.hypot(p.x-xy[i-1].x,p.y-xy[i-1].y)>1e-6);}

// Offset a path sideways by d meters (positive = left of travel direction). Corners use a mitre so the
// distance to the track stays about d everywhere; very sharp corners are capped by MITER_LIMIT.
function offsetPath(xy,d,closed){
  const n=xy.length;
  return xy.map((p,i)=>{
    const hasPrev=closed||i>0,hasNext=closed||i<n-1;
    const n1=hasPrev?leftNormal(unit(xy[(i-1+n)%n],p)):null,n2=hasNext?leftNormal(unit(p,xy[(i+1)%n])):null;
    if(!n1||!n2){const q=n1||n2;return{x:p.x+q.x*d,y:p.y+q.y*d};}
    const mx=n1.x+n2.x,my=n1.y+n2.y,len=Math.hypot(mx,my);
    if(len<1e-9)return{x:p.x+n2.x*d,y:p.y+n2.y*d};
    const factor=Math.min(2/len,MITER_LIMIT);
    return{x:p.x+mx/len*d*factor,y:p.y+my/len*d*factor};
  });
}

/**
 * Turn a GPX track into a corridor of +/- width meters around it.
 * Open track  -> {closed:false, rings:[ one outline ]}
 * Closed loop -> {closed:true,  rings:[ outer edge, inner edge ]}  (the middle is NOT safe)
 */
function bufferTrackRings(points,width){
  checkPoints(points);
  if(!Number.isFinite(width)||width<5||width>50)throw new Error('half-width must be 5–50 meters');
  const f=frame(points);let xy=points.map(f.toXY);
  const closed=xy.length>3&&Math.hypot(xy[0].x-xy[xy.length-1].x,xy[0].y-xy[xy.length-1].y)<CLOSE_M;
  if(closed)xy.pop();
  xy=dedupe(xy);
  if(xy.length<2)throw new Error('track needs at least two distinct points');
  if(closed){
    if(xy.length<3)throw new Error('closed track needs at least three distinct points');
    const area=signedArea(xy);
    if(Math.abs(area)<1)throw new Error('closed track encloses no area');
    const outerD=area>0?-width:width; // counter-clockwise loop: outside is to the right
    const outer=offsetPath(xy,outerD,true),inner=offsetPath(xy,-outerD,true);
    const innerArea=signedArea(inner);
    // The inner edge collapses when an offset edge flips direction (reverses) or the area stops shrinking.
    const flipped=xy.some((p,i)=>{const q=xy[(i+1)%xy.length],a=inner[i],b=inner[(i+1)%inner.length];return(q.x-p.x)*(b.x-a.x)+(q.y-p.y)*(b.y-a.y)<=0;});
    if(flipped||Math.sign(innerArea)!==Math.sign(area)||Math.abs(innerArea)>=Math.abs(signedArea(outer)))
      throw new Error('half-width is too large for this loop: the inner edge collapses. Use a smaller half-width.');
    return{closed:true,rings:[outer.map(f.toLL),inner.map(f.toLL)]};
  }
  const left=offsetPath(xy,width,false),right=offsetPath(xy,-width,false).reverse();
  return{closed:false,rings:[[...left,...right].map(f.toLL)]};
}
// Back-compatible: one flat outline (the outer ring only).
function bufferTrack(points,width){return bufferTrackRings(points,width).rings[0];}

// Haversine length for the original GPX points; this does not alter or simplify the track.
function trackLengthMeters(points){
  checkPoints(points);const radius=6371008.8,rad=v=>v*Math.PI/180;let total=0;
  for(let i=1;i<points.length;i++){const a=points[i-1],b=points[i],dLat=rad(b.latitude-a.latitude),dLon=rad(b.longitude-a.longitude),lat1=rad(a.latitude),lat2=rad(b.latitude),h=Math.sin(dLat/2)**2+Math.cos(lat1)*Math.cos(lat2)*Math.sin(dLon/2)**2;total+=2*radius*Math.asin(Math.min(1,Math.sqrt(h)));}
  return total;
}

function pointSegmentDistance(point,a,b){const dx=b.x-a.x,dy=b.y-a.y,length=dx*dx+dy*dy;if(length===0)return Math.hypot(point.x-a.x,point.y-a.y);const t=Math.max(0,Math.min(1,((point.x-a.x)*dx+(point.y-a.y)*dy)/length));return Math.hypot(point.x-(a.x+t*dx),point.y-(a.y+t*dy));}
function simplifyTrack(points,toleranceMeters){if(!Array.isArray(points)||points.length<2)throw new Error('track needs at least two points');if(!Number.isFinite(toleranceMeters)||toleranceMeters<=0)throw new Error('simplification tolerance must be greater than 0 meters');if(points.some(p=>!Number.isFinite(p.latitude)||!Number.isFinite(p.longitude)||Math.abs(p.latitude)>90||Math.abs(p.longitude)>180))throw new Error('track coordinates must be valid latitude/longitude pairs');const origin=points[0],cos=Math.cos(origin.latitude*Math.PI/180),xy=points.map(p=>({x:(p.longitude-origin.longitude)*cos*111320,y:(p.latitude-origin.latitude)*110540}));const closed=xy.length>3&&Math.hypot(xy[0].x-xy[xy.length-1].x,xy[0].y-xy[xy.length-1].y)<.5,keep=new Uint8Array(points.length);keep[0]=1;keep[points.length-1]=1;const simplifyRange=(first,last)=>{keep[first]=1;keep[last]=1;const stack=[[first,last]];while(stack.length){const [start,end]=stack.pop();if(end-start<2)continue;let farthest=-1,maxDistance=toleranceMeters;for(let i=start+1;i<end;i++){const d=pointSegmentDistance(xy[i],xy[start],xy[end]);if(d>maxDistance){maxDistance=d;farthest=i;}}if(farthest>=0){keep[farthest]=1;stack.push([start,farthest],[farthest,end]);}}};if(closed){let anchor=1,maxDistance=-1;for(let i=1;i<points.length-1;i++){const d=Math.hypot(xy[i].x-xy[0].x,xy[i].y-xy[0].y);if(d>maxDistance){maxDistance=d;anchor=i;}}simplifyRange(0,anchor);simplifyRange(anchor,points.length-1);let kept=Array.from(keep,(v,i)=>v?i:-1).filter(i=>i>=0);if(kept.length<4){let candidate=-1,candidateDistance=-1;for(let i=1;i<points.length-1;i++){if(keep[i])continue;const d=pointSegmentDistance(xy[i],xy[0],xy[anchor]);if(d>candidateDistance){candidateDistance=d;candidate=i;}}if(candidate>=0)keep[candidate]=1;}}else simplifyRange(0,points.length-1);const indices=Array.from(keep,(v,i)=>v?i:-1).filter(i=>i>=0);let maxDeviationMeters=0;for(let s=0;s<indices.length-1;s++){const start=indices[s],end=indices[s+1];for(let i=start+1;i<end;i++)maxDeviationMeters=Math.max(maxDeviationMeters,pointSegmentDistance(xy[i],xy[start],xy[end]));}return{points:indices.map(i=>points[i]),maxDeviationMeters};}

// ---- validation of one or more rings (used by the editor before saving) ----
const cross=(a,b,c)=>(b.x-a.x)*(c.y-a.y)-(b.y-a.y)*(c.x-a.x);
const onSeg=(a,b,c)=>Math.min(a.x,b.x)-1e-8<=c.x&&c.x<=Math.max(a.x,b.x)+1e-8&&Math.min(a.y,b.y)-1e-8<=c.y&&c.y<=Math.max(a.y,b.y)+1e-8;
function segIntersect(a,b,c,d){const o1=cross(a,b,c),o2=cross(a,b,d),o3=cross(c,d,a),o4=cross(c,d,b);return(o1*o2<0&&o3*o4<0)||(Math.abs(o1)<1e-8&&onSeg(a,b,c))||(Math.abs(o2)<1e-8&&onSeg(a,b,d))||(Math.abs(o3)<1e-8&&onSeg(c,d,a))||(Math.abs(o4)<1e-8&&onSeg(c,d,b));}
function ringSelfIntersects(xy){const n=xy.length;for(let i=0;i<n;i++)for(let j=i+1;j<n;j++){if((j+1)%n===i||(i+1)%n===j)continue;if(segIntersect(xy[i],xy[(i+1)%n],xy[j],xy[(j+1)%n]))return true;}return false;}
function ringsCross(A,B){for(let i=0;i<A.length;i++)for(let j=0;j<B.length;j++)if(segIntersect(A[i],A[(i+1)%A.length],B[j],B[(j+1)%B.length]))return true;return false;}
function pointInRing(p,xy){let inside=false;for(let i=0,j=xy.length-1;i<xy.length;j=i++){const a=xy[i],b=xy[j];if((a.y>p.y)!==(b.y>p.y)&&p.x<(b.x-a.x)*(p.y-a.y)/(b.y-a.y)+a.x)inside=!inside;}return inside;}
// Mirrors src/geofence.php: even-odd across rings, and a point on any ring edge is inside.
function pointInRings(point,rings){
  if(!hasCoord(point)||!Array.isArray(rings))return false;let inside=false;const lat=Number(point.latitude),lng=Number(point.longitude);
  for(const ring of rings){if(!Array.isArray(ring)||ring.length<3)continue;for(let i=0,j=ring.length-1;i<ring.length;j=i++){
    const a=ring[i],b=ring[j],yi=Number(a.latitude),xi=Number(a.longitude),yj=Number(b.latitude),xj=Number(b.longitude),cross=(lng-xi)*(yj-yi)-(lat-yi)*(xj-xi);
    if(Math.abs(cross)<1e-10&&lat>=Math.min(yi,yj)-1e-10&&lat<=Math.max(yi,yj)+1e-10&&lng>=Math.min(xi,xj)-1e-10&&lng<=Math.max(xi,xj)+1e-10)return true;
    if((yi>lat)!==(yj>lat)&&lng<(xj-xi)*(lat-yi)/(yj-yi)+xi)inside=!inside;
  }}return inside;
}
function ringInteriorPoint(ring){
  if(!Array.isArray(ring)||ring.length<3||ring.some(v=>!hasCoord(v)))return null;
  const pts=ring.map(v=>({latitude:Number(v.latitude),longitude:Number(v.longitude)})),f=frame(pts),xy=pts.map(f.toXY),ys=[...new Set(xy.map(p=>p.y))].sort((a,b)=>a-b);
  for(let band=0;band<ys.length-1;band++){
    if(ys[band+1]-ys[band]<1e-9)continue;const y=(ys[band]+ys[band+1])/2,xs=[];
    for(let i=0,j=xy.length-1;i<xy.length;j=i++){const a=xy[j],b=xy[i];if((a.y>y)!==(b.y>y))xs.push(a.x+(y-a.y)*(b.x-a.x)/(b.y-a.y));}
    xs.sort((a,b)=>a-b);
    for(let i=0;i+1<xs.length;i+=2){if(xs[i+1]-xs[i]<1e-7)continue;const probe=f.toLL({x:(xs[i]+xs[i+1])/2,y});if(pointInRings(probe,[pts]))return probe;}
  }
  return null;
}
// For two rings, require a representative point in the inner ring to be outside the complete zone.
// expectedInnerRing is useful to test a missing-hole/filled-polygon regression.
function loopGuardState(rings,expectedInnerRing=null){
  const inner=expectedInnerRing||(Array.isArray(rings)&&rings.length===2?rings[1]:null);
  if(!inner)return{checked:false,safe:true,probe:null,insideInner:null,insideZone:null};
  const probe=ringInteriorPoint(inner);if(!probe)return{checked:true,safe:false,probe:null,insideInner:false,insideZone:null};
  const insideInner=pointInRings(probe,[inner]),insideZone=pointInRings(probe,rings);
  return{checked:true,safe:insideInner&&!insideZone,probe,insideInner,insideZone};
}
const hasCoord=v=>v!=null&&v.latitude!=null&&v.longitude!=null&&v.latitude!==''&&v.longitude!==''&&Number.isFinite(Number(v.latitude))&&Number.isFinite(Number(v.longitude));
/** Returns '' when the rings are a valid safe zone, otherwise a message for the operator. */
function validateRings(rings){
  if(!Array.isArray(rings)||!rings.length||rings.some(r=>!Array.isArray(r)||r.length<3))return 'Add at least three vertices.';
  const all=rings.flat();
  if(all.some(v=>!hasCoord(v)||Math.abs(v.latitude)>90||Math.abs(v.longitude)>180))return 'Check that every latitude and longitude is within range.';
  if(rings.length>2)return 'A safe zone can have one outer ring and at most one inner ring.';
  for(const r of rings){if(new Set(r.map(v=>`${Number(v.latitude).toFixed(7)},${Number(v.longitude).toFixed(7)}`)).size<3)return 'At least three distinct vertices are required.';}
  const f=frame(all.map(v=>({latitude:Number(v.latitude),longitude:Number(v.longitude)})));
  const xy=rings.map(r=>r.map(v=>f.toXY({latitude:Number(v.latitude),longitude:Number(v.longitude)})));
  if(xy.some(ringSelfIntersects))return 'Polygon crosses itself. Adjust the vertex order.';
  if(xy.length===2){
    if(ringsCross(xy[0],xy[1]))return 'The inner and outer edges cross each other. Use a smaller half-width or edit the vertices.';
    if(!pointInRing(xy[1][0],xy[0]))return 'The inner ring must lie inside the outer ring.';
  }
  return '';
}
return {bufferTrack,bufferTrackRings,simplifyTrack,trackLengthMeters,pointInRings,ringInteriorPoint,loopGuardState,validateRings};
});
