/**
 * Admin Buddy - setup-modules.js
 *
 * React-powered module toggle grid for Setup → Modules subtab.
 * Modules are grouped into Interface, Utilities, Integrations.
 * Group open/closed state persists in localStorage.
 *
 * @version 1.1.0
 * @package Admbud
 */

/* global wp, admbudSetupData, admbudIcons */

( function () {
	'use strict';

	if ( typeof wp === 'undefined' || ! wp.element ) { return; }
	const mountPoint = document.getElementById( 'ab-setup-modules-react' );
	if ( ! mountPoint ) { return; }

	const { createElement: el, useState, useCallback, useRef } = wp.element;
	const S = window.admbudSettings || {};

	const GROUPS = [
		{ key: 'interface',    label: S.groupInterface || 'Interface' },
		{ key: 'utilities',    label: S.groupUtilities || 'Utilities' },
		{ key: 'integrations', label: S.groupIntegrations || 'Integrations' },
		{ key: 'manage',       label: S.groupManage || 'Manage' },
	];

	const LS_KEY = 'admbud_modules_groups_open';

	function loadGroupState() {
		try {
			return JSON.parse( localStorage.getItem( LS_KEY ) || '{}' );
		} catch ( e ) { return {}; }
	}

	function saveGroupState( state ) {
		try { localStorage.setItem( LS_KEY, JSON.stringify( state ) ); } catch ( e ) {}
	}

	// -- AJAX helper ----------------------------------------------------------
	function ajaxPost( action, params ) {
		const data = new FormData();
		data.append( 'action', action );
		data.append( 'nonce',  admbudSetupData.nonce );
		Object.keys( params ).forEach( function ( k ) { data.append( k, params[ k ] ); } );
		return fetch( admbudSetupData.ajaxUrl, { method: 'POST', body: data } )
			.then( function ( r ) { return r.json(); } );
	}

	// -- ModuleToggle ---------------------------------------------------------
	function ModuleToggle( props ) {
		const { slug, label, enabled, alwaysOn, mode, onToggle, busy } = props;

		const handleChange = useCallback( function ( e ) {
			const newEnabled = e.target.checked;
			if ( slug === 'maintenance' && ! newEnabled && mode !== 'off' ) {
				e.target.checked = true;
				if ( typeof window.openConfirmModal === 'function' ) {
					const modeLabel = mode === 'coming_soon' ? 'Coming Soon' : 'Maintenance';
					window.openConfirmModal(
						modeLabel + ' Mode is Active',
						S.maintenanceWarning || 'Turn it off in the Maintenance tab first, then you can disable this module.',
						function () { window.location.href = admbudSetupData.maintenanceUrl || '#maintenance'; },
						S.goToMaintenance || 'Go to Maintenance', 'ab-btn--primary'
					);
				}
				return;
			}
			onToggle( slug, newEnabled );
		}, [ slug, enabled, mode, onToggle ] );

		const iconSvg = ( admbudIcons && admbudIcons[ slug ] ) ? admbudIcons[ slug ] : '';
		const checkboxId = 'ab-module-chk-' + slug;

		return el( 'div',
			{ className: 'ab-module-card' + ( enabled ? ' is-enabled' : '' ) + ( alwaysOn ? ' is-always-on' : '' )
				,
			  'data-module': slug,
			  onClick: ( alwaysOn
				) ? null : function( e ) {
			  	// Don't double-fire if the label/checkbox itself was clicked.
			  	if ( e.target.tagName === 'INPUT' ) { return; }
			  	handleChange( { target: { checked: ! enabled } } );
			  }
			},
			el( 'div', { className: 'ab-module-card__icon', dangerouslySetInnerHTML: { __html: iconSvg } } ),
			el( 'div', { className: 'ab-module-card__body' },
				el( 'span', { className: 'ab-module-card__label' }, label ),
				alwaysOn ? el( 'span', { className: 'ab-badge ab-badge--info ab-module-card__badge' }, 'Always on' ) : null,
			),
			el( 'label',
				{ className: 'ab-toggle ab-module-card__toggle',
				  htmlFor: checkboxId,
				  style: { pointerEvents: 'none' },
				  title: alwaysOn ? ( S.moduleAlwaysOn || 'This module cannot be disabled' ) : ( enabled ? ( S.disableModule || 'Disable' ) + ' ' : ( S.enableModule || 'Enable' ) + ' ' ) + label },
				el( 'input', { type: 'checkbox', id: checkboxId, checked: enabled, disabled: alwaysOn || busy
					, onChange: ( alwaysOn
					) ? null : handleChange } ),
				el( 'span', { className: 'ab-toggle__track' } ),
				el( 'span', { className: 'ab-toggle__thumb' } )
			)
		);
	}

	// -- GroupSection ---------------------------------------------------------
	function GroupSection( props ) {
		const { group, modules, maintenanceMode, onToggle, busySlug, onGroupBulk } = props;
		const [ open, setOpen ] = useState( props.defaultOpen );

		const handleToggle = function () {
			const next = ! open;
			setOpen( next );
			const state = loadGroupState();
			state[ group.key ] = next;
			saveGroupState( state );
		};

		const chevronStyle = { transform: open ? 'rotate(0deg)' : 'rotate(-90deg)', transition: 'transform 0.2s', display: 'inline-flex' };

		return el( 'div', { className: 'ab-module-group' },
			// Group header
			el( 'div', { className: 'ab-module-group__header', onClick: handleToggle, role: 'button', 'aria-expanded': open },
				el( 'span', { className: 'ab-module-group__chevron', style: chevronStyle },
					el( 'svg', { width: '12', height: '12', fill: 'none', viewBox: '0 0 24 24', stroke: 'currentColor', strokeWidth: '2.5' },
						el( 'polyline', { points: '6 9 12 15 18 9' } )
					)
				),
				el( 'span', { className: 'ab-module-group__label' }, group.label ),
				el( 'span', { className: 'ab-module-group__count ab-text-muted ab-text-xs' },
					modules.filter( function ( m ) { return m.enabled; } ).length + '/' + modules.length
				),
				// Per-group bulk buttons (stop propagation so header click doesn't fire)
				el( 'div', { className: 'ab-btn-group ab-module-group__bulk',
					onClick: function ( e ) { e.stopPropagation(); } },
					el( 'button', {
						type: 'button', className: 'ab-btn ab-btn--secondary ab-btn--xs',
						disabled: busySlug === '__bulk__',
						onClick: function () {
							var doEnable = function () { onGroupBulk( group.key, true ); };
							if ( typeof window.openConfirmModal !== 'function' ) {
								doEnable();
								return;
							}
							window.openConfirmModal(
								( S.enableModule || 'Enable' ) + ' ' + group.label + '?',
								( S.enableModulesConfirm || 'All modules in this group will be enabled.' ),
								doEnable,
								null,
								'ab-btn--success'
							);
						},
					}, S.enableAllModules || 'Enable all' ),
					el( 'button', {
						type: 'button', className: 'ab-btn ab-btn--secondary ab-btn--xs',
						disabled: busySlug === '__bulk__',
						onClick: function () {
							var doDisable = function () { onGroupBulk( group.key, false ); };
							if ( typeof window.openConfirmModal !== 'function' ) {
								doDisable();
								return;
							}
							window.openConfirmModal(
								( S.disableModule || 'Disable' ) + ' ' + group.label + '?',
								( S.disableModulesConfirm || 'All modules in this group will be removed from the navigation.' ),
								doDisable
							);
						},
					}, S.disableAllModules || 'Disable all' )
				)
			),
			// Module grid (collapsible)
			open ? el( 'div', { className: 'ab-grid ab-grid--ruled ab-grid--module' },
				modules.map( function ( m ) {
					return el( ModuleToggle, {
						key: m.slug, slug: m.slug, label: m.label, enabled: m.enabled,
						alwaysOn: m.always_on || false, mode: maintenanceMode || 'off',
						onToggle: onToggle, busy: busySlug === m.slug,
					} );
				} )
			) : null
		);
	}

	// -- SetupModulesApp -------------------------------------------------------
	function SetupModulesApp( props ) {
		const { modules: initialModules, maintenanceMode } = props;
		const [ modules, setModules ] = useState( initialModules );
		const [ busySlug, setBusySlug ] = useState( null );

		// Determine default open state for each group
		const savedState = loadGroupState();
		function isGroupOpen( groupKey ) {
			if ( groupKey in savedState ) { return savedState[ groupKey ]; }
			return true; // All groups open by default
		}

		// -- Single toggle --------------------------------------------------
		// The admbud_modules_toggle endpoint does read-modify-write on a shared
		// comma-joined option string, which races across concurrent requests
		// (both reads see the pre-write value; second write clobbers first).
		// Serialise the AJAX calls on a promise chain so only one is in
		// flight at a time. Optimistic UI updates stay instant; the user
		// never waits for the write to see their toggle flip.
		const chainRef = useRef( Promise.resolve() );

		const handleToggle = useCallback( function ( slug, newEnabled ) {
			setBusySlug( slug );
			setModules( function ( prev ) {
				return prev.map( function ( m ) { return m.slug === slug ? Object.assign( {}, m, { enabled: newEnabled } ) : m; } );
			} );

			const myPromise = chainRef.current.then( function () {
				return ajaxPost( 'admbud_modules_toggle', { slug: slug, enabled: newEnabled ? '1' : '0' } );
			} );
			// Continue the chain even on reject so one failure doesn't
			// permanently block subsequent clicks.
			chainRef.current = myPromise.catch( function () {} );

			if ( window.admbudReloadAfterAll ) { window.admbudReloadAfterAll.wait( myPromise ); }

			myPromise
				.then( function ( json ) {
					if ( json && json.success ) {
						if ( window.admbudReloadAfterAll ) { window.admbudReloadAfterAll.arm(); }
						return;
					}
					setModules( function ( prev ) {
						return prev.map( function ( m ) { return m.slug === slug ? Object.assign( {}, m, { enabled: ! newEnabled } ) : m; } );
					} );
					if ( typeof window.admbudShowToast === 'function' ) {
						window.admbudShowToast( ( json && json.data && json.data.message ) ? json.data.message : 'Could not update module.', 'error' );
					}
				} )
				.catch( function () {
					setModules( function ( prev ) {
						return prev.map( function ( m ) { return m.slug === slug ? Object.assign( {}, m, { enabled: ! newEnabled } ) : m; } );
					} );
					if ( typeof window.admbudShowToast === 'function' ) { window.admbudShowToast( 'Request failed. Please try again.', 'error' ); }
				} )
				.finally( function () { setBusySlug( null ); } );
		}, [] );

		// -- Global bulk toggle ---------------------------------------------
		const handleBulkToggle = useCallback( function ( enable ) {
			setBusySlug( '__bulk__' );
			const p = ajaxPost( 'admbud_modules_bulk_toggle', { enabled: enable ? '1' : '0' } );
			if ( window.admbudReloadAfterAll ) { window.admbudReloadAfterAll.wait( p ); }
			p
				.then( function ( json ) {
					if ( json.success ) {
						setModules( function ( prev ) {
							return prev.map( function ( m ) {
								if ( m.always_on ) { return m; }
								if ( ! enable && m.slug === 'maintenance' ) { return m; }
								return Object.assign( {}, m, { enabled: enable } );
							} );
						} );
						if ( json.data && json.data.maintenance_skipped && typeof window.admbudShowToast === 'function' ) {
							window.admbudShowToast( 'Maintenance module was kept active because a mode is currently on.', 'info', 5000 );
						}
						if ( window.admbudReloadAfterAll ) { window.admbudReloadAfterAll.arm(); }
					} else {
						if ( typeof window.admbudShowToast === 'function' ) { window.admbudShowToast( 'Bulk update failed.', 'error' ); }
					}
				} )
				.catch( function () {
					if ( typeof window.admbudShowToast === 'function' ) { window.admbudShowToast( 'Request failed. Please try again.', 'error' ); }
				} )
				.finally( function () { setBusySlug( null ); } );
		}, [] );

		// -- Per-group bulk -------------------------------------------------
		const handleGroupBulk = useCallback( function ( groupKey, enable ) {
			setBusySlug( '__bulk__' );
			// Single atomic request - avoids read-modify-write race on the server
			const p = ajaxPost( 'admbud_modules_group_toggle', { group: groupKey, enabled: enable ? '1' : '0' } );
			if ( window.admbudReloadAfterAll ) { window.admbudReloadAfterAll.wait( p ); }
			p
				.then( function ( json ) {
					if ( json.success ) {
						if ( window.admbudReloadAfterAll ) { window.admbudReloadAfterAll.arm(); }
					} else {
						if ( typeof window.admbudShowToast === 'function' ) { window.admbudShowToast( 'Group update failed.', 'error' ); }
					}
				} )
				.catch( function () {
					if ( typeof window.admbudShowToast === 'function' ) { window.admbudShowToast( 'Request failed. Please try again.', 'error' ); }
				} )
				.finally( function () { setBusySlug( null ); } );
		}, [] );

		// -- Render ---------------------------------------------------------
		const totalEnabled = modules.filter( function ( m ) { return m.enabled; } ).length;

		return el( 'div', { className: 'ab-setup-modules' },

			// Global toolbar
			el( 'div', { className: 'ab-setup-modules__toolbar' },
				el( 'div', { className: 'ab-btn-group' },
					el( 'button', {
						type: 'button', className: 'ab-btn ab-btn--secondary ab-btn--sm',
						disabled: busySlug === '__bulk__',
						onClick: function () {
							if ( typeof window.openConfirmModal !== 'function' ) {
								handleBulkToggle( true );
								return;
							}
							window.openConfirmModal(
								( S.enableModule || 'Enable' ) + ' all?',
								S.enableModulesConfirm || 'All modules will be added to the navigation menu.',
								function () { handleBulkToggle( true ); },
								null,
								'ab-btn--success'
							);
						},
					}, S.enableAllModules || 'Enable all' ),
					el( 'button', {
						type: 'button', className: 'ab-btn ab-btn--secondary ab-btn--sm',
						disabled: busySlug === '__bulk__',
						onClick: function () {
							if ( typeof window.openConfirmModal !== 'function' ) {
								handleBulkToggle( false );
								return;
							}
							window.openConfirmModal(
								( S.disableModule || 'Disable' ) + ' all?',
								S.disableModulesConfirm || 'All modules except Setup will be removed from the navigation menu.',
								function () { handleBulkToggle( false ); }
							);
						},
					}, S.disableAllModules || 'Disable all' )
				),
				el( 'span', { className: 'ab-setup-modules__count ab-text-muted ab-text-xs' },
					totalEnabled + ' of ' + modules.length + ' modules active'
				),
				// Expand / Collapse All - right side
				el( 'div', { className: 'ab-btn-group' },
					el( 'button', {
						type: 'button', className: 'ab-btn ab-btn--ghost ab-btn--sm',
						onClick: function () {
							var next = {};
							GROUPS.forEach( function ( g ) { next[ g.key ] = true; } );
							saveGroupState( next );
							window.location.reload();
						},
					}, 'Expand all' ),
					el( 'button', {
						type: 'button', className: 'ab-btn ab-btn--ghost ab-btn--sm',
						onClick: function () {
							var next = {};
							GROUPS.forEach( function ( g ) { next[ g.key ] = false; } );
							saveGroupState( next );
							window.location.reload();
						},
					}, 'Collapse all' )
				)
			),

			// Grouped sections
			GROUPS.map( function ( group ) {
				const groupModules = modules.filter( function ( m ) { return m.group === group.key; } );
				if ( ! groupModules.length ) { return null; }
				return el( GroupSection, {
					key:             group.key,
					group:           group,
					modules:         groupModules,
					maintenanceMode: maintenanceMode,
					onToggle:        handleToggle,
					busySlug:        busySlug,
					onGroupBulk:     handleGroupBulk,
					defaultOpen:     isGroupOpen( group.key ),
				} );
			} )
		);
	}

	// React 18 (WP 6.2+) prefers createRoot. wp.element.render was removed in
	// WP 6.6 / React 18. Fall back to it for older WP installs that pre-date
	// createRoot exposure.
	const appElement = el( SetupModulesApp, admbudSetupData );
	if ( typeof wp.element.createRoot === 'function' ) {
		wp.element.createRoot( mountPoint ).render( appElement );
	} else if ( typeof wp.element.render === 'function' ) {
		wp.element.render( appElement, mountPoint );
	}

} )();
