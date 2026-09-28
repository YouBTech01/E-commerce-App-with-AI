/* ExamLegacy — Admin dashboard. Dedicated Email+Password auth (eladm_ HMAC
   session tokens, zero Google dependency). All data comes from the verified
   /api/admin endpoints. No client-side trust. */
(function () {
  var API = '/api';
  var admin = null;
  var section = 'dashboard';

  /* ---------- helpers ---------- */
  function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
  function money(p,c){c=c||'INR';try{return new Intl.NumberFormat(undefined,{style:'currency',currency:c}).format((p||0)/100);}catch(e){return '₹'+((p||0)/100).toFixed(2);}}
  function qs(s,r){return (r||document).querySelector(s);}
  function qsa(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s));}
  function icon(n,cls){return '<i class="'+(cls?cls+' ':'')+'fas fa-'+n+'"></i>';}

  function applyTheme(){
    var pref=localStorage.getItem('el_theme')||'system';
    var dark=pref==='dark'||(pref==='system'&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.setAttribute('data-theme',dark?'dark':'light');
  }

  async function token(){return localStorage.getItem('el_admin_secret')||null;}
  async function api(method,path,body,isForm){
    var headers={};
    var t=await token(); if(t) headers['Authorization']='Bearer '+t;
    var opts={method:method,headers:headers};
    if(isForm){opts.body=body;}
    else if(body!==undefined&&method!=='GET'){headers['Content-Type']='application/json';opts.body=JSON.stringify(body);}
    var res=await fetch(API+path,opts);
    var text=await res.text(); var data=null;
    try{data=text?JSON.parse(text):{};}catch(e){data={ok:false,error:{message:'Bad response'}};}
    if(!res.ok||data.ok===false){var e=new Error((data.error&&data.error.message)||('Error '+res.status));e.status=res.status;throw e;}
    return data.data!==undefined?data.data:data;
  }
  function upload(path,file,field){
    var fd=new FormData(); fd.append(field||'file',file);
    return api('POST',path,fd,true);
  }

  function toast(msg,type){
    var host=qs('.toasts')||(function(){var h=document.createElement('div');h.className='toasts';document.body.appendChild(h);return h;})();
    var t=document.createElement('div');t.className='toast'+(type?' '+type:'');t.textContent=msg;host.appendChild(t);
    setTimeout(function(){t.remove();},2600);
  }
  function modal(title,bodyHTML,footerHTML){
    var scrim=document.createElement('div');scrim.className='scrim';
    var m=document.createElement('div');m.className='modal';m.setAttribute('role','dialog');
    m.innerHTML='<div class="row between" style="margin-bottom:6px"><h2>'+esc(title)+'</h2><button class="iconbtn" data-x>'+icon('xmark')+'</button></div><div class="mbody"></div>'+(footerHTML||'');
    qs('.mbody',m).innerHTML=bodyHTML;
    function close(){m.classList.remove('show');scrim.classList.remove('show');setTimeout(function(){m.remove();scrim.remove();},220);}
    qs('[data-x]',m).addEventListener('click',close);
    scrim.addEventListener('click',close);
    document.body.appendChild(scrim);document.body.appendChild(m);
    requestAnimationFrame(function(){scrim.classList.add('show');m.classList.add('show');});
    return {el:m,close:close,body:qs('.mbody',m)};
  }
  function confirmDialog(title,msg,danger){
    return new Promise(function(resolve){
      var m=modal(title,'<p class="dim">'+esc(msg)+'</p>',
        '<div class="row gap8" style="justify-content:flex-end;margin-top:14px">'+
        '<button class="btn ghost" data-no>Cancel</button><button class="btn '+(danger?'danger':'')+'" data-yes>Confirm</button></div>');
      qs('[data-no]',m.el).addEventListener('click',function(){resolve(false);m.close();});
      qs('[data-yes]',m.el).addEventListener('click',function(){resolve(true);m.close();});
    });
  }

  /* ---------- boot ---------- */
  async function boot(){
    applyTheme();
    // PWA: same service worker as the student app (offline shell for /admin too).
    if ('serviceWorker' in navigator &&
        (location.protocol === 'https:' || /localhost|127\.0\.0\.1/.test(location.hostname))) {
      navigator.serviceWorker.register('/sw.js').catch(function () {});
    }
    if (!token()) { showLogin(); return; }
    try {
      var me = await api('GET', '/admin/auth/me');
      admin = (me && me.admin) || me;
      renderShell();
    } catch (e) {
      localStorage.removeItem('el_admin_secret');
      showLogin();
    }
  }
  function showLogin(err){
    document.body.innerHTML='<div class="login"><div class="box"><img class="mark" src="/assets/img/logo.svg" alt="" width="84" height="84">'+
      '<h1>ExamLegacy Admin</h1><p>Sign in with your administrator account.</p>'+
      (err?'<div class="badge red" style="margin-bottom:12px">'+esc(err)+'</div>':'')+
      '<form id="adm-form" autocomplete="on">'+
      '<input class="input" id="adm-email" type="email" placeholder="Admin email" autocomplete="username" required style="margin-bottom:10px">'+
      '<input class="input" id="adm-pass" type="password" placeholder="Password" autocomplete="current-password" required style="margin-bottom:14px">'+
      '<button class="btn block" type="submit" id="adm-signin">Sign in</button>'+
      '</form>'+
      '<div class="tiny muted" style="margin-top:16px">SANJAYXLEGACY Powered By</div></div></div>';
    qs('#adm-form').addEventListener('submit', async function (ev) {
      ev.preventDefault();
      var b = qs('#adm-signin'); b.disabled = true; b.textContent = 'Signing in…';
      try {
        var d = await api('POST', '/admin/auth/login', {
          email: qs('#adm-email').value.trim(),
          password: qs('#adm-pass').value
        });
        localStorage.setItem('el_admin_secret', d.token);
        admin = d.admin;
        renderShell();
      } catch (e) { showLogin(e.message); }
    });
  }

  var SECTIONS=[
    {id:'dashboard',label:'Dashboard',icon:'gauge-high'},
    {id:'products',label:'Products',icon:'book'},
    {id:'orders',label:'Orders',icon:'receipt'},
    {id:'users',label:'Users',icon:'users'},
    {id:'support',label:'Support',icon:'headset'},
    {id:'notifications',label:'Notifications',icon:'bell'},
    {id:'vip',label:'VIP PASS',icon:'crown'},
    {id:'packs',label:'Credit packs',icon:'coins'},
    {id:'settings',label:'Settings',icon:'gear'},
    {id:'audit',label:'Audit log',icon:'clipboard-list'}
  ];

  function renderShell(){
    document.body.innerHTML=
      '<div class="shell">'+
      '<aside class="sidebar" id="sidebar"><div class="logo"><img class="m" src="/assets/img/mark.svg" alt=""><span>ExamLegacy</span></div><nav class="nav" id="nav"></nav></aside>'+
      '<div style="flex:1;min-width:0">'+
        '<div class="topbar"><button class="iconbtn hamb" id="hamb">'+icon('bars')+'</button><h1 id="sec-title">Dashboard</h1>'+
        '<button class="iconbtn" id="theme-btn">'+icon('moon')+'</button>'+
        '<button class="iconbtn" id="signout">'+icon('right-from-bracket')+'</button></div>'+
        '<main class="main" id="main"></main>'+
      '</div></div><div class="toasts"></div>';
    qs('#nav').innerHTML=SECTIONS.map(function(s){return '<button data-sec="'+s.id+'">'+icon(s.icon)+'<span>'+s.label+'</span></button>';}).join('');
    qsa('#nav [data-sec]').forEach(function(b){b.addEventListener('click',function(){go(b.getAttribute('data-sec'));qs('#sidebar').classList.remove('open');});});
    qs('#hamb').addEventListener('click',function(){qs('#sidebar').classList.toggle('open');});
    qs('#theme-btn').addEventListener('click',function(){var o=['light','dark','system'];var c=localStorage.getItem('el_theme')||'system';localStorage.setItem('el_theme',o[(o.indexOf(c)+1)%o.length]);applyTheme();});
    qs('#signout').addEventListener('click',function(){localStorage.removeItem('el_admin_secret');admin=null;showLogin();});
    go('dashboard');
  }
  function go(id){section=id;qsa('#nav [data-sec]').forEach(function(b){b.classList.toggle('active',b.getAttribute('data-sec')===id);});
    var s=SECTIONS.filter(function(x){return x.id===id;})[0]; if(s)qs('#sec-title').textContent=s.label;
    ({dashboard:dashboard,products:products,orders:orders,users:users,support:support,notifications:notifications,vip:vip,packs:packs,settings:settings,audit:audit})[id]();
  }

  /* ---------- Dashboard ---------- */
  async function dashboard(){
    var m=qs('#main'); m.innerHTML='<div class="stats" id="st">'+Array(5).fill('<div class="stat"><div class="skel" style="height:34px"></div></div>').join('')+'</div><div class="card"><h3>Recent activity</h3><div id="recent"><div class="spinner"></div></div></div>';
    try{
      var st=(await api('GET','/admin/stats')).stats;
      qs('#st').innerHTML=[
        ['users','Users',st.users_total],['user-check','Active',st.users_active],
        ['receipt','Paid orders',st.orders_paid],['clock','Pending',st.orders_pending],
        ['indian-rupee-sign','Revenue',money(st.revenue_paise)]
      ].map(function(x){return '<div class="stat"><div class="ic">'+icon(x[0])+'</div><div class="v mono">'+esc(x[2])+'</div><div class="l">'+x[1]+'</div></div>';}).join('');
    }catch(e){qs('#st').innerHTML='<div class="badge red">'+esc(e.message)+'</div>';}
    try{
      var logs=(await api('GET','/admin/audit')).logs||[];
      qs('#recent').innerHTML=logs.length?('<table><thead><tr><th>Action</th><th>Actor</th><th>Entity</th><th>When</th></tr></thead><tbody>'+
        logs.slice(0,12).map(function(l){return '<tr><td>'+esc(l.action)+'</td><td>'+esc(l.actor_name||l.actor_email||'system')+'</td><td>'+esc(l.entity)+' '+(l.entity_id?('#'+l.entity_id):'')+'</td><td class="tiny muted">'+new Date(l.created_at).toLocaleString()+'</td></tr>';}).join('')+'</tbody></table>'):'<div class="muted">No activity yet.</div>';
    }catch(e){qs('#recent').innerHTML='<div class="badge red">'+esc(e.message)+'</div>';}
  }

  /* ---------- Products ---------- */
  async function products(){
    var m=qs('#main');
    m.innerHTML='<div class="row between mb12"><input class="input" id="pq" placeholder="Search products…" style="max-width:280px"><button class="btn" id="add-p">'+icon('plus')+' New product</button></div><div class="card pad0"><div class="tablewrap" id="pt"><div class="spinner"></div></div></div>';
    var rows=[];
    async function load(){
      try{rows=(await api('GET','/admin/products')).products||[];draw();}catch(e){qs('#pt').innerHTML='<div class="badge red">'+esc(e.message)+'</div>';}
    }
    function draw(){
      var q=(qs('#pq').value||'').toLowerCase();
      var list=rows.filter(function(p){return !q||p.title.toLowerCase().indexOf(q)>=0;});
      qs('#pt').innerHTML=list.length?('<table><thead><tr><th>Title</th><th>Category</th><th>Price</th><th>VIP</th><th>Status</th><th></th></tr></thead><tbody>'+
        list.map(function(p){return '<tr><td class="wrap">'+esc(p.title)+'</td><td>'+esc(p.category)+'</td><td class="mono">'+money(p.price_paise,p.currency)+'</td>'+
          '<td>'+(p.is_vip?'<span class="badge amber">VIP</span>':'')+'</td>'+
          '<td><span class="badge '+(p.is_published?'green':'slate')+'">'+(p.is_published?'published':'draft')+'</span></td>'+
          '<td style="white-space:nowrap"><button class="btn sm soft" data-edit="'+p.id+'">Edit</button> <button class="btn sm ghost" data-del="'+p.id+'">Unpublish</button></td></tr>';}).join('')+'</tbody></table>'):'<div class="muted" style="padding:16px">No products.</div>';
      qsa('[data-edit]').forEach(function(b){b.addEventListener('click',function(){editProduct(rows.filter(function(p){return p.id==b.getAttribute('data-edit');})[0]);});});
      qsa('[data-del]').forEach(function(b){b.addEventListener('click',async function(){
        if(await confirmDialog('Unpublish product?','It will no longer be visible in the store. Existing owners keep access.',true)){
          try{await api('DELETE','/admin/products/'+b.getAttribute('data-del'));toast('Unpublished','ok');load();}catch(e){toast(e.message,'err');}
        }});});
    }
    qs('#pq').addEventListener('input',draw);
    qs('#add-p').addEventListener('click',function(){editProduct(null);});
    load();
  }
  function editProduct(p){
    var m=modal(p?'Edit product':'New product',
      '<div class="grid2">'+
      '<div class="field"><label>Title</label><input class="input" id="f-title" value="'+esc(p?p.title:'')+'"></div>'+
      '<div class="field"><label>Slug</label><input class="input" id="f-slug" value="'+esc(p?p.slug:'')+'"></div>'+
      '<div class="field"><label>Subtitle</label><input class="input" id="f-sub" value="'+esc(p?p.subtitle:'')+'"></div>'+
      '<div class="field"><label>Category</label><input class="input" id="f-cat" value="'+esc(p?p.category:'')+'"></div>'+
      '<div class="field"><label>Language</label><input class="input" id="f-lang" value="'+(esc(p?p.language:'English'))+'"></div>'+
      '<div class="field"><label>Price (₹)</label><input class="input" id="f-price" type="number" value="'+(p?(p.price_paise/100):0)+'"></div>'+
      '<div class="field"><label>MRP (₹)</label><input class="input" id="f-mrp" type="number" value="'+(p?(p.mrp_paise/100):0)+'"></div>'+
      '<div class="field"><label>Pages</label><input class="input" id="f-pages" type="number" value="'+(p?p.page_count:0)+'"></div>'+
      '<div class="field"><label>Access duration (days, 0=lifetime)</label><input class="input" id="f-days" type="number" value="'+(p?p.access_duration_days:0)+'"></div>'+
      '</div>'+
      '<div class="field"><label>Description</label><textarea id="f-desc" rows="3">'+esc(p?p.description:'')+'</textarea></div>'+
      '<div class="grid2">'+
      '<label class="row gap8"><input type="checkbox" id="f-pub" '+(p&&p.is_published?'checked':'')+'> Published</label>'+
      '<label class="row gap8"><input type="checkbox" id="f-vip" '+(p&&p.is_vip?'checked':'')+'> VIP</label>'+
      '<label class="row gap8"><input type="checkbox" id="f-dl" '+(p&&p.download_allowed?'checked':'')+'> Allow download</label>'+
      '</div>'+
      '<div class="divider" style="height:1px;background:var(--border);margin:12px 0"></div>'+
      '<div class="grid2">'+
      '<div class="field"><label>PDF file</label><input type="file" id="f-pdf" accept="application/pdf"><div class="tiny muted" id="pdf-st">'+(p?'current: '+esc(p.pdf_path):'required')+'</div></div>'+
      '<div class="field"><label>Cover image</label><input type="file" id="f-cover" accept="image/*"><div class="tiny muted" id="cover-st">'+(p&&p.thumbnail_path?'current: '+esc(p.thumbnail_path):'optional')+'</div></div>'+
      '</div>',
      '<div class="row gap8" style="justify-content:flex-end;margin-top:14px"><button class="btn ghost" data-cancel>Cancel</button><button class="btn" data-save>Save</button></div>');
    qs('[data-cancel]',m.el).addEventListener('click',m.close);
    qs('[data-save]',m.el).addEventListener('click',async function(){
      try{
        var pdfPath=p?p.pdf_path:''; var cover=p?p.thumbnail_path:null; var size=p?p.file_size_bytes:0; var sha=p?p.pdf_sha256:null;
        var pdfFile=qs('#f-pdf').files[0];
        if(pdfFile){var r=await upload('/admin/products/upload-pdf',pdfFile,'file');pdfPath=r.pdf_path;size=r.file_size_bytes;sha=r.pdf_sha256;qs('#pdf-st').textContent='uploaded';}
        var coverFile=qs('#f-cover').files[0];
        if(coverFile){var rc=await upload('/admin/products/upload-cover',coverFile,'file');cover=rc.thumbnail_path;qs('#cover-st').textContent='uploaded';}
        if(!pdfPath){toast('A PDF file is required','err');return;}
        var body={title:qs('#f-title').value,slug:qs('#f-slug').value||slugify(qs('#f-title').value),subtitle:qs('#f-sub').value,
          category:qs('#f-cat').value||'General',language:qs('#f-lang').value||'English',
          price_paise:Math.round(parseFloat(qs('#f-price').value||'0')*100),mrp_paise:Math.round(parseFloat(qs('#f-mrp').value||'0')*100),
          page_count:parseInt(qs('#f-pages').value||'0',10),access_duration_days:parseInt(qs('#f-days').value||'0',10),
          description:qs('#f-desc').value,is_published:qs('#f-pub').checked?1:0,is_vip:qs('#f-vip').checked?1:0,download_allowed:qs('#f-dl').checked?1:0,
          pdf_path:pdfPath,thumbnail_path:cover,file_size_bytes:size,pdf_sha256:sha,currency:'INR'};
        if(p) await api('PATCH','/admin/products/'+p.id,body); else await api('POST','/admin/products',body);
        toast('Saved','ok'); m.close(); go('products');
      }catch(e){toast(e.message,'err');}
    });
  }
  function slugify(s){return String(s||'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'').slice(0,60)+'-'+Math.random().toString(36).slice(2,6);}

  /* ---------- Orders ---------- */
  async function orders(){
    var m=qs('#main');
    m.innerHTML='<div class="row gap8 mb12" id="ofilter">'+['all','pending','paid','failed','refunded'].map(function(s,i){return '<button class="btn sm '+(i===0?'':'ghost')+'" data-f="'+s+'">'+s+'</button>';}).join('')+'</div><div class="card pad0"><div class="tablewrap" id="ot"><div class="spinner"></div></div></div>';
    var filter='all';
    async function load(){
      qs('#ot').innerHTML='<div class="spinner"></div>';
      try{var list=(await api('GET','/admin/orders'+(filter!=='all'?('?status='+filter):''))).orders||[];
        qs('#ot').innerHTML=list.length?('<table><thead><tr><th>Code</th><th>Status</th><th>Method</th><th>Total</th><th>Items</th><th>Date</th><th></th></tr></thead><tbody>'+
          list.map(function(o){return '<tr><td class="mono">'+esc(o.order_code)+'</td><td>'+statusBadge(o.status)+'</td><td>'+esc(o.payment_method)+'</td><td class="mono">'+money(o.total_paise,o.currency)+'</td><td>'+o.items.length+'</td><td class="tiny muted">'+new Date(o.created_at).toLocaleString()+'</td>'+
            '<td style="white-space:nowrap"><button class="btn sm soft" data-view="'+o.id+'">View</button> <button class=\"btn sm soft\" data-recheck=\"'+o.id+'\">Recheck</button>'+(o.status==='paid'?' <button class="btn sm danger" data-refund="'+o.id+'">Refund</button>':'')+'</td></tr>';}).join('')+'</tbody></table>'):'<div class="muted" style="padding:16px">No orders.</div>';
        qsa('[data-view]').forEach(function(b){b.addEventListener('click',function(){viewOrder(list.filter(function(o){return o.id==b.getAttribute('data-view');})[0]);});});
        qsa('[data-recheck]').forEach(function(b){b.addEventListener('click',async function(){
          try{var rc=await api('POST','/admin/orders/'+b.getAttribute('data-recheck')+'/recheck',{});toast('Rechecked: '+rc.after,'ok');load();}
          catch(e){toast(e.message,'err');}
        });});
        qsa('[data-refund]').forEach(function(b){b.addEventListener('click',async function(){
          if(await confirmDialog('Refund order?','Credits the total to the user wallet and revokes PDF access.',true)){
            try{await api('POST','/admin/orders/'+b.getAttribute('data-refund')+'/refund',{});toast('Refunded','ok');load();}catch(e){toast(e.message,'err');}
          }});});
      }catch(e){qs('#ot').innerHTML='<div class="badge red">'+esc(e.message)+'</div>';}
    }
    qsa('#ofilter [data-f]').forEach(function(b){b.addEventListener('click',function(){filter=b.getAttribute('data-f');qsa('#ofilter .btn').forEach(function(x){x.classList.add('ghost');});b.classList.remove('ghost');load();});});
    load();
  }
  function viewOrder(o){
    modal('Order '+o.order_code,
      '<div class="row between"><span class="dim">Status</span>'+statusBadge(o.status)+'</div>'+
      '<div class="row between mt8"><span class="dim">Method</span><span>'+esc(o.payment_method)+'</span></div>'+
      '<div class="row between mt8"><span class="dim">Total</span><span class="b mono">'+money(o.total_paise,o.currency)+'</span></div>'+
      '<div class="row between mt8"><span class="dim">Wallet applied</span><span class="mono">'+money(o.wallet_applied_paise)+'</span></div>'+
      '<div class="divider" style="height:1px;background:var(--border);margin:12px 0"></div>'+
      '<h3>Items</h3>'+(o.items||[]).map(function(it){return '<div class="row between"><span>'+esc(it.title)+'</span><span class="mono">'+money(it.price_paise)+'</span></div>';}).join(''),
      '<div class="row" style="justify-content:flex-end;margin-top:14px"><button class="btn ghost" data-x>Close</button></div>');
  }
  function statusBadge(s){var map={paid:'green',pending:'amber',failed:'red',refunded:'slate',cancelled:'red'};return '<span class="badge '+(map[s]||'slate')+'">'+esc(s)+'</span>';}

  /* ---------- Users ---------- */
  async function users(){
    var m=qs('#main');
    m.innerHTML='<div class="row gap8 mb12"><input class="input" id="uq" placeholder="Search name/email/phone…" style="max-width:300px"></div><div class="card pad0"><div class="tablewrap" id="ut"><div class="spinner"></div></div></div>';
    var rows=[];
    async function load(){
      try{rows=(await api('GET','/admin/users')).users||[];draw();}catch(e){qs('#ut').innerHTML='<div class="badge red">'+esc(e.message)+'</div>';}
    }
    function draw(){
      var q=(qs('#uq').value||'').toLowerCase();
      var list=rows.filter(function(u){return !q||(u.name||'').toLowerCase().indexOf(q)>=0||(u.email||'').toLowerCase().indexOf(q)>=0;});
      qs('#ut').innerHTML=list.length?('<table><thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Wallet</th><th>Credits</th><th>VIP</th><th></th></tr></thead><tbody>'+
        list.map(function(u){return '<tr><td class="wrap">'+esc(u.name)+(u.role==='admin'?' <span class="badge blue">admin</span>':'')+'</td><td class="wrap">'+esc(u.email)+'</td>'+
          '<td><span class="badge '+(u.status==='active'?'green':(u.status==='suspended'?'amber':'red'))+'">'+esc(u.status)+'</span></td>'+
          '<td class="mono">'+money(u.wallet_balance_paise)+'</td><td class="mono">'+u.ai_credit_balance+'</td>'+
          '<td>'+(u.vip_active?'<span class="badge amber">VIP</span>':'')+'</td>'+
          '<td><button class="btn sm soft" data-user="'+u.id+'">Manage</button></td></tr>';}).join('')+'</tbody></table>'):'<div class="muted" style="padding:16px">No users.</div>';
      qsa('[data-user]').forEach(function(b){b.addEventListener('click',function(){manageUser(list.filter(function(u){return u.id==b.getAttribute('data-user');})[0]);});});
    }
    qs('#uq').addEventListener('input',draw);
    load();
  }
  async function manageUser(u){
    var full;
    try{full=await api('GET','/admin/users/'+u.id);}catch(e){return toast(e.message,'err');}
    full=full.user;
    var m=modal('Manage '+full.name,
      '<div class="grid2">'+
      '<div class="card"><div class="tiny muted">Wallet</div><div class="b mono" style="font-size:1.3rem">'+money(full.wallet_balance_paise)+'</div>'+
        '<div class="row gap8 mt8"><input class="input" id="wa" type="number" placeholder="± paise"><button class="btn sm" id="wa-apply">Adjust</button></div></div>'+
      '<div class="card"><div class="tiny muted">AI Credits</div><div class="b mono" style="font-size:1.3rem">'+full.ai_credit_balance+'</div>'+
        '<div class="row gap8 mt8"><input class="input" id="ca" type="number" placeholder="± credits"><button class="btn sm" id="ca-apply">Adjust</button></div></div>'+
      '</div>'+
      '<div class="card"><h3>Account status</h3><div class="row gap8">'+
        '<button class="btn sm '+(full.status==='active'?'':'ghost')+'" data-status="active">Active</button>'+
        '<button class="btn sm '+(full.status==='suspended'?'':'ghost')+'" data-status="suspended">Suspend</button>'+
        '<button class="btn sm '+(full.status==='disabled'?'':'ghost')+'" data-status="disabled">Disable</button></div></div>'+
      '<div class="card"><h3>PDF access</h3><div class="row gap8"><input class="input" id="ppid" type="number" placeholder="Product ID"><button class="btn sm soft" id="grant">Grant</button><button class="btn sm danger" id="revoke">Revoke</button></div>'+
        '<div class="mt8 small">'+((full.pdf_access||[]).map(function(a){return '<div class="row between"><span>#'+a.id+' '+esc(a.title)+'</span><span class="badge '+(a.status==='active'?'green':'red')+'">'+a.status+'</span></div>';}).join('')||'<span class="muted">No access records</span>')+'</div></div>'+
      '<div class="card"><h3>VIP PASS</h3><div class="row gap8"><input class="input" id="vpid" type="number" placeholder="Plan ID"><button class="btn sm" id="grant-vip">Grant VIP</button></div></div>'+
      '<div class="card"><h3>Notify user</h3><input class="input" id="nt" placeholder="Title"><textarea id="nb" rows="2" class="mt8" placeholder="Body"></textarea><button class="btn sm soft mt8" id="send-n">Send notification</button></div>',
      '<div class="row" style="justify-content:flex-end;margin-top:14px"><button class="btn ghost" data-x>Close</button></div>');
    qs('[data-x]',m.el).addEventListener('click',m.close);
    qsa('[data-status]',m.el).forEach(function(b){b.addEventListener('click',async function(){
      try{await api('POST','/admin/users/'+full.id+'/status',{status:b.getAttribute('data-status'),reason:'admin action'});toast('Status updated','ok');m.close();go('users');}catch(e){toast(e.message,'err');}
    });});
    qs('#wa-apply',m.el).addEventListener('click',async function(){try{var r=await api('POST','/admin/users/'+full.id+'/wallet',{amount_paise:parseInt(qs('#wa',m.el).value||'0',10),reason:'manual adjustment'});toast('New balance '+money(r.balance_paise),'ok');}catch(e){toast(e.message,'err');}});
    qs('#ca-apply',m.el).addEventListener('click',async function(){try{var r=await api('POST','/admin/users/'+full.id+'/credits',{amount:parseInt(qs('#ca',m.el).value||'0',10),reason:'manual adjustment'});toast('New credits '+r.balance,'ok');}catch(e){toast(e.message,'err');}});
    qs('#grant',m.el).addEventListener('click',async function(){try{await api('POST','/admin/users/'+full.id+'/pdf/grant',{product_id:parseInt(qs('#ppid',m.el).value||'0',10)});toast('Access granted','ok');}catch(e){toast(e.message,'err');}});
    qs('#revoke',m.el).addEventListener('click',async function(){try{await api('POST','/admin/users/'+full.id+'/pdf/revoke',{product_id:parseInt(qs('#ppid',m.el).value||'0',10)});toast('Access revoked','ok');}catch(e){toast(e.message,'err');}});
    qs('#grant-vip',m.el).addEventListener('click',async function(){try{await api('POST','/admin/users/'+full.id+'/vip',{plan_id:parseInt(qs('#vpid',m.el).value||'0',10)});toast('VIP granted','ok');}catch(e){toast(e.message,'err');}});
    qs('#send-n',m.el).addEventListener('click',async function(){try{await api('POST','/admin/notify',{user_id:full.id,category:'system',title:qs('#nt',m.el).value,body:qs('#nb',m.el).value});toast('Notification sent','ok');}catch(e){toast(e.message,'err');}});
  }

  /* ---------- Support ---------- */
  async function support(){
    var m=qs('#main');
    m.innerHTML='<div class="card pad0"><div class="tablewrap" id="st"><div class="spinner"></div></div></div>';
    try{
      var threads=(await api('GET','/admin/support')).threads||[];
      qs('#st').innerHTML=threads.length?('<table><thead><tr><th>ID</th><th>Subject</th><th>Status</th><th>Last</th><th></th></tr></thead><tbody>'+
        threads.map(function(t){return '<tr><td class="mono">'+t.id+'</td><td class="wrap">'+esc(t.subject)+'</td><td><span class="badge blue">'+esc(t.status)+'</span></td><td class="tiny muted">'+(t.last_message_at?new Date(t.last_message_at).toLocaleString():'')+'</td><td><button class="btn sm soft" data-t="'+t.id+'">Open</button></td></tr>';}).join('')+'</tbody></table>'):'<div class="muted" style="padding:16px">No support threads.</div>';
      qsa('[data-t]').forEach(function(b){b.addEventListener('click',function(){openThread(parseInt(b.getAttribute('data-t'),10));});});
    }catch(e){qs('#st').innerHTML='<div class="badge red">'+esc(e.message)+'</div>';}
  }
  async function openThread(id){
    var data;try{data=await api('GET','/admin/support/'+id);}catch(e){return toast(e.message,'err');}
    var m=modal('Conversation #'+id,
      '<div style="max-height:46vh;overflow:auto">'+(data.messages||[]).map(function(msg){
        var who=msg.sender==='admin'?'You':(msg.sender==='user'?'User':'AI');
        return '<div class="card" style="margin-bottom:8px"><div class="tiny muted">'+who+' · '+new Date(msg.created_at).toLocaleString()+'</div><div style="white-space:pre-wrap">'+esc(msg.body)+'</div></div>';
      }).join('')+'</div>'+
      '<div class="row gap8 mt8"><input class="input" id="reply" placeholder="Type a reply…"><button class="btn" id="send">Send</button></div>'+
      '<div class="row gap8 mt8">'+['resolved','waiting_user','closed','open'].map(function(s){return '<button class="btn sm ghost" data-st="'+s+'">'+s+'</button>';}).join('')+'</div>',
      '<div class="row" style="justify-content:flex-end;margin-top:10px"><button class="btn ghost" data-x>Close</button></div>');
    qs('[data-x]',m.el).addEventListener('click',m.close);
    qs('#send',m.el).addEventListener('click',async function(){var v=qs('#reply',m.el).value.trim();if(!v)return;try{await api('POST','/admin/support/'+id+'/reply',{message:v});toast('Reply sent','ok');m.close();openThread(id);}catch(e){toast(e.message,'err');}});
    qsa('[data-st]',m.el).forEach(function(b){b.addEventListener('click',async function(){try{await api('POST','/admin/support/'+id+'/status',{status:b.getAttribute('data-st')});toast('Status set','ok');m.close();go('support');}catch(e){toast(e.message,'err');}});});
  }

  /* ---------- Notifications ---------- */
  async function notifications(){
    var m=qs('#main');
    m.innerHTML='<div class="grid2">'+
      '<div class="card"><h3>Notify a user</h3><input class="input" id="nu" type="number" placeholder="User ID"><input class="input mt8" id="nti" placeholder="Title"><textarea id="nb" rows="3" class="mt8" placeholder="Body"></textarea><button class="btn mt8" id="send-u">Send</button></div>'+
      '<div class="card"><h3>Broadcast to all users</h3><input class="input" id="bt" placeholder="Title"><textarea id="bb" rows="3" class="mt8" placeholder="Body"></textarea><button class="btn mt8" id="send-b">Broadcast</button></div>'+
      '</div>';
    qs('#send-u',m).addEventListener('click',async function(){try{await api('POST','/admin/notify',{user_id:parseInt(qs('#nu',m).value||'0',10),category:'system',title:qs('#nti',m).value,body:qs('#nb',m).value});toast('Sent','ok');}catch(e){toast(e.message,'err');}});
    qs('#send-b',m).addEventListener('click',async function(){try{await api('POST','/admin/broadcast',{category:'product',title:qs('#bt',m).value,body:qs('#bb',m).value});toast('Broadcast sent','ok');}catch(e){toast(e.message,'err');}});
  }

  /* ---------- VIP ---------- */
  async function vip(){
    var m=qs('#main');
    m.innerHTML='<div class="card pad0"><div class="tablewrap" id="vt"><div class="spinner"></div></div></div>';
    try{
      var plans=(await api('GET','/admin/vip/plans')).plans||[];
      qs('#vt').innerHTML='<table><thead><tr><th>Code</th><th>Name</th><th>Interval</th><th>Price</th><th>Active</th></tr></thead><tbody>'+
        plans.map(function(p){return '<tr><td class="mono">'+esc(p.code)+'</td><td>'+esc(p.name)+'</td><td>'+esc(p.interval)+'</td><td class="mono">'+money(p.price_paise)+'</td><td><span class="badge '+(p.is_active?'green':'slate')+'">'+(p.is_active?'yes':'no')+'</span></td></tr>';}).join('')+'</tbody></table>';
    }catch(e){qs('#vt').innerHTML='<div class="badge red">'+esc(e.message)+'</div>';}
  }

  /* ---------- Settings ---------- */
  async function settings(){
    var m=qs('#main');
    m.innerHTML='<div class="card"><div id="sf"><div class="spinner"></div></div><button class="btn mt16" id="save-s">Save settings</button></div>';
    var s;try{s=(await api('GET','/admin/settings')).settings||{};}catch(e){return qs('#sf').innerHTML='<div class="badge red">'+esc(e.message)+'</div>';}
    var fields=['support_email','telegram','telegram_channel','instagram','youtube','whatsapp_channel','whatsapp_support_enabled','whatsapp_support_link','trial_ai_credits','ai_credit_cost_study','ai_credit_cost_support','ai_credit_cost_help','brand_powered_by'];
    qs('#sf').innerHTML='<div class="grid2">'+fields.map(function(f){
      var v=s[f]!==undefined?s[f]:'';
      if(f==='whatsapp_support_enabled')return '<div class="field"><label>'+f+'</label><select class="input" data-k="'+f+'"><option value="0"'+(v==='0'?' selected':'')+'>OFF (default)</option><option value="1"'+(v==='1'?' selected':'')+'>ON</option></select></div>';
      return '<div class="field"><label>'+f+'</label><input class="input" data-k="'+f+'" value="'+esc(v)+'"></div>';
    }).join('')+'</div><div class="tiny muted">WhatsApp Support is OFF by default. Destination is admin-configured only.</div>';
    qs('#save-s',m).addEventListener('click',async function(){
      var out={};qsa('[data-k]',m).forEach(function(el){out[el.getAttribute('data-k')]=el.value;});
      try{await api('POST','/admin/settings',{settings:out});toast('Settings saved','ok');}catch(e){toast(e.message,'err');}
    });
  }

  /* ---------- Credit packs ---------- */
  async function packs(){
    var m=qs('#main');
    m.innerHTML='<div class="row between mb12"><div class="dim small">AI credit bundles sold for cash — separate ledger from the Store Wallet.</div>'+
      '<button class="btn" id="add-pack">'+icon('plus')+' New pack</button></div>'+
      '<div class="card pad0"><div class="tablewrap" id="pkt"><div class="spinner"></div></div></div>';
    async function load(){
      try{
        var list=(await api('GET','/admin/credits/packs')).packs||[];
        qs('#pkt').innerHTML=list.length?('<table><thead><tr><th>Code</th><th>Name</th><th>Credits</th><th>Bonus</th><th>Price</th><th>Active</th><th></th></tr></thead><tbody>'+
          list.map(function(p){
            return '<tr><td class="mono">'+esc(p.code)+'</td><td>'+esc(p.name)+'</td><td>'+p.credits+'</td><td>'+(p.bonus_credits||0)+'</td>'+
            '<td class="mono">'+money(p.price_paise,p.currency)+'</td><td><span class="badge '+(p.is_active?'green':'slate')+'">'+(p.is_active?'yes':'no')+'</span></td>'+
            '<td style="white-space:nowrap"><button class="btn sm soft" data-edit="'+p.id+'">Edit</button> '+
            '<button class="btn sm ghost" data-arch="'+p.id+'">'+(p.is_active?'Archive':'Restore')+'</button></td></tr>';
          }).join('')+'</tbody></table>'):'<div class="muted" style="padding:16px">No credit packs yet.</div>';
        qsa('[data-edit]',m).forEach(function(b){b.addEventListener('click',function(){
          edit(list.filter(function(x){return String(x.id)===String(b.getAttribute('data-edit'));})[0]);});});
        qsa('[data-arch]',m).forEach(function(b){b.addEventListener('click',async function(){
          var p=list.filter(function(x){return String(x.id)===String(b.getAttribute('data-arch'));})[0];
          try{await api('PATCH','/admin/credits/packs/'+p.id,{is_active:p.is_active?0:1});toast('Pack updated','ok');load();}
          catch(e){toast(e.message,'err');}
        });});
      }catch(e){qs('#pkt').innerHTML='<div class="badge red">'+esc(e.message)+'</div>';}
    }
    function edit(p){
      var isNew=!p;
      p=p||{code:'',name:'',credits:100,bonus_credits:0,price_paise:19900,currency:'INR'};
      var mm=modal(isNew?'New credit pack':'Edit pack',
        '<div class="grid2">'+
        '<div class="field"><label>Name</label><input class="input" id="pk-name" value="'+esc(p.name)+'" placeholder="Starter 100"></div>'+
        '<div class="field"><label>Code</label><input class="input" id="pk-code" value="'+esc(p.code)+'" placeholder="pack_starter"'+(isNew?'':' disabled')+'></div>'+
        '<div class="field"><label>Credits</label><input class="input" id="pk-credits" type="number" min="1" value="'+p.credits+'"></div>'+
        '<div class="field"><label>Bonus credits</label><input class="input" id="pk-bonus" type="number" min="0" value="'+(p.bonus_credits||0)+'"></div>'+
        '<div class="field"><label>Price (₹)</label><input class="input" id="pk-price" type="number" min="1" step="0.01" value="'+((p.price_paise||0)/100)+'"></div>'+
        '</div><div class="tiny muted">Purchases settle server-side via Cashfree before credits are granted.</div>',
        '<div class="row gap8" style="justify-content:flex-end;margin-top:14px"><button class="btn ghost" data-cancel>Cancel</button><button class="btn" data-save>Save</button></div>');
      qs('[data-cancel]',mm.el).addEventListener('click',mm.close);
      qs('[data-save]',mm.el).addEventListener('click',async function(){
        var body={
          name:qs('#pk-name',mm.el).value.trim(),
          credits:parseInt(qs('#pk-credits',mm.el).value||'0',10),
          bonus_credits:parseInt(qs('#pk-bonus',mm.el).value||'0',10),
          price_paise:Math.round(parseFloat(qs('#pk-price',mm.el).value||'0')*100)
        };
        if(isNew)body.code=qs('#pk-code',mm.el).value.trim();
        if(!body.name||body.credits<=0||body.price_paise<=0){toast('Name, credits and price are required','err');return;}
        try{
          await api(isNew?'POST':'PATCH',isNew?'/admin/credits/packs':'/admin/credits/packs/'+p.id,body);
          toast('Pack saved','ok');mm.close();load();
        }catch(e){toast(e.message,'err');}
      });
    }
    qs('#add-pack',m).addEventListener('click',function(){edit(null);});
    load();
  }

  /* ---------- Audit ---------- */
  async function audit(){
    var m=qs('#main');
    m.innerHTML='<div class="card pad0"><div class="tablewrap" id="at"><div class="spinner"></div></div></div>';
    try{
      var logs=(await api('GET','/admin/audit')).logs||[];
      qs('#at').innerHTML=logs.length?('<table><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th></tr></thead><tbody>'+
        logs.map(function(l){return '<tr><td class="tiny muted">'+new Date(l.created_at).toLocaleString()+'</td><td class="wrap">'+esc(l.actor_name||l.actor_email||'system')+'</td><td>'+esc(l.action)+'</td><td>'+esc(l.entity)+' '+(l.entity_id?('#'+l.entity_id):'')+'</td></tr>';}).join('')+'</tbody></table>'):'<div class="muted" style="padding:16px">No audit entries.</div>';
    }catch(e){qs('#at').innerHTML='<div class="badge red">'+esc(e.message)+'</div>';}
  }

  document.addEventListener('DOMContentLoaded',boot);
})();
