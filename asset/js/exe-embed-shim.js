/**
 * eXeLearning external-embed shim (runs INSIDE the opaque-origin content iframe).
 *
 * In secure mode the .elpx HTML runs in a sandboxed, opaque-origin iframe. The
 * sandbox origin flag propagates to any nested iframe, so cross-origin players
 * (YouTube, Vimeo, ...) lose their own origin and render blank. This shim, injected
 * by the content proxy only in secure mode, replaces each WHITELISTED external
 * <iframe> with a same-size placeholder and reports its geometry + URL to the parent
 * window, which renders the real player inline on top (see exe-embed-relay.js).
 * Non-whitelisted iframes are left untouched.
 *
 * The whitelist is provided by the host as window.__exeEmbedWhitelist (array of
 * lowercase hostnames). postMessage targetOrigin is '*' because the opaque origin
 * has no stable value; the parent authenticates messages by event.source instead.
 *
 * Part of the eXeLearning embedder family (Omeka S module).
 */
( function () {
	'use strict';

	if ( window.parent === window ) {
		return; // Not embedded; nothing to promote.
	}

	// Only promote in the secure (opaque-origin) sandbox. In an opaque origin
	// document.cookie throws and window.origin is "null"; in legacy (same-origin)
	// external players already render inline, so promotion would be both wrong and
	// useless (the parent relay is not loaded there). This lets the shim be baked
	// unconditionally (e.g. mod_exelearning) and stay dormant outside secure mode.
	function isOpaqueOrigin() {
		try {
			void document.cookie;
			return window.origin === 'null';
		} catch ( e ) {
			return true;
		}
	}
	if ( ! isOpaqueOrigin() ) {
		return;
	}

	var whitelist = Array.isArray( window.__exeEmbedWhitelist ) ? window.__exeEmbedWhitelist : [];
	var counter = 0;
	var scheduled = false;

	function hostOf( url ) {
		try {
			return new URL( url, window.location.href ).hostname.toLowerCase();
		} catch ( e ) {
			return '';
		}
	}

	function isWhitelisted( host ) {
		for ( var i = 0; i < whitelist.length; i++ ) {
			if ( whitelist[ i ] === host ) {
				return true;
			}
		}
		return false;
	}

	// A URL whose path ends in .pdf is a document embed; PDFs also fail to render
	// under the opaque sandbox, so they are promoted to the parent too.
	function isPdfUrl( url ) {
		try {
			return /\.pdf$/i.test( new URL( url, window.location.href ).pathname );
		} catch ( e ) {
			return false;
		}
	}

	function isPromotable( src ) {
		return isWhitelisted( hostOf( src ) ) || isPdfUrl( src );
	}

	function cssSize( value, fallback ) {
		if ( ! value ) {
			return fallback;
		}
		return /^[0-9]+$/.test( String( value ) ) ? value + 'px' : String( value );
	}

	function promote() {
		var frames = document.querySelectorAll( 'iframe[src]' );
		for ( var i = 0; i < frames.length; i++ ) {
			var frame = frames[ i ];
			if ( frame.getAttribute( 'data-exe-embed-id' ) ) {
				continue;
			}
			var src = frame.getAttribute( 'src' );
			if ( ! isPromotable( src ) ) {
				continue;
			}
			var rect = frame.getBoundingClientRect();
			var placeholder = document.createElement( 'div' );
			counter += 1;
			placeholder.setAttribute( 'data-exe-embed-id', 'exe-embed-' + counter );
			placeholder.setAttribute( 'data-exe-embed-url', src );
			placeholder.className = frame.className;
			placeholder.style.display = 'block';
			placeholder.style.maxWidth = '100%';
			placeholder.style.width = cssSize( frame.getAttribute( 'width' ), ( rect.width || 0 ) + 'px' );
			placeholder.style.height = cssSize( frame.getAttribute( 'height' ), ( rect.height || 0 ) + 'px' );
			placeholder.style.background = '#000';
			frame.parentNode.replaceChild( placeholder, frame );
		}
	}

	function report() {
		var embeds = [];
		var nodes = document.querySelectorAll( '[data-exe-embed-id]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			var node = nodes[ i ];
			var rect = node.getBoundingClientRect();
			embeds.push( {
				id: node.getAttribute( 'data-exe-embed-id' ),
				url: node.getAttribute( 'data-exe-embed-url' ),
				x: rect.left,
				y: rect.top,
				w: rect.width,
				h: rect.height
			} );
		}
		window.parent.postMessage( { type: 'exe-embed', action: 'sync', embeds: embeds }, '*' );
	}

	function schedule() {
		if ( scheduled ) {
			return;
		}
		scheduled = true;
		window.requestAnimationFrame( function () {
			scheduled = false;
			report();
		} );
	}

	function init() {
		promote();
		report();
		if ( window.MutationObserver ) {
			var observer = new MutationObserver( function () {
				promote();
				schedule();
			} );
			observer.observe( document.documentElement, { childList: true, subtree: true } );
		}
		window.addEventListener( 'scroll', schedule, true );
		window.addEventListener( 'resize', schedule );
		window.addEventListener( 'load', report );
		// The parent may ask for a fresh report (closes the load-order race).
		window.addEventListener( 'message', function ( event ) {
			if ( event.source !== window.parent ) {
				return;
			}
			var data = event.data;
			if ( data && 'exe-embed' === data.type && 'request' === data.action ) {
				promote();
				report();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
