const {resident}=require('/home/rubenlinde/zuiddrecht-demo/sc2/lib');
(async()=>{for(const who of ['Linda Bakker','Henk Bakker']){const {b,p}=await resident(who);
 const seen=[];p.on('response',async r=>{if(/my-cases|mandate/.test(r.url()))seen.push(r.url());});
 const tok=await p.evaluate(()=>{const o={};for(let i=0;i<sessionStorage.length;i++){o['s:'+sessionStorage.key(i)]=sessionStorage.getItem(sessionStorage.key(i)).slice(0,200)}for(let i=0;i<localStorage.length;i++){o['l:'+localStorage.key(i)]=localStorage.getItem(localStorage.key(i)).slice(0,200)}return o;});
 console.log('==',who,JSON.stringify(tok).slice(0,1500));
 await b.close();}})();
