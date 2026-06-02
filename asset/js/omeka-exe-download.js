/**
 * Public download orchestrator for the eXeLearning multi-format split-button.
 *
 *   - `.elpx` items download the original Omeka media URL directly.
 *   - Any other format triggers a client-side export by lazy-loading the
 *     static editor in a hidden iframe served at /exelearning/export and
 *     asking it via postMessage to run SharedExporters.quickExport().
 *
 * The hidden iframe is reused for additional downloads within the same page
 * and is disposed of after a short idle period.
 */
(function() {
    'use strict';

    var iframeEl = null;
    var iframeReady = false;
    var iframeLoadedMedia = null;
    var pendingExports = Object.create(null);
    var nextRequestId = 1;
    var disposeTimer = null;

    function getBasePath() {
        var href = window.location.href;
        var base = '';
        var markers = ['/admin/', '/s/', '/api/'];
        for (var i = 0; i < markers.length; i++) {
            var idx = href.indexOf(markers[i]);
            if (idx !== -1) {
                var url = new URL(href);
                var pathToMarker = url.pathname.substring(0, url.pathname.indexOf(markers[i]));
                return url.origin + pathToMarker;
            }
        }
        return window.location.origin;
    }

    function exportBootstrapUrl(mediaId) {
        return getBasePath() + '/exelearning/export?media_id=' + encodeURIComponent(mediaId);
    }

    function ensureIframe(mediaId) {
        if (iframeEl && iframeLoadedMedia === mediaId) {
            return Promise.resolve();
        }
        disposeIframe();

        return new Promise(function(resolve, reject) {
            iframeEl = document.createElement('iframe');
            iframeEl.setAttribute('aria-hidden', 'true');
            iframeEl.setAttribute('tabindex', '-1');
            iframeEl.setAttribute('title', 'eXeLearning export worker');
            iframeEl.style.cssText = 'position:fixed;left:-9999px;top:-9999px;width:1px;height:1px;border:0;visibility:hidden;';
            iframeEl.src = exportBootstrapUrl(mediaId);
            iframeLoadedMedia = mediaId;
            iframeReady = false;

            var readyHandler = function(event) {
                if (event.source !== iframeEl.contentWindow) return;
                var data = event.data || {};
                if (data.type === 'DOCUMENT_LOADED' || data.type === 'exelearning-bridge-ready') {
                    iframeReady = true;
                    window.removeEventListener('message', readyHandler);
                    resolve();
                }
            };
            window.addEventListener('message', readyHandler);
            iframeEl.addEventListener('error', function() {
                window.removeEventListener('message', readyHandler);
                reject(new Error('iframe failed to load'));
            });
            document.body.appendChild(iframeEl);

            setTimeout(function() {
                if (!iframeReady) {
                    window.removeEventListener('message', readyHandler);
                    reject(new Error('export iframe timeout'));
                }
            }, 60000);
        });
    }

    function disposeIframe() {
        if (iframeEl && iframeEl.parentNode) {
            iframeEl.parentNode.removeChild(iframeEl);
        }
        iframeEl = null;
        iframeReady = false;
        iframeLoadedMedia = null;
        pendingExports = Object.create(null);
    }

    function scheduleDispose() {
        if (disposeTimer) clearTimeout(disposeTimer);
        disposeTimer = setTimeout(disposeIframe, 60000);
    }

    window.addEventListener('message', function(event) {
        if (!iframeEl || event.source !== iframeEl.contentWindow) return;
        var data = event.data || {};
        var requestId = data.requestId;
        if (!requestId || !pendingExports[requestId]) return;
        var pending = pendingExports[requestId];
        delete pendingExports[requestId];

        if (data.type === 'OMEKA_EXPORT_FILE' && data.bytes) {
            pending.resolve({ bytes: data.bytes, filename: data.filename, mimeType: data.mimeType });
        } else if (data.type === 'OMEKA_REQUEST_EXPORT_ERROR' || data.error) {
            pending.reject(new Error(data.error || 'Export failed'));
        }
    });

    function requestExport(format) {
        return new Promise(function(resolve, reject) {
            if (!iframeEl || !iframeReady || !iframeEl.contentWindow) {
                reject(new Error('Editor iframe not ready'));
                return;
            }
            var requestId = 'exe-' + (nextRequestId++);
            pendingExports[requestId] = { resolve: resolve, reject: reject };
            // The export iframe is same-origin; target it explicitly rather
            // than '*' so the request can never reach a cross-origin document.
            iframeEl.contentWindow.postMessage(
                { type: 'OMEKA_REQUEST_EXPORT', requestId: requestId, data: { format: format } },
                window.location.origin
            );
            setTimeout(function() {
                if (pendingExports[requestId]) {
                    delete pendingExports[requestId];
                    reject(new Error('Export timed out'));
                }
            }, 120000);
        });
    }

    function triggerDownload(blob, filename) {
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(function() { URL.revokeObjectURL(url); }, 1000);
    }

    function setBusy(container, busy) {
        if (!container) return;
        if (busy) container.setAttribute('data-busy', '1');
        else container.removeAttribute('data-busy');
    }

    function showStatus(container, message) {
        var existing = container.querySelector('.exelearning-download__status');
        if (!message) {
            if (existing) existing.remove();
            return;
        }
        if (!existing) {
            existing = document.createElement('span');
            existing.className = 'exelearning-download__status';
            container.appendChild(existing);
        }
        existing.textContent = message;
    }

    function closeAllMenus() {
        var open = document.querySelectorAll('.exelearning-download__menu[data-open="1"]');
        open.forEach(function(menu) {
            menu.hidden = true;
            menu.removeAttribute('data-open');
            var toggle = menu.parentNode && menu.parentNode.querySelector('.exelearning-download__toggle');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        });
    }

    function onClick(event) {
        var target = event.target.closest('[data-format]');
        var toggle = event.target.closest('.exelearning-download__toggle');
        var container = event.target.closest('.exelearning-download');

        if (toggle && container) {
            event.preventDefault();
            var menu = container.querySelector('.exelearning-download__menu');
            if (!menu) return;
            var isOpen = menu.getAttribute('data-open') === '1';
            closeAllMenus();
            if (!isOpen) {
                menu.hidden = false;
                menu.setAttribute('data-open', '1');
                toggle.setAttribute('aria-expanded', 'true');
            }
            return;
        }

        if (!target || !container) {
            closeAllMenus();
            return;
        }

        var format = target.getAttribute('data-format');
        var suffix = target.getAttribute('data-suffix') || '';
        var mediaId = parseInt(container.getAttribute('data-media-id'), 10);
        var elpUrl = container.getAttribute('data-elp-url');
        var slug = container.getAttribute('data-slug') || ('media-' + mediaId);
        closeAllMenus();

        if (format === 'elpx') {
            event.preventDefault();
            var link = document.createElement('a');
            link.href = elpUrl;
            link.download = slug + suffix;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            return;
        }

        event.preventDefault();
        if (!mediaId) {
            console.error('[omeka-exe-download] Missing media id');
            return;
        }

        setBusy(container, true);

        ensureIframe(mediaId)
            .then(function() { return requestExport(format); })
            .then(function(result) {
                var mime = result.mimeType || (format === 'epub3' ? 'application/epub+zip' : 'application/zip');
                var blob = new Blob([result.bytes], { type: mime });
                triggerDownload(blob, slug + suffix);
            })
            .catch(function(err) {
                console.error('[omeka-exe-download]', err);
                showStatus(container, (window.OMEKA_EXE_DOWNLOAD_I18N && window.OMEKA_EXE_DOWNLOAD_I18N.failed) || 'Download failed. Please try again.');
                setTimeout(function() { showStatus(container, ''); }, 4000);
            })
            .finally(function() {
                setBusy(container, false);
                if (!container.querySelector('.exelearning-download__status')) {
                    showStatus(container, '');
                }
                scheduleDispose();
            });
    }

    function init() {
        document.addEventListener('click', onClick);
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeAllMenus();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
