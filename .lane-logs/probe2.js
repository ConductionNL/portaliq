const {resident}=require('/home/rubenlinde/zuiddrecht-demo/sc2/lib');
(async()=>{for(const who of ['Linda Bakker','Henk Bakker']){const {b,p}=await resident(who);
 const out=await p.evaluate(async()=>{const t=sessionStorage.getItem('portaliq.session.token');const h={Authorization:'Bearer '+t};const r={};
  const a=await (await fetch('/index.php/apps/portaliq/portal/api/my-cases?portal=zuiddrecht',{headers:h})).json();r.plain=a;
  const m=(a.mandates||[])[0];if(m){const id=m.id||m.mandateId;r.mid=id;r.withMandate=await (await fetch('/index.php/apps/portaliq/portal/api/my-cases?portal=zuiddrecht&mandate='+encodeURIComponent(id),{headers:h})).json();}
  return r;});
 const s=o=>o&&({cases:(o.cases||[]).map(c=>[c.identifier,c.portalParty,c._mandate?'M':'']),mandates:o.mandates,active:o.activeMandate,err:o.error});
 console.log('==',who,JSON.stringify({plain:s(out.plain),mid:out.mid,withMandate:s(out.withMandate)},null,1));
 await b.close();}})();
