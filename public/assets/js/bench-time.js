(function(root,factory){
  const api=factory();
  if(root)root.TrailwatchBenchTime=api;
  if(typeof module==='object'&&module.exports)module.exports=api;
})(typeof window!=='undefined'?window:null,function(){
  function localDateTimeToUtcIso(value){
    if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value))throw new Error('Choose a valid local date and time.');
    const date=new Date(value);
    if(!Number.isFinite(date.getTime()))throw new Error('Choose a valid local date and time.');
    return date.toISOString();
  }
  return {localDateTimeToUtcIso};
});
