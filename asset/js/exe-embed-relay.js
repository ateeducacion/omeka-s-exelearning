/**
 * eXeLearning external-embed relay (runs on the HOST page, a trusted context).
 *
 * Companion to exe-embed-shim.js. Listens for geometry reports posted by the
 * opaque content iframe(s), validates each embed URL against the host whitelist,
 * rebuilds the canonical player URL, and renders the real player as an inline
 * overlay positioned exactly over the placeholder inside the content iframe.
 *
 * Security model: the player iframe is cross-origin (youtube/vimeo) and cannot
 * read or script the host page; the whitelist + strict URL validation stop the
 * untrusted content from making the host load an arbitrary URL. Messages are
 * authenticated by event.source (the opaque origin has no usable event.origin).
 *
 * Config: window.ExeEmbedRelayConfig = { whitelist: [ hostnames... ] }.
 *
 * MIRROR of the canonical eXeLearning embedder source in mod_exelearning
 * (js/exe_embed_relay.js). Keep the validate()/sync() logic identical across the three
 * embedders (mod, wp, omeka); only the export wrapper differs (this one is an
 * auto-running IIFE reading window.ExeEmbedRelayConfig). tools/check-embed-sync.mjs in
 * mod_exelearning flags drift. Part of the eXeLearning embedder family (Omeka S module).
 */
( function () {
	'use strict';

	var config = window.ExeEmbedRelayConfig || {};
	var whitelist = {};
	( config.whitelist || [] ).forEach( function ( host ) {
		whitelist[ String( host ).toLowerCase() ] = true;
	} );

	// One entry per content iframe: { iframe, el (overlay), players: { id: iframe } }.
	var overlays = [];

	function findOverlay( iframe ) {
		for ( var i = 0; i < overlays.length; i++ ) {
			if ( overlays[ i ].iframe === iframe ) {
				return overlays[ i ];
			}
		}
		return null;
	}

	function frameForSource( source ) {
		var frames = document.getElementsByTagName( 'iframe' );
		for ( var i = 0; i < frames.length; i++ ) {
			if ( frames[ i ].contentWindow === source ) {
				return frames[ i ];
			}
		}
		return null;
	}

	/**
	 * Validate an embed URL. Returns { url, kind } or null.
	 *
	 *  - 'video': host on the whitelist; the canonical player URL is rebuilt, so
	 *    the host never loads an attacker-controlled video URL verbatim.
	 *  - 'pdf': any cross-origin https URL whose path ends in .pdf, OR a SAME-ORIGIN
	 *    URL that lives under the content's own proxy directory (a package file the
	 *    proxy serves as application/pdf + nosniff, so it can never execute as the
	 *    host page). Same-origin URLs outside that directory are rejected, so the
	 *    content cannot point the host at an executable same-origin route.
	 *
	 * @param {string} raw        The reported embed URL.
	 * @param {string} contentSrc The src of the content iframe that reported it.
	 */
	function validate( raw, contentSrc ) {
		var url;
		try {
			url = new URL( raw, window.location.href );
		} catch ( e ) {
			return null;
		}
		if ( String( raw ).indexOf( '@' ) !== -1 ) {
			return null; // Reject userinfo, e.g. evil.com@youtube.com.
		}
		var host = url.hostname.toLowerCase();

		if ( whitelist[ host ] && 'https:' === url.protocol ) {
			var match;
			if ( host.indexOf( 'youtube' ) !== -1 ) {
				match = url.pathname.match( /^\/embed\/([A-Za-z0-9_-]{6,})$/ );
				return match ? { url: 'https://www.youtube-nocookie.com/embed/' + match[ 1 ], kind: 'video' } : null;
			}
			if ( host.indexOf( 'vimeo' ) !== -1 ) {
				match = url.pathname.match( /^\/video\/([0-9]+)$/ );
				return match ? { url: 'https://player.vimeo.com/video/' + match[ 1 ], kind: 'video' } : null;
			}
			if ( host.indexOf( 'dailymotion' ) !== -1 ) {
				match = url.pathname.match( /^\/embed\/video\/([A-Za-z0-9]{5,})$/ );
				return match ? { url: 'https://www.dailymotion.com/embed/video/' + match[ 1 ], kind: 'video' } : null;
			}
			if ( 'mediateca.educa.madrid.org' === host ) {
				// EducaMadrid / Mediateca de Madrid embed: /video/{id}/fs (watch URL is /video/{id}).
				match = url.pathname.match( /^\/video\/([A-Za-z0-9]{8,})(?:\/fs)?$/ );
				return match ? { url: 'https://mediateca.educa.madrid.org/video/' + match[ 1 ] + '/fs', kind: 'video' } : null;
			}
		}

		if ( /\.pdf$/i.test( url.pathname ) ) {
			if ( url.origin === window.location.origin ) {
				// Same-origin: only allow this package's own extracted files, so
				// author-supplied absolute URLs (e.g. /wp-admin/x.pdf) are rejected.
				// Package files are served by extension as application/pdf, never as
				// executable HTML. A file qualifies if it sits under the content's own
				// directory (Omeka, mod_exelearning serve assets there) OR carries the
				// package hash as a path segment (WordPress serves assets from a sibling
				// uploads tree that shares the hash).
				return isSameOriginPackageFile( url, contentSrc ) ? { url: url.href, kind: 'pdf' } : null;
			}
			// Cross-origin: https only.
			return 'https:' === url.protocol ? { url: url.href, kind: 'pdf' } : null;
		}

		return null;
	}

	/**
	 * Whether a same-origin URL is one of this package's own extracted files.
	 *
	 * @param {URL} url           The candidate URL (already same-origin).
	 * @param {string} contentSrc The content iframe src.
	 * @return {boolean}
	 */
	function isSameOriginPackageFile( url, contentSrc ) {
		var dir = contentDir( contentSrc );
		if ( dir && 0 === url.href.indexOf( dir ) ) {
			return true;
		}
		var id = packageId( contentSrc );
		return !! ( id && url.pathname.indexOf( '/' + id + '/' ) !== -1 );
	}

	/**
	 * Directory portion of the content iframe src (everything up to the last '/').
	 *
	 * @param {string} src The content iframe src.
	 * @return {string} The base directory URL, or '' if it cannot be parsed.
	 */
	function contentDir( src ) {
		try {
			return new URL( src, window.location.href ).href.replace( /[^/]*([?#].*)?$/, '' );
		} catch ( e ) {
			return '';
		}
	}

	/**
	 * Extract the package hash from the content iframe src (a long hex token shared
	 * by the content URL and its extracted assets). Returns null when there is none
	 * (e.g. mod_exelearning, whose content URL uses numeric ids).
	 *
	 * @param {string} src The content iframe src.
	 * @return {?string} The hash, or null if none is found.
	 */
	function packageId( src ) {
		var match = String( src ).match( /[a-f0-9]{12,}/i );
		return match ? match[ 0 ] : null;
	}

	function overlayFor( iframe ) {
		var entry = findOverlay( iframe );
		if ( entry ) {
			return entry;
		}
		var el = document.createElement( 'div' );
		el.className = 'exe-embed-overlay';
		el.style.cssText = 'position:absolute;overflow:hidden;pointer-events:none;z-index:2147483646;';
		document.body.appendChild( el );
		entry = { iframe: iframe, el: el, players: {} };
		overlays.push( entry );
		return entry;
	}

	function positionOverlay( entry ) {
		var rect = entry.iframe.getBoundingClientRect();
		var scrollX = window.pageXOffset || document.documentElement.scrollLeft || 0;
		var scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
		entry.el.style.left = ( rect.left + scrollX ) + 'px';
		entry.el.style.top = ( rect.top + scrollY ) + 'px';
		entry.el.style.width = rect.width + 'px';
		entry.el.style.height = rect.height + 'px';
	}

	function makePlayer( result ) {
		var frame = document.createElement( 'iframe' );
		frame.style.cssText = 'position:absolute;border:0;pointer-events:auto;';
		if ( 'video' === result.kind ) {
			frame.setAttribute( 'allow', 'autoplay; encrypted-media; fullscreen; picture-in-picture; clipboard-write' );
			frame.setAttribute( 'allowfullscreen', '' );
			frame.setAttribute( 'referrerpolicy', 'strict-origin-when-cross-origin' );
		} else {
			// PDF / document: no autoplay/media grants; do not leak the referrer.
			frame.setAttribute( 'allow', 'fullscreen' );
			frame.setAttribute( 'referrerpolicy', 'no-referrer' );
		}
		frame.src = result.url;
		// Tag the player with the URL it renders so sync() can detect when a reused
		// embed id (the in-iframe shim restarts its counter per page) now points at a
		// different URL and must be replaced rather than just repositioned.
		frame.setAttribute( 'data-exe-embed-src', result.url );
		return frame;
	}

	function sync( entry, embeds ) {
		positionOverlay( entry );
		var seen = {};
		embeds.forEach( function ( embed ) {
			if ( ! embed || typeof embed.id !== 'string' ) {
				return;
			}
			if ( ! isFinite( embed.x ) || ! isFinite( embed.y ) || ! isFinite( embed.w ) || ! isFinite( embed.h ) ) {
				return;
			}
			var result = validate( embed.url, entry.iframe.src );
			if ( ! result ) {
				return;
			}
			seen[ embed.id ] = true;
			var player = entry.players[ embed.id ];
			// After the content navigates, the shim reuses ids (exe-embed-1, ...) for
			// the new page's embeds. If this id now renders a different URL, drop the
			// stale player so the previous page's video does not linger here.
			if ( player && player.getAttribute( 'data-exe-embed-src' ) !== result.url ) {
				player.parentNode.removeChild( player );
				delete entry.players[ embed.id ];
				player = null;
			}
			if ( ! player ) {
				player = makePlayer( result );
				entry.el.appendChild( player );
				entry.players[ embed.id ] = player;
			}
			// Defence in depth against clickjacking: the overlay is clamped to the
			// content iframe's box and clips with overflow:hidden, so a player can never
			// cover host UI outside the iframe. Cap the player size to the overlay too
			// (the content reports geometry, the parent owns rendering).
			var rect = entry.iframe.getBoundingClientRect();
			player.style.left = embed.x + 'px';
			player.style.top = embed.y + 'px';
			player.style.width = Math.min( embed.w, rect.width ) + 'px';
			player.style.height = Math.min( embed.h, rect.height ) + 'px';
		} );
		Object.keys( entry.players ).forEach( function ( id ) {
			if ( ! seen[ id ] ) {
				entry.players[ id ].parentNode.removeChild( entry.players[ id ] );
				delete entry.players[ id ];
			}
		} );
	}

	window.addEventListener( 'message', function ( event ) {
		var data = event.data;
		if ( ! data || 'exe-embed' !== data.type || 'sync' !== data.action || ! Array.isArray( data.embeds ) ) {
			return;
		}
		var iframe = frameForSource( event.source );
		if ( ! iframe ) {
			return; // Not from a known content iframe on this page.
		}
		sync( overlayFor( iframe ), data.embeds );
	} );

	// Ask every content iframe to report (covers the case where the shim fired
	// its first report before this relay attached its listener).
	function pingAll() {
		var frames = document.getElementsByTagName( 'iframe' );
		for ( var i = 0; i < frames.length; i++ ) {
			try {
				frames[ i ].contentWindow.postMessage( { type: 'exe-embed', action: 'request' }, '*' );
			} catch ( e ) {}
		}
	}

	var scheduled = false;
	function scheduleReflow() {
		if ( scheduled ) {
			return;
		}
		scheduled = true;
		window.requestAnimationFrame( function () {
			scheduled = false;
			for ( var i = 0; i < overlays.length; i++ ) {
				positionOverlay( overlays[ i ] );
			}
		} );
	}

	window.addEventListener( 'resize', scheduleReflow );
	window.addEventListener( 'scroll', scheduleReflow, true );
	window.addEventListener( 'load', pingAll );
	pingAll();
	window.setTimeout( pingAll, 500 );
} )();
