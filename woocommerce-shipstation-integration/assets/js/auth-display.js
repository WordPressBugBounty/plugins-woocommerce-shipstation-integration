/* global wc_shipstation_auth_params */

( function () {
	'use strict';

	const $ = ( selector, scope ) => ( scope || document ).querySelector( selector );

	const AuthDisplay = {
		requestMade: false, // Track if request has been made to avoid unnecessary requests.
		keysAreMissing: false, // True while the "REST API keys missing" view is the active panel.

		init: function () {
			this.bindEvents();
		},

		bindEvents: function () {
			// Delegated clicks.
			document.addEventListener( 'click', function ( event ) {
				const openBtn   = event.target.closest( '.shipstation-view-auth' );
				const closeBtn  = event.target.closest( '.shipstation-modal-close' );
				const backdrop  = event.target.closest( '.shipstation-modal-backdrop' );
				const copyBtn   = event.target.closest( '.shipstation-copy-btn' );
				const toggleBtn = event.target.closest( '.shipstation-toggle-visibility' );
				const genBtn    = event.target.closest( '#shipstation-generate-new-keys' );

				if ( openBtn ) {
					AuthDisplay.showModal( event );
					return;
				}

				if ( closeBtn || backdrop ) {
					AuthDisplay.hideModal( event );
					return;
				}

				if ( copyBtn ) {
					AuthDisplay.copyToClipboard( event, copyBtn );
					return;
				}

				if ( toggleBtn ) {
					AuthDisplay.toggleVisibility( event, toggleBtn );
					return;
				}

				if ( genBtn ) {
					AuthDisplay.generateNewKeys( event );
				}
			} );
		},

		showModal: function ( event ) {
			event.preventDefault();

			const modal = $( '#shipstation-auth-modal' );
			if ( ! modal ) {
				return;
			}

			modal.style.display = 'block';

			// Focus first close button for accessibility.
			const closeBtn = $( '.shipstation-modal-close', modal );
			if ( closeBtn ) {
				closeBtn.focus();
			}

			// Only make request if not already made.
			if ( ! AuthDisplay.requestMade ) {
				const clsOverlay = $( '.shipstation-loading-overlay' );
				if ( clsOverlay ) {
					clsOverlay.style.display = 'flex';
				}
				AuthDisplay.loadAuthData();
			}
		},

		hideModal: function ( event ) {
			// Allow only if click is on backdrop or the explicit close button.
			const target = event.target;
			if (
				! target.classList.contains( 'shipstation-modal-close' ) &&
				! target.classList.contains( 'shipstation-modal-backdrop' )
			) {
				return;
			}

			const modal = $( '#shipstation-auth-modal' );
			if ( modal ) {
				modal.style.display = 'none';
			}
		},

		loadAuthData: function () {
			const body = new FormData();
			body.append( 'action', 'shipstation_get_auth_data' );
			body.append( 'nonce', wc_shipstation_auth_params.nonce );

			fetch( wc_shipstation_auth_params.ajax_url, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( response ) {
					if ( response && response.success ) {
						// First-time merchant (no option, no row): auto-trigger
						// generation so the modal shows plaintext on first open
						// without making the user click "Generate". Mark the
						// request as already made BEFORE chaining so a slow AJAX
						// + close/reopen cannot fire a second concurrent
						// generate POST that the server-side lock would reject
						// with a confusing "another request is generating"
						// message.
						if ( response.data && response.data.first_time ) {
							AuthDisplay.requestMade = true;
							AuthDisplay.requestNewKeys();
							return;
						}
						AuthDisplay.populateModal( response.data );
					} else {
						AuthDisplay.requestMade = false;
						AuthDisplay.showError( ( response && response.data && response.data.message ) || wc_shipstation_auth_params.error_text );
					}
				} )
				.catch( function () {
					AuthDisplay.requestMade = false;
					AuthDisplay.showError( wc_shipstation_auth_params.error_text );
				} );
		},

		populateModal: function ( data ) {
			// Hide any loading overlays (both id and class variants, mirroring original).
			const clsOverlay = $( '.shipstation-loading-overlay' );
			if ( clsOverlay ) {
				clsOverlay.style.display = 'none';
			}

			// Clear any stale error banner from a previous failed attempt so a
			// successful (re)load never renders credentials beneath a
			// contradictory error notice. showError() inserts the overlay only
			// once, so without this it would persist across an error → retry.
			const errorOverlay = $( '.shipstation-error-overlay' );
			if ( errorOverlay && errorOverlay.parentNode ) {
				errorOverlay.parentNode.removeChild( errorOverlay );
			}

			// Mark that request has been made.
			AuthDisplay.requestMade = true;

			const firstView   = $( '#shipstation-first-view' );
			const afterView   = $( '#shipstation-after-view' );
			const missingView = $( '#shipstation-missing-view' );

			// Three states:
			//   1. Plaintext present → first-time view (just generated).
			//   2. No plaintext, keys exist in DB → after-view (already seen).
			//   3. No plaintext, keys_exist === false → missing-view (deleted externally).
			const hidePanel = function ( el ) {
				if ( el ) {
					el.style.display = 'none';
				}
			};
			const showPanel = function ( el, displayValue ) {
				if ( el ) {
					el.style.display = displayValue || 'block';
				}
			};

			if ( data.consumer_secret ) {
				AuthDisplay.keysAreMissing = false;
				hidePanel( afterView );
				hidePanel( missingView );
				showPanel( firstView );

				const consumerKey    = $( '#shipstation-consumer-key' );
				const consumerSecret = $( '#shipstation-consumer-secret' );
				if ( consumerKey ) {
					consumerKey.value = data.consumer_key || '';
				}
				if ( consumerSecret ) {
					consumerSecret.value = data.consumer_secret || '';
				}
			} else if ( data.keys_existed_but_missing ) {
				AuthDisplay.keysAreMissing = true;
				hidePanel( firstView );
				hidePanel( afterView );

				const titleEl = $( '#shipstation-missing-title' );
				const textEl  = $( '#shipstation-missing-text' );
				if ( titleEl ) {
					titleEl.textContent = wc_shipstation_auth_params.missing_keys_title || '';
				}
				if ( textEl ) {
					textEl.textContent = wc_shipstation_auth_params.missing_keys_text || '';
				}
				showPanel( missingView );
			} else {
				AuthDisplay.keysAreMissing = false;
				hidePanel( firstView );
				hidePanel( missingView );
				showPanel( afterView );

				// Surface the truncated identifier of the row that's currently
				// in use so the merchant can cross-reference it against WC →
				// Settings → Advanced → REST API and confirm ShipStation is
				// authenticating against the expected key.
				const truncatedRow  = $( '#shipstation-truncated-key-row' );
				const truncatedCode = $( '#shipstation-truncated-key' );
				if ( truncatedRow && truncatedCode ) {
					if ( data.truncated_key ) {
						truncatedCode.textContent = data.truncated_key;
						truncatedRow.style.display = '';
					} else {
						truncatedCode.textContent = '';
						truncatedRow.style.display = 'none';
					}
				}
			}

			const authKey = $( '#shipstation-auth-key' );
			const siteUrl = $( '#shipstation-site-url' );

			if ( authKey ) {
				authKey.value = data.auth_key || '';
			}
			if ( siteUrl ) {
				siteUrl.value = data.site_url || '';
			}
		},

		showError: function ( message ) {
			// Hide loading overlays if present.
			const clsOverlay = $( '.shipstation-loading-overlay' );
			if ( clsOverlay ) {
				clsOverlay.style.display = 'none';
			}

			const modal = $( '#shipstation-auth-modal' );
			if ( ! modal ) {
				return;
			}
			const body = $( '.shipstation-modal-body', modal );
			if ( ! body ) {
				return;
			}

			if ( ! $( '.shipstation-error-overlay', body ) ) {
				const wrapper = document.createElement( 'div' );
				wrapper.className = 'shipstation-error-overlay';

				const notice = document.createElement( 'div' );
				notice.className = 'shipstation-error notice notice-error';

				const p = document.createElement( 'p' );
				p.textContent = message;

				notice.appendChild( p );
				wrapper.appendChild( notice );
				body.insertBefore( wrapper, body.firstChild );
			}
		},

		copyToClipboard: function ( event, buttonEl ) {
			event.preventDefault();

			const targetId = buttonEl && buttonEl.dataset ? buttonEl.dataset.target : '';
			if ( ! targetId ) {
				return;
			}

			const input = document.getElementById( targetId );
			if ( ! input ) {
				return;
			}

			AuthDisplay.copyValue( input ).then( function ( copied ) {
				if ( copied ) {
					AuthDisplay.showCopyFeedback( buttonEl );
				}
			} );
		},

		// Copy a field's value to the clipboard, resolving to true only on an
		// actual success. The async Clipboard API is used when available, but it
		// is undefined outside a secure context (plain HTTP on any host other
		// than localhost) and can also reject even when present, so both cases
		// fall back to the legacy execCommand path.
		copyValue: function ( input ) {
			if ( window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText ) {
				return navigator.clipboard.writeText( input.value ).then(
					function () {
						return true;
					},
					function () {
						return AuthDisplay.legacyCopy( input );
					}
				);
			}

			return Promise.resolve( AuthDisplay.legacyCopy( input ) );
		},

		// Legacy clipboard fallback using a temporary selection + execCommand.
		// Password fields are switched to text for the copy so the real value is
		// captured rather than the masked display, then restored afterwards.
		legacyCopy: function ( input ) {
			const originalType = input.getAttribute( 'type' );
			if ( 'password' === originalType ) {
				input.setAttribute( 'type', 'text' );
			}

			input.focus();
			input.select();
			if ( input.setSelectionRange ) {
				input.setSelectionRange( 0, input.value.length );
			}

			let copied = false;
			try {
				copied = document.execCommand( 'copy' );
			} catch ( e ) {
				copied = false;
			}

			if ( 'password' === originalType ) {
				input.setAttribute( 'type', 'password' );
			}

			return copied;
		},

		showCopyFeedback: function ( buttonEl ) {
			const iconEl = $( '.dashicons', buttonEl );
			const originalIcon = iconEl ? iconEl.getAttribute( 'class' ) : '';
			const originalTitle = buttonEl.getAttribute( 'title' ) || '';

			if ( iconEl ) {
				iconEl.setAttribute( 'class', 'dashicons dashicons-yes-alt' );
			}
			buttonEl.setAttribute( 'title', wc_shipstation_auth_params.copy_text );
			buttonEl.classList.add( 'copied' );

			window.setTimeout( function () {
				if ( iconEl && originalIcon ) {
					iconEl.setAttribute( 'class', originalIcon );
				}
				buttonEl.setAttribute( 'title', originalTitle );
				buttonEl.classList.remove( 'copied' );
			}, 2000 );
		},

		toggleVisibility: function ( event, buttonEl ) {
			event.preventDefault();

			const targetId = buttonEl && buttonEl.dataset ? buttonEl.dataset.target : '';
			if ( ! targetId ) {
				return;
			}

			const input = document.getElementById( targetId );
			const icon  = $( '.dashicons', buttonEl );
			if ( ! input ) {
				return;
			}

			if ( input.getAttribute( 'type' ) === 'password' ) {
				input.setAttribute( 'type', 'text' );
				if ( icon ) {
					icon.classList.remove( 'dashicons-visibility' );
					icon.classList.add( 'dashicons-hidden' );
				}
				buttonEl.setAttribute( 'title', wc_shipstation_auth_params.hide_text );
			} else {
				input.setAttribute( 'type', 'password' );
				if ( icon ) {
					icon.classList.remove( 'dashicons-hidden' );
					icon.classList.add( 'dashicons-visibility' );
				}
				buttonEl.setAttribute( 'title', wc_shipstation_auth_params.show_text );
			}
		},

		generateNewKeys: function ( event ) {
			// Skip the "this will disable your old keys" confirmation when the
			// merchant has just been told their keys are missing — there are no
			// old keys to disable in that state, so the prompt is misleading.
			// A successful generate flips the missing-keys flag back to false
			// via populateModal(), so a second click within the same modal
			// session correctly re-prompts.
			//
			// Failed-generate path: if the AJAX errors (network/lock/DB),
			// keysAreMissing intentionally STAYS true and a retry click also
			// skips the confirm. That's correct — the merchant still has zero
			// valid keys, so prompting "this will disable your old keys" would
			// remain misleading. The flag only resets on a successful generate.
			if ( ! AuthDisplay.keysAreMissing && ! window.confirm( wc_shipstation_auth_params.confirm_text ) ) {
				return;
			}

			event.preventDefault();
			AuthDisplay.requestNewKeys();
		},

		// Issues the generate-new-keys AJAX request without any confirmation
		// prompt. Used by the explicit button (after the user confirms) and by
		// the first-time auto-generate path.
		requestNewKeys: function () {
			const clsOverlay = $( '.shipstation-loading-overlay' );
			if ( clsOverlay ) {
				clsOverlay.style.display = 'flex';
			}

			const body = new FormData();
			body.append( 'action', 'shipstation_generate_new_keys' );
			body.append( 'nonce', wc_shipstation_auth_params.nonce );

			fetch( wc_shipstation_auth_params.ajax_url, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( response ) {
					if ( response && response.success ) {
						AuthDisplay.populateModal( response.data );
					} else {
						// Reset so a reopen re-fetches state. The first-time
						// auto-generate path sets requestMade = true before
						// chaining here; if this generate fails, leaving it true
						// would make showModal() skip the reload and strand the
						// merchant on the error overlay until a full page refresh.
						AuthDisplay.requestMade = false;
						AuthDisplay.showError( ( response && response.data && response.data.message ) || wc_shipstation_auth_params.error_text );
					}
				} )
				.catch( function () {
					AuthDisplay.requestMade = false;
					AuthDisplay.showError( wc_shipstation_auth_params.error_text );
				} );
		},
	};

	// DOM ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			AuthDisplay.init();
		} );
	} else {
		AuthDisplay.init();
	}
} )();
