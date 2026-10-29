// Serialize writes per draft; a late response never replaces a newer edit.
(function(global){
  class DraftSaver {
    constructor(send, onStatus){this.send=send;this.onStatus=onStatus;this.entries=new Map();}
    queue(key,url,data){
      let entry=this.entries.get(key);
      if(!entry){entry={key,url,revision:0,saved:0,busy:false,timer:null,failures:0,retryAt:0};this.entries.set(key,entry);}
      entry.data=JSON.parse(JSON.stringify(data));entry.revision++;entry.stopped=false;
      this.onStatus(key,'pending');
      clearTimeout(entry.timer);entry.timer=setTimeout(()=>this.flush(key),Math.max(1000,entry.retryAt-Date.now()));
    }
    async flush(key){
      const entry=this.entries.get(key);
      if(!entry || entry.busy || entry.stopped || entry.saved===entry.revision)return;
      if(Date.now()<entry.retryAt){clearTimeout(entry.timer);entry.timer=setTimeout(()=>this.flush(key),entry.retryAt-Date.now());return;}
      clearTimeout(entry.timer);entry.busy=true;
      const revision=entry.revision,data=entry.data;
      let delay=0;
      try{
        const result=await this.send(entry.url,data);
        if(!result?.ok){
          if([400,401,403,404,408,409,413].includes(result?.http_status)){entry.stopped=true;}
          throw new Error(result?.error||'Сервер недоступен');
        }
        entry.saved=revision;entry.failures=0;entry.retryAt=0;
        this.onStatus(key,entry.saved===entry.revision?'saved':'pending');
      }catch(error){
        entry.failures++;delay=Math.min(60000,5000*2**Math.min(entry.failures-1,4));
        entry.retryAt=Date.now()+delay;
        this.onStatus(key,'failed',error.message);
      }finally{
        entry.busy=false;
        if(!entry.stopped && entry.saved!==entry.revision)entry.timer=setTimeout(()=>this.flush(key),delay);
      }
    }
    flushAll(){for(const key of this.entries.keys())this.flush(key);}
    stop(key){const entry=this.entries.get(key);if(entry){entry.stopped=true;clearTimeout(entry.timer);}}
  }
  global.DraftSaver=DraftSaver;
})(globalThis);
