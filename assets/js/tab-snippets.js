/**
 * Admin Buddy - tab-snippets.js
 * Snippets tab. Vanilla ES6+. No jQuery.
 * @version 1.2.0-beta1
 */
( function () {
    'use strict';
    if ( ! document.getElementById('ab-snippets-list') ) { return; }

    var nonce   = ( document.getElementById('ab-snippets-nonce')   ||{}).value||'';
    var ajaxUrl = ( document.getElementById('ab-snippets-ajax-url')||{}).value||'';
    var S       = window.admbudSettings||{};

    function qs(sel,ctx){ return (ctx||document).querySelector(sel); }
    function qsa(sel,ctx){ return Array.from((ctx||document).querySelectorAll(sel)); }
    function on(ctx,evt,sel,fn){ ctx.addEventListener(evt,function(e){ var t=sel?e.target.closest(sel):e.target; if(sel&&!t){return;} fn.call(t,e); }); }
    function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
    function post(data){
        var fd=new FormData(); Object.keys(data).forEach(function(k){fd.append(k,data[k]);});
        return fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();});
    }

    var modal    = qs('#ab-snippet-modal');
    var backdrop = qs('#ab-snippet-modal-backdrop');
    var editor   = null;
    var activeTab='all', showActive=true, showInactive=true, searchQuery='';
    var cmModes  = {php:'application/x-httpd-php',css:'text/css',js:'text/javascript',html:'text/html'};


    // -- Filters ---------------------------------------------------------------
    function applyFilters() {
        var query=searchQuery.toLowerCase(), hasVisible=false;
        var rows=qsa('.ab-snippet-row'), total=rows.length, aC=0, iC=0, sharedC=0;
        rows.forEach(function(row){
            var type=row.getAttribute('data-type'), isAct=row.getAttribute('data-active')==='1',
                isShared=row.getAttribute('data-is-shared')==='1',
                title=(row.getAttribute('data-search')||'').toLowerCase(),
                typeOk=activeTab==='all'||(activeTab==='shared'?isShared:type===activeTab),
                statOk=(isAct&&showActive)||(!isAct&&showInactive),
                qOk=!query||title.includes(query);
            row.style.display=(typeOk&&statOk&&qOk)?'':'none';
            if(typeOk&&statOk&&qOk){hasVisible=true;}
            if(isAct){aC++;}else{iC++;}
            if(isShared){sharedC++;}
        });
        var ac=qs('#ab-status-count-active'), ic=qs('#ab-status-count-inactive');
        if(ac){ac.textContent=aC;} if(ic){ic.textContent=iC;}
        qsa('.ab-snippet-tab:not(.ab-snippet-tab--status)').forEach(function(tab){
            var t=tab.getAttribute('data-tab');
            var cnt=t==='all'?total:t==='shared'?sharedC:qsa('.ab-snippet-row[data-type="'+t+'"]').length;
            var c=qs('.ab-snippet-tab__count',tab); if(c){c.textContent=cnt;}
        });
        var empty=qs('#ab-snippets-empty'), noRes=qs('#ab-snippets-no-results');
        if(total===0){if(empty){empty.style.display='';} if(noRes){noRes.style.display='none';}}
        else if(!hasVisible){if(empty){empty.style.display='none';} if(noRes){noRes.style.display='';}}
        else{if(empty){empty.style.display='none';} if(noRes){noRes.style.display='none';}}
    }

    var searchEl=qs('#ab-snippet-search');
    if(searchEl){ searchEl.addEventListener('input',function(){searchQuery=this.value; applyFilters();}); }
    on(document,'change','#ab-status-show-active',  function(){showActive=this.checked;   applyFilters();});
    on(document,'change','#ab-status-show-inactive',function(){showInactive=this.checked; applyFilters();});
    on(document,'click', '.ab-snippet-tab', function(){
        activeTab=this.getAttribute('data-tab');
        qsa('.ab-snippet-tab').forEach(function(t){t.classList.remove('ab-snippet-tab--active'); t.setAttribute('aria-selected','false');});
        this.classList.add('ab-snippet-tab--active'); this.setAttribute('aria-selected','true');
        applyFilters();
    });

    // -- CodeMirror ------------------------------------------------------------
    function triggerSave() { var b=qs('#ab-snippet-save'); if(b&&!b.disabled){b.click();} }

    // WP's wp-codemirror bundle strips the lint addon's hover-tooltip handler
    // (wp.codeEditor.initialize never calls showTooltipFor), so the standard
    // .CodeMirror-lint-tooltip element is never created. We work around it by
    // hooking onUpdateLinting and writing messages into each gutter marker's
    // `title` attribute — browsers render native tooltips for that.
    //
    // WP has a bug at wp-admin/js/code-editor.js L121:
    //   onUpdateLintingOverridden.apply( annotations, annotationsSorted, cm )
    // .apply(thisArg, argsArray) only takes 2 args, so this sets
    //   this = annotations (flat list)
    //   arguments = items of annotationsSorted (per-line groups, not the
    //     flat list we asked for)
    //   cm = silently dropped
    // We read `this` for the flat annotations and use the `editor` closure
    // for `cm`. If WP ever fixes the apply call, the positional `annotations`
    // would be correct and the `this` fallback wouldn't fire.
    function lintMarkerTitles(annotations) {
        // Disambiguate buggy vs. fixed WP wrappers.
        if (!Array.isArray(annotations) && Array.isArray(this)) {
            annotations = this;
        }
        var cm = editor;
        if (!Array.isArray(annotations) || !cm) { return; }

        var byLine = {};
        annotations.forEach(function (ann) {
            if (!ann || !ann.from) { return; }
            var line = ann.from.line;
            if (!byLine[line]) { byLine[line] = []; }
            var prefix = ann.severity === 'warning' ? '! ' : 'X ';
            byLine[line].push(prefix + (ann.message || ''));
        });
        // Walk every line so we also clear stale titles when errors are fixed.
        var total = cm.lineCount();
        for (var i = 0; i < total; i++) {
            var info = cm.lineInfo(i);
            var marker = info && info.gutterMarkers && info.gutterMarkers['CodeMirror-lint-markers'];
            if (!marker) { continue; }
            marker.title = byLine[i] ? byLine[i].join('\n') : '';
        }
    }

    function lintOptionFor(type) {
        if (type !== 'css' && type !== 'js' && type !== 'html') { return false; }
        return { onUpdateLinting: lintMarkerTitles };
    }

    function initCodeMirror(type) {
        var mode=cmModes[type]||cmModes.php, ta=document.getElementById('ab-edit-code');
        if(!ta){return;}
        var lintOpt = lintOptionFor(type);
        if(editor){
            editor.setOption('mode',mode);
            editor.setOption('lint', lintOpt);
            editor.setOption('autoCloseTags', type==='html');
            editor.setOption('matchTags', type==='html' ? { bothTags: true } : false);
            editor.refresh();
            if(lintOpt && typeof editor.performLint === 'function'){
                setTimeout(function(){ try{ editor.performLint(); }catch(e){} }, 60);
            }
            return;
        }
        if(typeof wp==='undefined'||!wp.codeEditor){return;}
        var settings=wp.codeEditor.defaultSettings?(Object.assign({},wp.codeEditor.defaultSettings)):{};
        // wp.codeEditor.initialize reads csslint/jshint/htmlhint from the TOP
        // level of the settings object (not under codemirror.lint) to set up
        // the actual linter helper. defaultSettings only has the last enqueue
        // call's config (HTML), so we re-inject all three from our captured
        // per-type maps to make sure the matching one is present for any mode.
        var linters = (window.admbudSnippetData && window.admbudSnippetData.linters) || {};
        if (linters.csslint)  { settings.csslint  = linters.csslint;  }
        if (linters.jshint)   { settings.jshint   = linters.jshint;   }
        if (linters.htmlhint) { settings.htmlhint = linters.htmlhint; }
        settings.codemirror=Object.assign({},settings.codemirror,{
            mode: mode,
            theme: 'default',
            lineNumbers: true,
            lineWrapping: false,
            indentUnit: 4,
            tabSize: 4,
            indentWithTabs: false,
            autoCloseBrackets: true,
            autoCloseTags: type==='html',
            matchBrackets: true,
            matchTags: type==='html' ? { bothTags: true } : false,
            styleActiveLine: true,
            foldGutter: true,
            lint: lintOpt,
            gutters: ['CodeMirror-linenumbers','CodeMirror-foldgutter','CodeMirror-lint-markers'],
            extraKeys: {
                'Tab': 'indentMore',
                'Shift-Tab': 'indentLess',
                'Ctrl-Space': 'autocomplete',
                'Ctrl-/': 'toggleComment',
                'Cmd-/': 'toggleComment',
                'Ctrl-F': 'findPersistent',
                'Cmd-F': 'findPersistent',
                'Ctrl-S': triggerSave,
                'Cmd-S':  triggerSave
            }
        });
        var inst=wp.codeEditor.initialize(ta,settings);
        editor=inst.codemirror;

        // Auto-trigger PHP function hints as the user types identifier chars.
        editor.on('inputRead', function (cm, change) {
            if (cm.getOption('mode') !== cmModes.php) { return; }
            if (!change.text || !change.text[0]) { return; }
            if (!/[a-z_]/i.test(change.text[0])) { return; }
            cm.showHint({ completeSingle: false });
        });

        // Clear inline error widget on edit so stale messages don't linger.
        editor.on('change', function () {
            if (editor._abErrWidget) { editor._abErrWidget.clear(); editor._abErrWidget = null; }
            if (editor._abErrMarker != null) { editor.setGutterMarker(editor._abErrMarker, 'CodeMirror-lint-markers', null); editor._abErrMarker = null; }
        });
    }

    // Renders an inline lint marker + widget at the line reported by `php -l`.
    function showInlineLintError(message) {
        if (!editor || !message) { return; }
        var m = String(message).match(/on line\s+(\d+)/i);
        if (!m) { return; }
        var line = Math.max(0, parseInt(m[1], 10) - 1);
        // Gutter marker (red dot in the lint gutter).
        var marker = document.createElement('div');
        marker.className = 'CodeMirror-lint-marker-error';
        marker.title = message;
        editor.setGutterMarker(line, 'CodeMirror-lint-markers', marker);
        editor._abErrMarker = line;
        // Inline widget below the offending line.
        var widget = document.createElement('div');
        widget.className = 'ab-cm-inline-error';
        widget.textContent = message.replace(/\s+on line\s+\d+\.?$/i, '');
        editor._abErrWidget = editor.addLineWidget(line, widget, { coverGutter: false, noHScroll: true });
        editor.scrollIntoView({ line: line, ch: 0 }, 100);
    }

    // -- Modal open/close ------------------------------------------------------
    function openModal(data) {
        var errEl=qs('#ab-snippet-syntax-error'); if(errEl){errEl.style.display='none'; errEl.textContent='';}
        var fields={id:data.id||0,title:data.title||'',notes:data.notes||'',scope:data.scope||'global',position:data.position||'footer',priority:data.priority||10};
        Object.keys(fields).forEach(function(k){ var el=qs('#ab-edit-'+k); if(el){el.value=fields[k];} });
        var activeEl=qs('#ab-edit-active'); if(activeEl){activeEl.checked=data.active!==0;}
        var sharedEl=qs('#ab-edit-is-shared'); if(sharedEl){sharedEl.checked=!!parseInt(data.is_shared||0);}
        var titleEl=qs('#ab-editor-title-text'); if(titleEl){titleEl.textContent=data.id?'Edit Snippet':'New Snippet';}
        var type=data.type||'php';
        qsa('input[name="admbud_edit_type"]').forEach(function(r){r.checked=r.value===type;});
        togglePositionRow(type); togglePhpNote(type);
        if(backdrop){backdrop.style.display='';} if(modal){modal.style.display='';}
        document.body.classList.add('ab-modal-open');
        requestAnimationFrame(function(){requestAnimationFrame(function(){
            if(backdrop){backdrop.classList.add('is-open');} if(modal){modal.classList.add('is-open');}
            if(window.trapFocus){window.trapFocus(modal);}
        });});
        setTimeout(function(){
            initCodeMirror(type);
            if(editor){
                editor.setValue(data.code||'');
                editor.setSize('100%',400);
                editor.refresh();
                editor.on('change',updateSaveBtn);
                // Force an immediate lint pass so markers appear on open
                // instead of after the addon's internal debounce.
                if(typeof editor.performLint === 'function'){
                    setTimeout(function(){ try{ editor.performLint(); }catch(e){} }, 60);
                }
            }
            else { var ca=qs('#ab-edit-code'); if(ca){ca.value=data.code||''; ca.addEventListener('input',updateSaveBtn);} }
            updateSaveBtn();
            var tf=qs('#ab-edit-title'); if(tf){tf.focus();}
        },150);
    }
    function closeModal() {
        if(modal){modal.classList.remove('is-open');}
        if(backdrop){backdrop.classList.remove('is-open');}
        document.body.classList.remove('ab-modal-open');
        if(window.releaseFocus){window.releaseFocus();}
        setTimeout(function(){ if(modal&&!modal.classList.contains('is-open')){modal.style.display='none'; if(backdrop){backdrop.style.display='none';}} },300);
    }
    function togglePositionRow(type){ qsa('.ab-snippet-position-row').forEach(function(r){r.style.display=type!=='php'?'':'none';}); }
    function togglePhpNote(type){ var el=qs('#ab-php-note'); if(el){el.style.display=type==='php'?'':'none';} }
    function updateSaveBtn(){ var code=editor?editor.getValue():(qs('#ab-edit-code')||{}).value||''; var btn=qs('#ab-snippet-save'); if(btn){btn.disabled=code.trim()==='';} }

    var newBtn=qs('#ab-snippet-new'); if(newBtn){newBtn.addEventListener('click',function(){openModal({});});}
    on(document,'click','.ab-snippet-close',closeModal);
    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&modal&&modal.classList.contains('is-open')){closeModal();}});
    on(document,'change','input[name="admbud_edit_type"]',function(){
        var type=this.value; togglePositionRow(type); togglePhpNote(type);
        if(editor){editor.setOption('mode',cmModes[type]||cmModes.php);}
    });

    // -- Edit button -----------------------------------------------------------
    on(document,'click','.ab-snippet-edit',function(){
        var btn=this, id=btn.getAttribute('data-id'); btn.disabled=true;
        post({action:'admbud_snippet_get',nonce:nonce,id:id}).then(function(res){
            if(res.success){openModal(res.data);}
        }).finally(function(){btn.disabled=false;});
    });

    // -- Save ------------------------------------------------------------------
    function doSave() {
        var btn   = qs( '#ab-snippet-save' );
        var code  = editor ? editor.getValue() : ( ( qs( '#ab-edit-code' ) || {} ).value || '' );
        var type  = ( qs( 'input[name="admbud_edit_type"]:checked' ) || {} ).value || 'php';
        var isNew = ( qs( '#ab-edit-id' ) || {} ).value === '0';
        if ( btn ) { btn.disabled = true; }
        var errEl = qs( '#ab-snippet-syntax-error' );
        if ( errEl ) { errEl.style.display = 'none'; }

        post( {
            action:    'admbud_snippet_save',
            nonce:     nonce,
            id:        ( qs( '#ab-edit-id' ) || {} ).value || 0,
            title:     ( qs( '#ab-edit-title' ) || {} ).value || '',
            notes:     ( qs( '#ab-edit-notes' ) || {} ).value || '',
            type:      type,
            scope:     ( qs( '#ab-edit-scope' ) || {} ).value || 'global',
            position:  ( qs( '#ab-edit-position' ) || {} ).value || 'footer',
            priority:  ( qs( '#ab-edit-priority' ) || {} ).value || 10,
            active:    ( qs( '#ab-edit-active' ) || {} ).checked ? 1 : 0,
            is_shared: ( qs( '#ab-edit-is-shared' ) || {} ).checked ? 1 : 0,
            code:      code,
        } )
        .then( function ( res ) {
            if ( res.success ) {
                closeModal();
                window.showToast(
                    isNew ? ( S.snippetCreated || 'Snippet created.' )
                          : ( S.snippetSavedOk || 'Snippet saved.' ),
                    'success'
                );
                setTimeout( function () { window.location.reload(); }, 700 );
                return;
            }
            var msg = ( res.data && res.data.message ) || 'Error.';
            var det = ( res.data && res.data.detail ) ? ( '\n' + res.data.detail ) : '';
            if ( errEl ) {
                errEl.textContent  = msg + det;
                errEl.style.display = '';
            }
            showInlineLintError( ( res.data && res.data.detail ) || ( res.data && res.data.message ) || '' );
            if ( btn ) { btn.disabled = false; }
        } )
        .catch( function () {
            window.showToast( S.saveFailed || 'Save failed.', 'error' );
            if ( btn ) { btn.disabled = false; }
        } );
    }
    var saveBtn = qs( '#ab-snippet-save' );
    if ( saveBtn ) {
        saveBtn.addEventListener( 'click', function () {
            var title = ( qs( '#ab-edit-title' ) || {} ).value || '(untitled)';
            var isNew = ( qs( '#ab-edit-id' ) || {} ).value === '0';
            window.openConfirmModal(
                isNew ? 'Create snippet?' : 'Save snippet?',
                ( isNew ? 'Create' : 'Save changes to' ) + ' "' + escHtml( title ) + '"?',
                doSave,
                null,
                'ab-btn--success'
            );
        } );
    }

    // -- Toggle active ---------------------------------------------------------
    on( document, 'change', '.ab-snippet-toggle', function () {
        var cb     = this;
        var id     = cb.getAttribute( 'data-id' );
        var active = cb.checked ? 1 : 0;
        var row    = cb.closest( '.ab-snippet-row' );

        post( { action: 'admbud_snippet_toggle', nonce: nonce, id: id, active: active } )
            .then( function ( res ) {
                if ( ! res.success ) { return; }
                if ( row ) {
                    row.setAttribute( 'data-active', active );
                    row.classList.toggle( 'ab-snippet-row--inactive', ! active );
                }
                applyFilters();
                window.showToast( active ? 'Snippet enabled.' : 'Snippet disabled.', 'info' );
            } )
            .catch( function () {
                cb.checked = ! active;
                window.showToast( S.toggleFailed || 'Toggle failed.', 'error' );
            } );
    } );

    // -- Delete ----------------------------------------------------------------
    on( document, 'click', '.ab-snippet-delete', function () {
        var id    = this.getAttribute( 'data-id' );
        var title = this.closest( '.ab-snippet-row' )?.querySelector( '.ab-snippet-row__name' )?.textContent?.trim() || 'this snippet';

        window.openConfirmModal(
            S.snippetDeleteConfirmTitle || 'Delete Snippet?',
            ( S.snippetDeleteConfirmBody || 'This cannot be undone.' ) + ' "' + escHtml( title ) + '"',
            function () {
                post( { action: 'admbud_snippet_delete', nonce: nonce, id: id } ).then( function ( res ) {
                    if ( ! res.success ) { return; }
                    var row = qs( '.ab-snippet-row[data-id="' + id + '"]' );
                    if ( row ) {
                        row.style.transition = 'opacity .2s';
                        row.style.opacity    = '0';
                        setTimeout( function () { row.remove(); applyFilters(); }, 200 );
                    }
                    window.showToast( S.snippetDeleted || 'Snippet deleted.', 'success' );
                } );
            }
        );
    } );

    // -- Shared badge toggle ---------------------------------------------------
    on( document, 'click', '.ab-snippet-shared-toggle', function ( e ) {
        e.stopPropagation();
        var btn       = this;
        var id        = btn.getAttribute( 'data-id' );
        var isShared  = btn.getAttribute( 'data-shared' ) === '1';
        var newShared = isShared ? 0 : 1;
        btn.disabled  = true;

        post( { action: 'admbud_snippet_save_shared', nonce: nonce, id: id, is_shared: newShared } )
            .then( function ( res ) {
                btn.disabled = false;
                if ( ! res.success ) { return; }

                btn.setAttribute( 'data-shared', String( newShared ) );
                var row = btn.closest( '.ab-snippet-row' );
                if ( row ) { row.setAttribute( 'data-is-shared', String( newShared ) ); }

                if ( newShared ) {
                    btn.classList.remove( 'ab-badge--neutral' );
                    btn.classList.add( 'ab-badge--success' );
                    btn.title = 'Shared via Source - click to unshare';
                    btn.lastChild.textContent = 'Shared';
                } else {
                    btn.classList.remove( 'ab-badge--success' );
                    btn.classList.add( 'ab-badge--neutral' );
                    btn.title = 'Not shared - click to share via Source';
                    btn.lastChild.textContent = 'Share';
                }
                applyFilters();
            } );
    } );

    // -- Samples dropdown ------------------------------------------------------
    var SAMPLES = {
        php: {
            title:    'Sample - Admin Notice',
            type:     'php',
            scope:    'admin',
            position: 'footer',
            priority: 10,
            active:   1,
            code:     "add_action( 'admin_notices', function () {\n    echo '<div class=\"notice notice-info is-dismissible\"><p>Hello!</p></div>';\n} );",
        },
        css: {
            title:    'Sample - Hide Admin Bar',
            type:     'css',
            scope:    'frontend',
            position: 'head',
            priority: 10,
            active:   1,
            code:     '#wpadminbar { display: none !important; }\nhtml { margin-top: 0 !important; }',
        },
        js: {
            title:    'Sample - Console Hello',
            type:     'js',
            scope:    'frontend',
            position: 'footer',
            priority: 10,
            active:   1,
            code:     "document.addEventListener( 'DOMContentLoaded', function () {\n    console.log( 'Hello from Admin Buddy!' );\n} );",
        },
        html: {
            title:    'Sample - Footer Note',
            type:     'html',
            scope:    'frontend',
            position: 'footer',
            priority: 10,
            active:   1,
            code:     '<!-- Footer note -->\n<div style="text-align:center;padding:8px;font-size:.75rem;color:#6b7280;">Sample footer note.</div>',
        },
    };

    var samplesToggle = qs( '#ab-samples-toggle' );
    var samplesMenu   = qs( '#ab-samples-menu' );
    if ( samplesToggle && samplesMenu ) {
        samplesToggle.addEventListener( 'click', function ( e ) {
            e.stopPropagation();
            var open = samplesMenu.style.display !== 'none';
            samplesMenu.style.display = open ? 'none' : '';
            this.setAttribute( 'aria-expanded', ! open );
        } );
    }
    document.addEventListener( 'click', function ( e ) {
        if ( ! samplesMenu ) { return; }
        if ( e.target.closest( '.ab-samples-dropdown' ) ) { return; }
        samplesMenu.style.display = 'none';
        if ( samplesToggle ) { samplesToggle.setAttribute( 'aria-expanded', 'false' ); }
    } );
    on( document, 'click', '.ab-sample-item', function () {
        var key    = this.getAttribute( 'data-sample' );
        var sample = SAMPLES[ key ];
        if ( samplesMenu )   { samplesMenu.style.display = 'none'; }
        if ( samplesToggle ) { samplesToggle.setAttribute( 'aria-expanded', 'false' ); }
        if ( sample ) { openModal( Object.assign( {}, sample, { id: 0 } ) ); }
    } );

    applyFilters();
} )();
