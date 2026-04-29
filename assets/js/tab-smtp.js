/**
 * Admin Buddy - tab-smtp.js
 * SMTP tab. Vanilla ES6+. No jQuery.
 * @version 1.1.0-beta5
 */
( function () {
    'use strict';
    if ( ! document.getElementById('ab-smtp-form') ) { return; }

    var nonce   = ( document.getElementById('ab-smtp-nonce')   ||{}).value || '';
    var ajaxUrl = ( document.getElementById('ab-smtp-ajax-url')||{}).value || '';
    var S       = window.admbudSettings || {};

    function qs(sel,ctx){ return (ctx||document).querySelector(sel); }
    function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
    function post(data){
        var fd=new FormData();
        Object.keys(data).forEach(function(k){fd.append(k,data[k]);});
        return fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();});
    }

    // -- Mailer UI sync -------------------------------------------------------
    function syncMailerUI() {
        var isSmtp    = !!(qs('#ab-mailer-smtp')  &&qs('#ab-mailer-smtp').checked);
        var isEnabled = !!(qs('#ab-smtp-enabled') &&qs('#ab-smtp-enabled').checked);
        var card=qs('#ab-smtp-server-card');     if(card){    card.style.display=(isSmtp&&isEnabled)?'':'none';}
        var row=qs('#ab-mailer-row');            if(row){     row.style.display=isEnabled?'':'none';}
        var note=qs('#ab-phpmail-note');         if(note){    note.style.display=(!isSmtp)?'':'none';}
    }
    ['#ab-smtp-enabled','#ab-mailer-smtp','#ab-mailer-phpmail'].forEach(function(sel){
        var el=qs(sel); if(el){ el.addEventListener('change', syncMailerUI); }
    });
    syncMailerUI();

    // -- Auth toggle ----------------------------------------------------------
    var authToggle=qs('#ab-smtp-auth');
    if(authToggle){ authToggle.addEventListener('change', function(){
        var show=this.checked;
        ['#ab-smtp-credentials','#ab-smtp-password-row'].forEach(function(sel){
            var el=qs(sel); if(el){ el.style.display=show?'':'none'; }
        });
    }); }

    // -- Provider preset ------------------------------------------------------
    var presetSel=qs('#ab-smtp-preset');
    if(presetSel){ presetSel.addEventListener('change', function(){
        var opt=this.options[this.selectedIndex];
        if(!opt){return;}
        var host=opt.getAttribute('data-host'), port=opt.getAttribute('data-port'), enc=opt.getAttribute('data-enc');
        var h=qs('#admbud_smtp_host'), p=qs('#admbud_smtp_port'), e=qs('#admbud_smtp_encryption');
        if(host!==null&&h){ h.value=host; } if(port!==null&&p){ p.value=port; } if(enc!==null&&e){ e.value=enc; }
    }); }

    // -- Test email -----------------------------------------------------------
    var testBtn=qs('#ab-smtp-test-btn');
    if(testBtn){ testBtn.addEventListener('click', function(){
        var result=qs('#ab-smtp-test-result'), to=(qs('#ab-smtp-test-to')||{}).value||'';
        testBtn.textContent=S.smtpTesting||'Sending…'; testBtn.disabled=true;
        if(result){result.textContent=''; result.style.color='';}
        post({action:'admbud_smtp_test',nonce:nonce,to:to}).then(function(res){
            if(res.success){ if(result){result.textContent=res.data.message; result.style.color='var(--ab-success)';} }
            else { var msg=(res.data&&res.data.message)||'Failed.', det=(res.data&&res.data.detail)?(' - '+res.data.detail):'';
                   if(result){result.textContent=msg+det; result.style.color='var(--ab-danger)';} }
        }).catch(function(){ if(result){result.textContent='Request failed.'; result.style.color='var(--ab-danger)';} })
          .finally(function(){ testBtn.textContent='Send Test'; testBtn.disabled=false; });
    }); }

    // -- Email detail slide panel ---------------------------------------------
    var panel    = qs('#ab-email-panel');
    var backdrop = qs('#ab-email-panel-backdrop');

    function openPanel(entry) {
        if(!panel){return;}
        var isFail=entry.status==='failed';
        var subj=qs('#ab-email-panel-subject'); if(subj){subj.textContent=entry.subject||'(no subject)';}
        var meta=qs('#ab-email-panel-meta');
        if(meta){
            var html='<div><strong>To:</strong> '+escHtml(entry.to||'')+'</div>';
            if(entry.cc) { html+='<div><strong>CC:</strong> '+escHtml(entry.cc)+'</div>'; }
            if(entry.bcc){ html+='<div><strong>BCC:</strong> '+escHtml(entry.bcc)+'</div>'; }
            html+='<div><strong>Time:</strong> '+escHtml(entry.time||'')+'</div>';
            if(entry.headers){ Object.keys(entry.headers).forEach(function(k){ html+='<div><strong>'+escHtml(k)+':</strong> '+escHtml(entry.headers[k])+'</div>'; }); }
            if(entry.attachments&&entry.attachments.length){ html+='<div><strong>Attachments:</strong> '+escHtml(entry.attachments.join(', '))+'</div>'; }
            meta.innerHTML=html;
        }
        var statusEl=qs('#ab-email-panel-status');
        if (statusEl) {
            statusEl.innerHTML = isFail
                ? '<span class="ab-badge" style="background:var(--ab-danger-bg);color:var(--ab-danger-text);">Failed</span>'
                : '<span class="ab-badge" style="background:var(--ab-success-bg);color:var(--ab-success-text);">Sent</span>';
        }
        var body=qs('#ab-email-panel-body');
        if(body){
            var msg=entry.message||'', isHtml=/<[a-z][\s\S]*>/i.test(msg);
            if (isHtml) {
                var iframeCss = 'body{font-family:sans-serif;font-size:14px;line-height:1.6;padding:8px;margin:0}';
                var srcdoc = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
                    + iframeCss
                    + '</style></head><body>'
                    + msg
                    + '</body></html>';
                body.innerHTML = '<iframe srcdoc="'
                    + srcdoc.replace(/"/g, '&quot;')
                    + '" sandbox="allow-same-origin"></iframe>';
            }
            else { body.innerHTML='<pre>'+escHtml(msg)+'</pre>'; }
        }
        var errEl=qs('#ab-email-panel-error');
        if (errEl) {
            if (isFail && entry.error) {
                errEl.innerHTML =
                    '<div class="ab-notice ab-notice--error"><strong>Send error:</strong> '
                    + escHtml(entry.error) + '</div>';
                errEl.style.display = '';
            } else {
                errEl.style.display = 'none';
            }
        }
        if(backdrop){ backdrop.style.display=''; }
        panel.style.display='';
        requestAnimationFrame(function(){ requestAnimationFrame(function(){
            if(backdrop){backdrop.classList.add('is-open');}
            panel.classList.add('is-open');
            if(window.trapFocus){ window.trapFocus(panel); }
            var closeBtn2=qs('#ab-email-panel-close'); if(closeBtn2){closeBtn2.focus();}
        }); });
    }

    function closePanel() {
        if(!panel){return;}
        panel.classList.remove('is-open');
        if(backdrop){backdrop.classList.remove('is-open');}
        if(window.releaseFocus){window.releaseFocus();}
        setTimeout(function(){ if(!panel.classList.contains('is-open')){ panel.style.display='none'; if(backdrop){backdrop.style.display='none';} } },300);
    }

    document.addEventListener('click', function(e){
        var row=e.target.closest('.ab-log-row');
        if(row){ var entry={}; try{entry=JSON.parse(row.getAttribute('data-entry')||'{}');}catch(ex){} openPanel(entry); }
        if(e.target.closest('#ab-email-panel-close')){ closePanel(); }
    });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape'&&panel&&panel.classList.contains('is-open')){ closePanel(); } });

    // -- Clear log ------------------------------------------------------------
    var clearBtn=qs('#ab-smtp-clear-log');
    if(clearBtn){ clearBtn.addEventListener('click', function(){
        window.openConfirmModal(S.smtpClearConfirmTitle||'Clear Email Log?', S.smtpClearConfirmBody||'All entries will be permanently deleted.', function(){
            post({action:'admbud_smtp_clear_log',nonce:nonce}).then(function(res){
                if(res.success){ var logBody=qs('#ab-smtp-log-body'); if(logBody){var tbl=logBody.closest('table'); if(tbl){tbl.innerHTML='<p class="description">No emails logged yet.</p>';}} }
            });
        });
    }); }

} )();
