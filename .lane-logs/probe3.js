const {resident,nav}=require('/home/rubenlinde/zuiddrecht-demo/sc2/lib');
(async()=>{const {b,p}=await resident('Linda Bakker');const reqs=[];p.on('request',r=>{if(/my-cases|contributions/.test(r.url()))reqs.push(r.url())});
 await nav(p,'/mijn/zaken');
 console.log(await p.evaluate(()=>({sw:document.querySelectorAll('[data-testid=acting-for]').length,bar:document.querySelectorAll('.pq-acting-for-bar,[data-testid*=acting]').length,store:sessionStorage.getItem('portaliq.actingFor')||Object.keys(sessionStorage).join(',')})));
 console.log(reqs.join('\n'));await b.close();})();
