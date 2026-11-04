(function(root,factory){const api=factory();if(typeof module==='object'&&module.exports)module.exports=api;else root.TrailwatchMapLabels=api;})(typeof globalThis!=='undefined'?globalThis:this,function(){
  function layout(label,{x,y,width=800,height=470,scale=1,fontPx=11,measureText=null}={}){
    const full=String(label??''),safeScale=Number.isFinite(scale)&&scale>0?scale:1,gapUser=12;
    const measure=text=>measureText?measureText(text,fontPx):Array.from(text).length*fontPx*.72;
    const leftSpace=Math.max(0,(x-gapUser)*safeScale),rightSpace=Math.max(0,(width-x-gapUser)*safeScale);
    const anchor=rightSpace>=leftSpace?'start':'end',available=Math.max(leftSpace,rightSpace);
    let display=full;
    if(measure(display)>available){const suffix='…',chars=Array.from(full);let low=0,high=chars.length;while(low<high){const mid=Math.ceil((low+high)/2);if(measure(chars.slice(0,mid).join('')+suffix)<=available)low=mid;else high=mid-1;}display=low?chars.slice(0,low).join('')+suffix:(measure(suffix)<=available?suffix:'');}
    const widthCss=measure(display),leftCss=anchor==='start'?(x+gapUser)*safeScale:(x-gapUser)*safeScale-widthCss,rightCss=leftCss+widthCss;
    const baselineCss=Math.max(fontPx*1.2,Math.min(height*safeScale-fontPx*.5,y*safeScale+fontPx*.35));
    return{full,display,anchor,textX:anchor==='start'?gapUser:-gapUser,baseline:baselineCss/safeScale-y,fontSize:fontPx/safeScale,bounds:{left:leftCss,right:rightCss,top:baselineCss-fontPx*1.2,bottom:baselineCss+fontPx*.5}};
  }
  return{layout};
});
