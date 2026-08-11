/**
 * eXeLearning external-embed shim (runs INSIDE the opaque-origin content iframe).
 *
 * In secure mode the .elpx HTML runs in a sandboxed, opaque-origin iframe. The
 * sandbox origin flag propagates to any nested iframe, so cross-origin players
 * (YouTube, Vimeo, ...) lose their own origin and render blank. This shim, injected
 * by the content proxy only in secure mode, replaces each cross-origin (https) or
 * .pdf <iframe> with a same-size placeholder and reports its geometry + URL to the
 * parent window, which renders the real player inline on top (see exe-embed-relay.js).
 *
 * There is no host list here: the shim promotes any cross-origin https (or .pdf)
 * iframe as a candidate and the parent relay is the authoritative gate (open vs strict
 * mode, DEC-0061). postMessage targetOrigin is '*' because the opaque origin has no
 * stable value; the parent authenticates messages by event.source instead.
 *
 * MIRROR of the canonical eXeLearning embedder source in eXe core
 * (public/app/common/exe_embed_bridge/exe_embed_shim.js). Keep the promote()/report()
 * logic identical to core; only the export wrapper differs (this one is an auto-running
 * IIFE). scripts/check-embed-sync.mjs in eXe core flags drift.
 * Part of the eXeLearning embedder family (Omeka S module).
 *
 * Copyright (C) 2026 eXeLearning Team
 *
 * Dual-licensed so this ONE file can ship inside eXeLearning (AGPL-3.0-or-later)
 * and inside the GPL-3.0-or-later host plugins (mod_exelearning) without either
 * project relicensing it. GPLv3 s13 and AGPLv3 s13 already permit COMBINING the
 * two, but combining never relicenses a file: only the copyright holder can offer
 * it under both, which is what this grant does. Keep this notice in every mirror.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later OR GPL-3.0-or-later
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

	var counter = 0;
	var scheduled = false;
	var lastReported = '';

	// A URL whose path ends in .pdf is a document embed; PDFs also fail to render
	// under the opaque sandbox, so they are promoted to the parent too.
	function isPdfUrl( url ) {
		try {
			return /\.pdf$/i.test( new URL( url, window.location.href ).pathname );
		} catch ( e ) {
			return false;
		}
	}

	// Whether a src resolves to an https URL on a host other than this document's own
	// (served) host -- i.e. a cross-origin external embed. The opaque document is still
	// served from the platform, so window.location.hostname is the platform host and the
	// comparison is reliable. The parent relay re-validates authoritatively (DEC-0061);
	// this is only a candidate filter so same-origin content iframes are left untouched.
	function isCrossOriginHttps( src ) {
		try {
			var u = new URL( src, window.location.href );
			// Strip a single trailing dot so the host in its FQDN-root form ('host.')
			// counts as same-host and is not reported as a candidate.
			var host = u.hostname.toLowerCase().replace( /\.$/, '' );
			var here = window.location.hostname.toLowerCase().replace( /\.$/, '' );
			return 'https:' === u.protocol && host !== here;
		} catch ( e ) {
			return false;
		}
	}

	// Whether an iframe src should be promoted to the parent: any cross-origin https
	// embed or a .pdf (both render blank under the opaque sandbox). No host list -- the
	// parent relay decides what actually renders (open vs strict mode).
	function isPromotable( src ) {
		return isCrossOriginHttps( src ) || isPdfUrl( src );
	}

	// Recognise a known video provider from an embed src and extract its object id, so the
	// shim can report {provider, objectId} instead of the author URL (DEC-0067 id-only
	// channel). The parent rebuilds the canonical URL from a fixed template; this avoids
	// passing the author's URL across the boundary for recognised providers. Returns null
	// for unknown hosts or unexpected paths (the caller then falls back to URL mode). The
	// id shape is intentionally permissive here; the parent re-checks it against a strict
	// regex before templating it.
	function extractProvider( src ) {
		var u;
		try {
			u = new URL( src, window.location.href );
		} catch ( e ) {
			return null;
		}
		if ( 'https:' !== u.protocol ) {
			return null;
		}
		var host = u.hostname.toLowerCase().replace( /\.$/, '' );
		var m;
		if ( 'youtu.be' === host ) {
			m = u.pathname.match( /^\/([A-Za-z0-9_-]{6,})$/ );
			return m ? { provider: 'youtube', objectId: m[ 1 ] } : null;
		}
		if ( host.indexOf( 'youtube' ) !== -1 ) {
			m = u.pathname.match( /^\/embed\/([A-Za-z0-9_-]{6,})$/ );
			return m ? { provider: 'youtube', objectId: m[ 1 ] } : null;
		}
		if ( host.indexOf( 'vimeo' ) !== -1 ) {
			m = u.pathname.match( /^\/video\/([0-9]+)$/ );
			return m ? { provider: 'vimeo', objectId: m[ 1 ] } : null;
		}
		if ( host.indexOf( 'dailymotion' ) !== -1 ) {
			m = u.pathname.match( /^\/embed\/video\/([A-Za-z0-9]{5,})$/ );
			return m ? { provider: 'dailymotion', objectId: m[ 1 ] } : null;
		}
		if ( 'mediateca.educa.madrid.org' === host ) {
			m = u.pathname.match( /^\/video\/([A-Za-z0-9]{8,})(?:\/fs)?$/ );
			return m ? { provider: 'mediateca-madrid', objectId: m[ 1 ] } : null;
		}
		return null;
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
			// Report an ABSOLUTE url: the shim runs inside the content, so resolve the
			// (possibly relative) src against the content location. The parent relay
			// cannot — it would resolve a relative url against the host page instead.
			var absoluteUrl = src;
			try {
				absoluteUrl = new URL( src, window.location.href ).href;
			} catch ( e ) {
				absoluteUrl = src;
			}
			placeholder.setAttribute( 'data-exe-embed-url', absoluteUrl );
			// For recognised providers also stamp {provider, objectId} so the parent can
			// rebuild the canonical URL from a fixed template (DEC-0067 id-only channel)
			// instead of trusting the author URL. Unknown hosts keep URL-only mode.
			var provider = extractProvider( absoluteUrl );
			if ( provider ) {
				placeholder.setAttribute( 'data-exe-embed-provider', provider.provider );
				placeholder.setAttribute( 'data-exe-embed-object-id', provider.objectId );
			}
			placeholder.className = frame.className;
			placeholder.style.display = 'block';
			placeholder.style.maxWidth = '100%';
			placeholder.style.width = cssSize( frame.getAttribute( 'width' ), ( rect.width || 0 ) + 'px' );
			placeholder.style.height = cssSize( frame.getAttribute( 'height' ), ( rect.height || 0 ) + 'px' );
			placeholder.style.background = '#000';
			frame.parentNode.replaceChild( placeholder, frame );
		}
	}

	// force=true always posts (initial run, load, and parent 'request' pings -- the
	// parent may have just started listening or lost its state); observer-driven
	// reports skip when the geometry did not actually change, so an attribute-noisy
	// page (carousel animations, aria flips) cannot spam the parent with identical syncs.
	function report( force ) {
		var embeds = [];
		var nodes = document.querySelectorAll( '[data-exe-embed-id]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			var node = nodes[ i ];
			var rect = node.getBoundingClientRect();
			var rec = {
				id: node.getAttribute( 'data-exe-embed-id' ),
				url: node.getAttribute( 'data-exe-embed-url' ),
				x: rect.left,
				y: rect.top,
				w: rect.width,
				h: rect.height
			};
			var provider = node.getAttribute( 'data-exe-embed-provider' );
			var objectId = node.getAttribute( 'data-exe-embed-object-id' );
			if ( provider && objectId ) {
				rec.provider = provider;
				rec.objectId = objectId;
			}
			embeds.push( rec );
		}
		var serialized = JSON.stringify( embeds );
		if ( ! force && serialized === lastReported ) {
			return;
		}
		lastReported = serialized;
		window.parent.postMessage( { type: 'exe-embed', action: 'sync', embeds: embeds }, '*' );
	}

	function schedule() {
		if ( scheduled ) {
			return;
		}
		scheduled = true;
		window.requestAnimationFrame( function () {
			scheduled = false;
			report( false );
		} );
	}

	// Re-announcement schedule (ms after the previous attempt). The parent relay is
	// loaded lazily by its page, so it may start listening after this document has
	// already run. Announcing more than once closes that race without ever
	// promoting on our own authority.
	var ANNOUNCE_DELAYS = [ 250, 750, 1500, 3000 ];
	var activated = false;

	function announce( index ) {
		if ( activated ) {
			return;
		}
		window.parent.postMessage( { type: 'exe-embed', action: 'hello' }, '*' );
		if ( index < ANNOUNCE_DELAYS.length ) {
			window.setTimeout( function () {
				announce( index + 1 );
			}, ANNOUNCE_DELAYS[ index ] );
		}
	}

	function observe() {
		if ( window.MutationObserver ) {
			// Observe attributes too, not just childList: layout-affecting UI (the
			// exported page's nav toggle, accordions) usually flips a class/style on an
			// existing node, which reflows the placeholders without adding or removing
			// any element. The filter keeps the observer to the reflow-causing attributes.
			var observer = new MutationObserver( function () {
				promote();
				schedule();
			} );
			observer.observe( document.documentElement, {
				childList: true,
				subtree: true,
				attributes: true,
				attributeFilter: [ 'class', 'style', 'hidden', 'open' ]
			} );
		}
		window.addEventListener( 'scroll', schedule, true );
		window.addEventListener( 'resize', schedule );
		// A class-toggled layout change usually ANIMATES (CSS transition on the nav
		// drawer): the mutation fires at the start, so re-measure again when the
		// transition/animation lands to report the settled geometry.
		window.addEventListener( 'transitionend', schedule, true );
		window.addEventListener( 'animationend', schedule, true );
		if ( window.ResizeObserver ) {
			// Catches content-box changes that fire no window resize (the drawer
			// pushing the content column, images loading late and growing the page).
			var resizeObserver = new ResizeObserver( schedule );
			resizeObserver.observe( document.documentElement );
			if ( document.body ) {
				resizeObserver.observe( document.body );
			}
		}
		window.addEventListener( 'load', function () {
			report( true );
		} );
	}

	// Promote for the first time. Only ever reached from an addressed 'welcome'.
	function activate() {
		if ( activated ) {
			return;
		}
		activated = true;
		observe();
		promote();
		report( true );
	}

	/*
	 * The shim NEVER promotes an embed on its own authority: it announces itself and
	 * waits for the relay to answer. Without that answer the document is left exactly
	 * as authored, because an unanswered placeholder is a permanent black box —
	 * strictly worse than an unprotected embed — and this file travels inside content
	 * that is routinely opened where no relay exists (file://, a third-party LMS, an
	 * ePub reader). Note file:// is itself an opaque origin, so "opaque" alone can
	 * never be the activation signal.
	 *
	 * 'welcome' is ADDRESSED (the relay resolved this exact window before replying)
	 * and is the only unlock. 'request' is the relay's geometry ping, BROADCAST to
	 * every content frame without resolving any of them, so it may never unlock; while
	 * dormant it only prompts another hello, recovering a relay that started late.
	 */
	function init() {
		window.addEventListener( 'message', function ( event ) {
			if ( event.source !== window.parent ) {
				return;
			}
			var data = event.data;
			if ( ! data || 'exe-embed' !== data.type ) {
				return;
			}
			if ( 'welcome' === data.action ) {
				if ( activated ) {
					promote();
					report( true );
				} else {
					activate();
				}
				return;
			}
			if ( 'request' === data.action ) {
				if ( activated ) {
					promote();
					report( true );
				} else {
					announce( 0 );
				}
			}
		} );
		announce( 0 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
