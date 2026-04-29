/**
 * Admin Buddy - tab-roles.js
 * User Roles tab. Vanilla ES6+. No jQuery.
 * @version 1.1.0-beta5
 */
( function () {
    'use strict';
    if ( ! document.getElementById('ab-role-select') ) { return; }

    var nonce       = ( document.getElementById('ab-roles-nonce')   ||{}).value||'';
    var ajaxUrl     = ( document.getElementById('ab-roles-ajax-url')||{}).value||'';
    var S           = window.admbudSettings||{};
    var currentRole = ( document.getElementById('ab-role-select')||{}).value||'administrator';
    function readJsonInput( id, fallback ) {
        var el = document.getElementById( id );
        if ( ! el ) { return fallback; }
        try { return JSON.parse( el.value ); } catch ( e ) { return fallback; }
    }
    var roleCaps    = readJsonInput( 'ab-roles-caps-data',    {} );
    var adminProt   = readJsonInput( 'ab-roles-admin-prot',   [] );
    var builtinRoles= readJsonInput( 'ab-roles-builtin-data', [] );

    function qs(sel,ctx){ return (ctx||document).querySelector(sel); }
    function qsa(sel,ctx){ return Array.from((ctx||document).querySelectorAll(sel)); }
    function on(ctx,evt,sel,fn){ ctx.addEventListener(evt,function(e){ var t=sel?e.target.closest(sel):e.target; if(sel&&!t){return;} fn.call(t,e); }); }
    function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function post(data){
        var fd=new FormData(); Object.keys(data).forEach(function(k){fd.append(k,data[k]);});
        return fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();});
    }
    function showModal(id){ var m=document.getElementById(id); if(m){m.style.display='';} }
    function hideModal(id){ var m=document.getElementById(id); if(m){m.style.display='none';} }

    // -- Load role -------------------------------------------------------------
    function loadRole(slug) {
        currentRole=slug;
        var caps=roleCaps[slug]||[], isAdmin=slug==='administrator', isBuiltin=builtinRoles.includes(slug);
        var sel=qs('#ab-role-select'), dispName=sel?sel.options[sel.selectedIndex]?.text||slug:slug;
        var badge=qs('#ab-role-slug-label');
        if(badge){badge.innerHTML='<span class="ab-role-badge__name">'+escHtml(dispName)+'</span> <span class="ab-role-badge__slug">'+escHtml(slug)+'</span>';}
        var notice=qs('#ab-role-admin-notice'), resetBtn=qs('#ab-role-reset-btn'), delBtn=qs('#ab-role-delete-btn');
        if(notice){notice.style.display=isAdmin?'':'none';}
        if(resetBtn){resetBtn.style.display=isBuiltin?'':'none';}
        if(delBtn){delBtn.style.display=!isBuiltin?'':'none';}
        qsa('.ab-cap-check').forEach(function(cb){
            var cap=cb.value, isChecked=caps.includes(cap), isProt=cb.getAttribute('data-protected')==='1';
            cb.checked=isChecked;
            if(isAdmin&&isProt){ cb.checked=true; cb.disabled=true; cb.closest('.ab-cap-item')?.classList.add('ab-cap-item--locked'); }
            else { cb.disabled=false; cb.closest('.ab-cap-item')?.classList.remove('ab-cap-item--locked'); }
        });
        updateGroupToggles(); updateCapCounts();
    }

    function updateGroupToggles(){
        qsa('.ab-cap-group').forEach(function(group){
            var checks=qsa('.ab-cap-check:not(:disabled)',group), checked=checks.filter(function(c){return c.checked;}), grp=qs('.ab-group-toggle',group);
            if(!grp){return;}
            if(checks.length===0){grp.checked=false; grp.indeterminate=false;}
            else if(checked.length===checks.length){grp.checked=true; grp.indeterminate=false;}
            else if(checked.length===0){grp.checked=false; grp.indeterminate=false;}
            else {grp.checked=false; grp.indeterminate=true;}
        });
    }
    function updateCapCounts(){
        qsa('.ab-cap-group').forEach(function(group){
            var total=qsa('.ab-cap-check',group).length, checked=qsa('.ab-cap-check:checked',group).length;
            var cnt=qs('.ab-cap-group__count',group); if(cnt){cnt.textContent='('+checked+'/'+total+')';}
        });
    }

    var roleSelect=qs('#ab-role-select');
    if(roleSelect){ roleSelect.addEventListener('change',function(){loadRole(this.value);}); }
    loadRole(currentRole);

    on(document,'change','.ab-cap-check',function(){ updateGroupToggles(); updateCapCounts(); var sb=qs('#ab-role-save-btn'); if(sb){sb.disabled=false;} });
    on(document,'change','.ab-group-toggle',function(){
        var checked=this.checked;
        qsa('.ab-cap-check:not(:disabled)',this.closest('.ab-cap-group')).forEach(function(c){c.checked=checked;});
        updateGroupToggles(); updateCapCounts();
        var sb=qs('#ab-role-save-btn'); if(sb){sb.disabled=false;}
    });

    var expBtn=qs('#ab-caps-expand-all');
    if(expBtn){ expBtn.addEventListener('click',function(){
        qsa('.ab-cap-group').forEach(function(g){
            var b  = qs('.ab-cap-group__body',    g);
            var l  = qs('.ab-cap-group__label',   g);
            var ch = qs('.ab-cap-group__chevron', g);
            if(b)  { b.style.display = ''; }
            if(l)  { l.setAttribute('aria-expanded','true'); }
            if(ch) { ch.style.transform = ''; }
        });
    }); }
    var colBtn=qs('#ab-caps-collapse-all');
    if(colBtn){ colBtn.addEventListener('click',function(){
        qsa('.ab-cap-group').forEach(function(g){
            var b  = qs('.ab-cap-group__body',    g);
            var l  = qs('.ab-cap-group__label',   g);
            var ch = qs('.ab-cap-group__chevron', g);
            if(b)  { b.style.display = 'none'; }
            if(l)  { l.setAttribute('aria-expanded','false'); }
            if(ch) { ch.style.transform = 'rotate(-90deg)'; }
        });
    }); }
    on(document,'click','.ab-cap-group__label',function(){
        var group=this.closest('.ab-cap-group'), body=qs('.ab-cap-group__body',group);
        var expanded=this.getAttribute('aria-expanded')!=='false';
        this.setAttribute('aria-expanded',!expanded);
        if(body){body.style.display=expanded?'none':'';}
        var ch=qs('.ab-cap-group__chevron',this); if(ch){ch.style.transform=expanded?'rotate(-90deg)':'';}
    });

    var capSearch=qs('#ab-cap-search');
    if(capSearch){ capSearch.addEventListener('input',function(){
        var q=this.value.toLowerCase().trim();
        qsa('.ab-cap-item').forEach(function(item){ item.style.display=(!q||(item.getAttribute('data-cap')||'').toLowerCase().includes(q))?'':'none'; });
        qsa('.ab-cap-group').forEach(function(g){
            var vis = qsa('.ab-cap-item', g).some(function(i){ return i.style.display !== 'none'; });
            g.style.display = vis ? '' : 'none';
            if (q && vis) {
                var b = qs('.ab-cap-group__body', g);
                if (b) { b.style.display = ''; }
            }
        });
    }); }

    // -- Save caps -------------------------------------------------------------
    var saveBtn=qs('#ab-role-save-btn');
    if(saveBtn){ saveBtn.addEventListener('click',function(){
        var name=(qs('#ab-role-select option:checked')||{}).textContent||currentRole;
        window.openConfirmModal(S.roleSaveConfirmTitle||'Save capabilities?','Save capabilities for "'+escHtml(name)+'"?',doSaveCaps, null, 'ab-btn--success');
    }); }
    function doSaveCaps(){
        var caps=qsa('.ab-cap-check:checked').map(function(c){return c.value;}), btn=qs('#ab-role-save-btn');
        // Disabled+checked checkboxes are collected by :checked, but as a safety net
        // ensure protected caps are always included for administrator.
        if(currentRole==='administrator'){
            qsa('.ab-cap-check[data-protected="1"]').forEach(function(c){
                if(caps.indexOf(c.value)===-1){caps.push(c.value);}
            });
        }
        if(btn){btn.disabled=true;}
        post({action:'admbud_roles_save',nonce:nonce,role:currentRole,caps:JSON.stringify(caps)}).then(function(res){
            if(res.success){ roleCaps[currentRole]=caps; window.showToast((res.data&&res.data.message)||S.roleSaved||'Saved.','success'); if(btn){btn.disabled=true;} }
            else { window.showToast((res.data&&res.data.message)||'Error.','error'); if(btn){btn.disabled=false;} }
        }).catch(function(){ window.showToast(S.requestFailed||'Request failed.','error'); if(btn){btn.disabled=false;} });
    }

    // -- Delete ----------------------------------------------------------------
    var delBtn=qs('#ab-role-delete-btn');
    if(delBtn){ delBtn.addEventListener('click',function(){
        window.openConfirmModal(S.roleDeleteConfirmTitle||'Delete Role?',S.roleDeleteConfirmBody||'Users will be moved to Subscriber. Cannot be undone.',function(){
            post({action:'admbud_roles_delete',nonce:nonce,role:currentRole}).then(function(res){
                if(res.success){
                    var opt=qs('#ab-role-select option[value="'+currentRole+'"]'); if(opt){opt.remove();}
                    delete roleCaps[currentRole];
                    var first=qs('#ab-role-select option'); if(first){roleSelect.value=first.value; loadRole(first.value);}
                    window.showToast(S.roleDeleted||'Role deleted.','success');
                } else { window.showToast((res.data&&res.data.message)||'Error.','error'); }
            });
        });
    }); }

    // -- Rename ----------------------------------------------------------------
    var renameBtn=qs('#ab-role-rename-btn');
    if(renameBtn){ renameBtn.addEventListener('click',function(){
        var inp=qs('#ab-rename-role-input'); if(inp){inp.value=(qs('#ab-role-select option:checked')||{}).textContent||'';}
        var err=qs('#ab-rename-role-error'); if(err){err.classList.add('ab-hidden'); err.textContent='';}
        showModal('ab-rename-role-modal');
        setTimeout(function(){ var inp2=qs('#ab-rename-role-input'); if(inp2){inp2.focus();} },160);
    }); }
    var renameConfirm=qs('#ab-rename-role-confirm');
    if(renameConfirm){ renameConfirm.addEventListener('click',function(){
        var name=(qs('#ab-rename-role-input')||{}).value?.trim()||''; if(!name){return;}
        post({action:'admbud_roles_rename',nonce:nonce,role:currentRole,name:name}).then(function(res){
            if (res.success) {
                var opt = qs('#ab-role-select option[value="' + currentRole + '"]');
                if (opt) { opt.textContent = name; }
                hideModal('ab-rename-role-modal');
                window.showToast(S.roleRenamed || 'Role renamed.', 'success');
            }
            else { var err=qs('#ab-rename-role-error'); if(err){err.textContent=(res.data&&res.data.message)||'Error.'; err.classList.remove('ab-hidden');} }
        });
    }); }

    // -- Create / Clone --------------------------------------------------------
    function openCreateModal(clone){
        var title=qs('#ab-create-role-title'); if(title){title.textContent=clone?'Clone Role':'Create Role';}
        var cr=qs('#ab-clone-from-row'); if(cr){cr.style.display='';}
        var cf=qs('#ab-clone-from'); if(cf){cf.value=clone?currentRole:'';}
        var inp=qs('#ab-new-role-name'); if(inp){inp.value='';}
        var err=qs('#ab-create-role-error'); if(err){err.classList.add('ab-hidden'); err.textContent='';}
        showModal('ab-create-role-modal');
        setTimeout(function(){ var n=qs('#ab-new-role-name'); if(n){n.focus();} },160);
    }
    var newBtn=qs('#ab-role-new-btn');   if(newBtn)  { newBtn.addEventListener('click',  function(){openCreateModal(false);}); }
    var cloneBtn=qs('#ab-role-clone-btn'); if(cloneBtn){ cloneBtn.addEventListener('click',function(){openCreateModal(true);}); }
    var createConfirm=qs('#ab-create-role-confirm');
    if(createConfirm){ createConfirm.addEventListener('click',function(){
        var name=(qs('#ab-new-role-name')||{}).value?.trim()||'', cf=(qs('#ab-clone-from')||{}).value||'';
        if(!name){ var n=qs('#ab-new-role-name'); if(n){n.focus();} return; }
        post({action:'admbud_roles_create',nonce:nonce,name:name,clone_from:cf}).then(function(res){
            if(res.success){
                roleCaps[res.data.slug]=res.data.caps||(cf?(roleCaps[cf]||[]).slice():['read']);
                var opt=document.createElement('option'); opt.value=res.data.slug; opt.textContent=res.data.name;
                if(roleSelect){roleSelect.appendChild(opt); roleSelect.value=res.data.slug;}
                hideModal('ab-create-role-modal'); loadRole(res.data.slug); window.showToast(S.roleCreated||'Role created.','success');
            } else { var err=qs('#ab-create-role-error'); if(err){err.textContent=(res.data&&res.data.message)||'Error.'; err.classList.remove('ab-hidden');} }
        });
    }); }

    // -- Reset -----------------------------------------------------------------
    var resetBtn=qs('#ab-role-reset-btn');
    if(resetBtn){ resetBtn.addEventListener('click',function(){
        window.openConfirmModal(S.roleResetConfirmTitle||'Reset Role?',S.roleResetConfirmBody||'Restore default capabilities.',function(){
            post({action:'admbud_roles_reset',nonce:nonce,role:currentRole}).then(function(res){ if(res.success){window.location.reload();} });
        });
    }); }

    // -- Backup ----------------------------------------------------------------
    var backupBtn=qs('#ab-role-backup-btn');
    if(backupBtn){ backupBtn.addEventListener('click',function(){
        backupBtn.disabled=true; backupBtn.textContent='Downloading…';
        post({action:'admbud_roles_backup',nonce:nonce}).then(function(res){
            if(res.success){
                var fname='admin-buddy-roles-'+new Date().toISOString().slice(0,10)+'.json';
                var blob=new Blob([JSON.stringify(res.data,null,2)],{type:'application/json'});
                var url=URL.createObjectURL(blob), a=document.createElement('a');
                a.href=url; a.download=fname; a.click(); URL.revokeObjectURL(url);
                var badge=qs('#ab-role-backup-badge'); if(badge){badge.textContent='Last backup: just now'; badge.style.display='';}
            }
        }).finally(function(){ backupBtn.disabled=false; backupBtn.textContent='Backup Roles'; });
    }); }

    // -- Modal close -----------------------------------------------------------
    on(document,'click','.ab-modal-close',function(){ hideModal(this.getAttribute('data-modal')); });
    on(document,'click','#ab-create-role-backdrop,#ab-rename-role-backdrop',function(){ this.closest('[id$="-modal"]') && hideModal(this.closest('[id$="-modal"]').id); });
    document.addEventListener('keydown',function(e){ if(e.key==='Escape'){ qsa('.ab-modal').forEach(function(m){if(m.style.display!=='none'){m.style.display='none';}}); } });
    on(document,'input','#ab-new-role-name,#ab-rename-role-input',function(){ var err=this.closest('.ab-modal')?.querySelector('.ab-notice--error'); if(err){err.classList.add('ab-hidden');} });

} )();
