/* Tillpress in-plugin support ticket. Sends via admin-ajax only when the admin presses Send. */
( function () {
	'use strict';
	var form = document.querySelector( 'form.tp-support-ticket' );
	if ( ! form ) {
		return;
	}
	var cfg = window[ form.getAttribute( 'data-prefix' ) + 'Support' ] || {};
	var i18n = cfg.i18n || {};
	var status = form.querySelector( '.tp-support-status' );
	var sendBtn = form.querySelector( '.tp-support-send' );
	var MAX_URL_BODY = 1800;

	function val( n ) {
		var el = form.elements[ n ];
		return el ? String( el.value || '' ).trim() : '';
	}
	function checked( n ) {
		var el = form.elements[ n ];
		return !! ( el && el.checked );
	}
	function say( msg, ok ) {
		status.textContent = msg;
		status.className = 'tp-support-status ' + ( ok ? 'is-ok' : 'is-error' );
	}
	function subject() {
		return '[' + cfg.plugin + '] ' + ( val( 'subject' ) || val( 'topic' ) );
	}
	function ticket() {
		var lines = [ 'Plugin: ' + cfg.plugin, 'Topic: ' + val( 'topic' ), 'Name: ' + val( 'name' ), 'Reply to: ' + val( 'email' ), '', val( 'message' ) ];
		if ( checked( 'include_report' ) ) {
			lines.push( '', '--- System report ---', String( cfg.report || '' ) );
		}
		return lines.join( '\n' );
	}
	function basicValid() {
		if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( val( 'email' ) ) ) {
			say( i18n.needEmail, false );
			form.elements.email.focus();
			return false;
		}
		if ( ! val( 'message' ) ) {
			say( i18n.needMessage, false );
			form.elements.message.focus();
			return false;
		}
		return true;
	}
	function fallbackCopy( text ) {
		var ta = document.createElement( 'textarea' );
		var ok = false;
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'fixed';
		ta.style.top = '-1000px';
		document.body.appendChild( ta );
		ta.select();
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}
		document.body.removeChild( ta );
		return ok;
	}
	function copy( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text ).then( function () { return true; }, function () { return fallbackCopy( text ); } );
		}
		return Promise.resolve( fallbackCopy( text ) );
	}

	form.addEventListener( 'submit', function ( ev ) {
		ev.preventDefault();
		if ( ! basicValid() ) {
			return;
		}
		if ( ! checked( 'consent' ) ) {
			say( i18n.needConsent, false );
			form.elements.consent.focus();
			return;
		}
		var data = new FormData();
		data.append( 'action', cfg.action );
		data.append( 'nonce', cfg.nonce );
		[ 'name', 'email', 'topic', 'subject', 'message' ].forEach( function ( n ) { data.append( n, val( n ) ); } );
		data.append( 'include_report', checked( 'include_report' ) ? '1' : '' );
		data.append( 'consent', '1' );
		sendBtn.disabled = true;
		say( i18n.sending, true );
		fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: data } )
			.then( function ( r ) { return r.json().catch( function () { return { success: false, data: { error: 'HTTP ' + r.status } }; } ); } )
			.then( function ( j ) {
				if ( j && j.success && j.data && j.data.ticket ) {
					say( String( i18n.sent ).replace( '%s', j.data.ticket ), true );
					form.elements.message.value = '';
					form.elements.subject.value = '';
				} else {
					var err = ( j && j.data && j.data.error ) ? j.data.error : 'unknown';
					say( i18n.failed + ' ' + err + ' ' + i18n.tryEmail, false );
					sendBtn.disabled = false;
				}
			} )
			.catch( function ( e ) {
				say( i18n.failed + ' ' + ( e && e.message ? e.message : 'network' ) + ' ' + i18n.tryEmail, false );
				sendBtn.disabled = false;
			} );
	} );

	form.querySelector( '.tp-support-mail' ).addEventListener( 'click', function () {
		if ( ! basicValid() ) {
			return;
		}
		var full = ticket();
		var body = full;
		var cut = false;
		while ( encodeURIComponent( body ).length > MAX_URL_BODY ) {
			body = body.slice( 0, Math.floor( body.length * 0.9 ) );
			cut = true;
		}
		if ( cut ) {
			body += '\n\n' + i18n.truncated;
		}
		copy( subject() + '\n\n' + full ).then( function () {
			say( i18n.opened, true );
			window.location.href = 'mailto:' + cfg.to + '?subject=' + encodeURIComponent( subject() ) + '&body=' + encodeURIComponent( body );
		} );
	} );

	form.querySelector( '.tp-support-copy' ).addEventListener( 'click', function () {
		if ( ! basicValid() ) {
			return;
		}
		copy( 'To: ' + cfg.to + '\nSubject: ' + subject() + '\n\n' + ticket() ).then( function ( ok ) {
			say( ok ? i18n.copied : i18n.copyFailed, ok );
		} );
	} );
}() );
