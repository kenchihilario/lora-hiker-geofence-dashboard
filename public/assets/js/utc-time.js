(function(root,factory){
  const api=factory();
  if(root)root.TrailwatchUtcTime=api;
  if(typeof module==='object'&&module.exports)module.exports=api;
})(typeof window!=='undefined'?window:null,function(){
  function parseDatabaseUtc(value){
    if(typeof value!=='string'||value==='')return null;
    const date=new Date(value.replace(' ','T')+'Z');
    return Number.isFinite(date.getTime())?date:null;
  }
  return {parseDatabaseUtc};
});
