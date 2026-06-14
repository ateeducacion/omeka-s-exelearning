/**
 * eXeLearning external-embed relay (runs on the HOST page, a trusted context).
 *
 * Companion to exe-embed-shim.js. Listens for geometry reports posted by the
 * opaque content iframe(s), validates each embed URL, and renders the real
 * player as an inline overlay positioned exactly over the placeholder inside the
 * content iframe.
 *
 * Trust model (DEC-0061): the promoted player is rendered cross-origin and
 * SANDBOXED, so the same-origin policy isolates it from this host page (it cannot
 * read the DOM, cookies or session). Two modes:
 *  - 'open' (default): promote any iframe whose src is https AND cross-origin to
 *    the host (rejecting same-origin, sub/superdomains of the host, IP/loopback/
 *    local hosts and userinfo). No host list. The host is irrelevant to escape;
 *    the residual is phishing/tracking, bounded to the content's own box.
 *  - 'strict': only a maintained host allowlist with per-provider canonical-URL
 *    reconstruction, for high-security deployments.
 * "Any https .pdf" is always allowed (same-origin only for this package's own files).
 *
 * Messages are authenticated by event.source (the opaque origin has no usable
 * event.origin); the host page is same-origin, so window.location.origin is the
 * host origin and the cross-origin check correctly rejects the proxied content.
 * In Omeka the package is served by a controller (ContentController, /content/...)
 * on the Omeka host, so the proxied same-origin content is correctly rejected.
 *
 * Config: window.ExeEmbedRelayConfig = { mode: 'open'|'strict', whitelist: [...] }.
 *
 * MIRROR of the canonical eXeLearning embedder source in mod_exelearning
 * (js/exe_embed_relay.js). Keep the validate()/makePlayer()/sync() logic identical
 * across the three embedders (mod, wp, omeka); only the export wrapper differs (this
 * one is an auto-running IIFE reading window.ExeEmbedRelayConfig).
 * tools/check-embed-sync.mjs in mod_exelearning flags drift. Part of the eXeLearning
 * embedder family (Omeka S module).
 */
( function () {
	'use strict';

	/**
	 * Build a host lookup map from a whitelist array (lowercased). Used by 'strict' mode.
	 *
	 * @param {string[]} list The whitelist of hostnames.
	 * @return {Object} Map of lowercase host => true.
	 */
	function buildWhitelist( list ) {
		var map = {};
		( list || [] ).forEach( function ( host ) {
			map[ String( host ).toLowerCase() ] = true;
		} );
		return map;
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
	 * Long hex token shared by the content URL and its assets (null when there is
	 * none, e.g. content URLs that use numeric ids).
	 *
	 * @param {string} src The content iframe src.
	 * @return {?string} The hash, or null if none is found.
	 */
	function packageId( src ) {
		var match = String( src ).match( /[a-f0-9]{12,}/i );
		return match ? match[ 0 ] : null;
	}

	/**
	 * Whether a same-origin URL is one of this package's own extracted files: under
	 * the content's own directory, or carrying the package hash as a path segment.
	 *
	 * @param {URL} url           The candidate URL (already same-origin).
	 * @param {string} contentSrc The content iframe src.
	 * @return {boolean} True when the URL belongs to this package.
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
	 * Whether a host is an IP literal (v4/v6) or a loopback/local name. Such hosts are
	 * cross-origin to the host yet target the machine/internal network, so they are
	 * rejected even though SOP would isolate them.
	 *
	 * @param {string} host Lowercased URL.hostname.
	 * @return {boolean} True when the host is an IP or local name.
	 */
	function isIpOrLocalHost( host ) {
		if ( ! host ) {
			return true;
		}
		if ( 'localhost' === host || /\.localhost$/.test( host ) || /\.local$/.test( host ) ) {
			return true;
		}
		if ( '[' === host.charAt( 0 ) || host.indexOf( ':' ) !== -1 ) {
			return true; // IPv6 (bracketed).
		}
		if ( /^\d{1,3}(\.\d{1,3}){3}$/.test( host ) ) {
			return true; // Any IPv4 literal.
		}
		return false;
	}

	/**
	 * Whether a host equals, is a subdomain of, or is a superdomain of the host page's
	 * host (dotted boundary so 'evil-host.example' does not match 'host.example'). Such
	 * hosts may share the host page's cookies, so they are rejected.
	 *
	 * @param {string} host    The candidate host.
	 * @param {string} lmsHost The host page's host.
	 * @return {boolean} True when the host is related to the host page.
	 */
	function isRelatedToLms( host, lmsHost ) {
		if ( ! lmsHost ) {
			return false;
		}
		return host === lmsHost || host.endsWith( '.' + lmsHost ) || lmsHost.endsWith( '.' + host );
	}

	/**
	 * The structural invariant: an https URL cross-origin to the host page and not
	 * pointing at a sub/superdomain, an IP/loopback/local host, or carrying userinfo.
	 * This is the only attacker-influenced gate in 'open' mode, and it is what makes
	 * the sandboxed player's allow-same-origin safe (the embed keeps ITS OWN origin,
	 * isolated by SOP).
	 *
	 * @param {URL} url The candidate URL.
	 * @return {boolean} True when the URL is a safe cross-origin https embed.
	 */
	function isCrossOriginHttps( url ) {
		if ( 'https:' !== url.protocol ) {
			return false;
		}
		if ( url.username || url.password ) {
			return false;
		}
		if ( url.origin === window.location.origin ) {
			return false;
		}
		var host = url.hostname.toLowerCase();
		if ( isIpOrLocalHost( host ) ) {
			return false;
		}
		var lmshost = ( window.location && window.location.hostname ) ? window.location.hostname.toLowerCase() : '';
		if ( isRelatedToLms( host, lmshost ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Validate an embed URL. Returns { url, kind ('video'|'pdf'), sameorigin? } or null.
	 *
	 * @param {string} raw        The reported (absolute) embed URL.
	 * @param {string} contentSrc The src of the content iframe that reported it.
	 * @param {Object} opts       { strict: boolean, whitelist: Object }.
	 * @return {?Object} The validated result, or null.
	 */
	function validate( raw, contentSrc, opts ) {
		opts = opts || {};
		var url;
		try {
			// Parse as an ABSOLUTE URL (the shim always reports absolute). No base:
			// a relative/scheme-relative value would otherwise inherit the host origin
			// and pass as same-origin -- here it throws and is rejected instead.
			url = new URL( raw );
		} catch ( e ) {
			return null;
		}
		if ( url.username || url.password ) {
			return null; // Reject userinfo, e.g. https://evil.com@youtube.com/.
		}
		var host = url.hostname.toLowerCase();

		// PDFs: any cross-origin https .pdf, or a same-origin file that belongs to this
		// package (served as application/pdf + nosniff, never executable HTML).
		if ( /\.pdf$/i.test( url.pathname ) ) {
			if ( url.origin === window.location.origin ) {
				return isSameOriginPackageFile( url, contentSrc ) ? { url: url.href, kind: 'pdf', sameorigin: true } : null;
			}
			return isCrossOriginHttps( url ) ? { url: url.href, kind: 'pdf' } : null;
		}

		// Strict mode: maintained host allowlist + per-provider canonical reconstruction.
		if ( opts.strict ) {
			var whitelist = opts.whitelist || {};
			if ( whitelist[ host ] && 'https:' === url.protocol ) {
				var m;
				if ( host.indexOf( 'youtube' ) !== -1 ) {
					m = url.pathname.match( /^\/embed\/([A-Za-z0-9_-]{6,})$/ );
					return m ? { url: 'https://www.youtube-nocookie.com/embed/' + m[ 1 ], kind: 'video' } : null;
				}
				if ( host.indexOf( 'vimeo' ) !== -1 ) {
					m = url.pathname.match( /^\/video\/([0-9]+)$/ );
					return m ? { url: 'https://player.vimeo.com/video/' + m[ 1 ], kind: 'video' } : null;
				}
				if ( host.indexOf( 'dailymotion' ) !== -1 ) {
					m = url.pathname.match( /^\/embed\/video\/([A-Za-z0-9]{5,})$/ );
					return m ? { url: 'https://www.dailymotion.com/embed/video/' + m[ 1 ], kind: 'video' } : null;
				}
				if ( 'mediateca.educa.madrid.org' === host ) {
					m = url.pathname.match( /^\/video\/([A-Za-z0-9]{8,})(?:\/fs)?$/ );
					return m ? { url: 'https://mediateca.educa.madrid.org/video/' + m[ 1 ] + '/fs', kind: 'video' } : null;
				}
			}
			return null;
		}

		// Open mode (default): any cross-origin https iframe is a video embed.
		return isCrossOriginHttps( url ) ? { url: url.href, kind: 'video' } : null;
	}

	/**
	 * Create a SANDBOXED player iframe for a validated embed. The video player gets
	 * allow-same-origin so the cross-origin provider keeps its own origin and renders,
	 * while NO allow-top-navigation/allow-modals stops a hostile embed from redirecting
	 * the host tab or spamming dialogs. The PDF player omits allow-scripts (so any PDF JS
	 * cannot run) but keeps allow-same-origin so the browser viewer renders.
	 *
	 * @param {Object} result { url, kind } from validate().
	 * @return {HTMLIFrameElement} The configured player iframe.
	 */
	function makePlayer( result ) {
		var frame = document.createElement( 'iframe' );
		frame.style.cssText = 'position:absolute;border:0;pointer-events:auto;';
		// Mark as a player so it is never mistaken for a content source (message auth).
		frame.setAttribute( 'data-exe-embed-player', '1' );
		if ( 'video' === result.kind ) {
			frame.setAttribute( 'sandbox', 'allow-scripts allow-same-origin allow-popups allow-forms allow-presentation' );
			frame.setAttribute( 'allow', 'autoplay; encrypted-media; fullscreen; picture-in-picture; clipboard-write' );
			frame.setAttribute( 'allowfullscreen', '' );
			frame.setAttribute( 'referrerpolicy', 'strict-origin-when-cross-origin' );
		} else {
			// The browser's built-in PDF viewer does NOT run inside a sandboxed iframe
			// (it renders the broken-document icon), so the PDF player is left unsandboxed
			// -- unchanged from before DEC-0061, where PDFs were already "any https .pdf".
			// A cross-origin PDF is isolated by SOP; the same-origin path is restricted to
			// this package's own files; the load guard below still removes a PDF that
			// redirects to the host origin. Residual (documented): a server that serves
			// HTML at a .pdf path could run scripts here -- pre-existing and low.
			frame.setAttribute( 'allow', 'fullscreen' );
			frame.setAttribute( 'referrerpolicy', 'no-referrer' );
		}
		frame.src = result.url;
		// Tag with the URL it renders so sync() can detect when a reused embed id (the
		// shim restarts its counter per page) now points at a different URL.
		frame.setAttribute( 'data-exe-embed-src', result.url );
		return frame;
	}

	/**
	 * Create a relay instance.
	 *
	 * @param {Object} config { mode: 'open'|'strict', whitelist: string[] }.
	 * @return {Object} The relay instance.
	 */
	function createRelay( config ) {
		config = config || {};
		var strict = 'strict' === config.mode;
		var whitelist = buildWhitelist( config.whitelist );
		var overlays = [];

		function findOverlay( iframe ) {
			for ( var i = 0; i < overlays.length; i++ ) {
				if ( overlays[ i ].iframe === iframe ) {
					return overlays[ i ];
				}
			}
			return null;
		}

		// Resolve the CONTENT iframe a message came from. Promoted players are excluded
		// (data-exe-embed-player): a sandboxed player with allow-same-origin could
		// otherwise postMessage a forged 'sync' and impersonate a content source.
		function frameForSource( source ) {
			var frames = document.getElementsByTagName( 'iframe' );
			for ( var i = 0; i < frames.length; i++ ) {
				if ( frames[ i ].getAttribute( 'data-exe-embed-player' ) ) {
					continue;
				}
				if ( frames[ i ].contentWindow === source ) {
					return frames[ i ];
				}
			}
			return null;
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

		// D1: if a promoted embed lands SAME-ORIGIN to the host (e.g. a cross-origin URL
		// that 30x-redirects to this origin), with allow-same-origin it would become
		// scriptable against this page -> remove it. A genuine cross-origin player throws
		// on contentWindow.document (expected, kept). Not armed for same-origin package
		// PDFs (intentionally same-origin, served as application/pdf).
		function armSameOriginGuard( entry, id, player ) {
			player.addEventListener( 'load', function () {
				try {
					if ( player.contentWindow && player.contentWindow.document ) {
						if ( player.parentNode ) {
							player.parentNode.removeChild( player );
						}
						if ( entry.players[ id ] === player ) {
							delete entry.players[ id ];
						}
					}
				} catch ( e ) { /* cross-origin: expected, keep the player */ }
			} );
		}

		function sync( entry, embeds, contentSrc ) {
			positionOverlay( entry );
			var seen = {};
			embeds.forEach( function ( embed ) {
				if ( ! embed || typeof embed.id !== 'string' ) {
					return;
				}
				if ( ! isFinite( embed.x ) || ! isFinite( embed.y ) || ! isFinite( embed.w ) || ! isFinite( embed.h ) ) {
					return;
				}
				var result = validate( embed.url, contentSrc, { strict: strict, whitelist: whitelist } );
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
					if ( ! result.sameorigin ) {
						armSameOriginGuard( entry, embed.id, player );
					}
				}
				// Defence in depth against clickjacking: the overlay is clamped to the
				// content iframe's box and clips with overflow:hidden, so a player can
				// never cover host UI outside the iframe. Cap the player size to the
				// overlay too (the content reports geometry, the parent owns rendering).
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

		function onMessage( event ) {
			var data = event.data;
			if ( ! data || 'exe-embed' !== data.type || 'sync' !== data.action || ! Array.isArray( data.embeds ) ) {
				return;
			}
			var iframe = frameForSource( event.source );
			if ( ! iframe ) {
				return;
			}
			sync( overlayFor( iframe ), data.embeds, iframe.src );
		}

		// Ask every content iframe to report (covers the case where the shim fired its
		// first report before this relay attached its listener). Promoted players are
		// excluded so a sandboxed player is never pinged as a content source.
		function pingAll() {
			var frames = document.getElementsByTagName( 'iframe' );
			for ( var i = 0; i < frames.length; i++ ) {
				if ( frames[ i ].getAttribute( 'data-exe-embed-player' ) ) {
					continue;
				}
				try {
					frames[ i ].contentWindow.postMessage( { type: 'exe-embed', action: 'request' }, '*' );
				} catch ( e ) {
					// Cross-origin player iframes reject this; harmless.
				}
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

		return {
			onMessage: onMessage,
			sync: sync,
			validate: function ( raw, contentSrc ) {
				return validate( raw, contentSrc, { strict: strict, whitelist: whitelist } );
			},
			init: function () {
				window.addEventListener( 'message', onMessage );
				window.addEventListener( 'resize', scheduleReflow );
				window.addEventListener( 'scroll', scheduleReflow, true );
				window.addEventListener( 'load', pingAll );
				pingAll();
				window.setTimeout( pingAll, 500 );
				return this;
			}
		};
	}

	// Browser bootstrap: expose the factory and helpers, then auto-run a relay from the
	// host-injected config (window.ExeEmbedRelayConfig is set before this script loads).
	window.exeEmbedRelay = {
		buildWhitelist: buildWhitelist,
		contentDir: contentDir,
		packageId: packageId,
		isSameOriginPackageFile: isSameOriginPackageFile,
		isIpOrLocalHost: isIpOrLocalHost,
		isRelatedToLms: isRelatedToLms,
		isCrossOriginHttps: isCrossOriginHttps,
		validate: validate,
		makePlayer: makePlayer,
		createRelay: createRelay
	};
	createRelay( window.ExeEmbedRelayConfig || {} ).init();
} )();
