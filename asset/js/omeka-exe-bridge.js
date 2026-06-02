/**
 * Omeka-S eXeLearning Bridge
 *
 * Connects the static eXeLearning editor with Omeka-S for:
 * - Loading ELP files from the media library
 * - Saving edited files back to Omeka-S
 * - Communication between iframe and parent window
 */
(function() {
    'use strict';

    var config = window.__OMEKA_EXE_CONFIG__;
    if (!config) {
        console.error('[Omeka-EXE Bridge] Configuration not found');
        return;
    }

    // Origins we trust for parent<->iframe messaging. The editor iframe is
    // embedded same-origin, so the parent origin equals our own; fall back to
    // it when the embedding config is absent. Used to (a) target every
    // postMessage to the real parent instead of '*' (so exported package bytes
    // never leak to a hostile framing origin) and (b) reject inbound messages
    // that do not come from the parent window at a trusted origin.
    var EMBED = window.__EXE_EMBEDDING_CONFIG__ || {};
    var TRUSTED_ORIGINS = (Array.isArray(EMBED.trustedOrigins) && EMBED.trustedOrigins.length)
        ? EMBED.trustedOrigins
        : [EMBED.parentOrigin || window.location.origin];
    var PARENT_ORIGIN = TRUSTED_ORIGINS[0] || window.location.origin;

    function isTrustedMessage(event) {
        return event.source === window.parent
            && TRUSTED_ORIGINS.indexOf(event.origin) !== -1;
    }

    console.log('[Omeka-EXE Bridge] Initializing with config:', config);

    var monitoredYdoc = null;
    var changeNotified = false;
    var documentLoadedNotified = false;

    /**
     * Post a protocol message to the parent window.
     */
    function postProtocolMessage(message) {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage(message, PARENT_ORIGIN);
        }
    }

    /**
     * Monitor Yjs document for changes and notify parent.
     */
    function monitorDocumentChanges() {
        try {
            var app = window.eXeLearning?.app;
            var yjsBridge = app?.project?._yjsBridge;
            var dm = yjsBridge?.documentManager;
            var ydoc = dm?.ydoc;
            if (ydoc && typeof ydoc.on === 'function' && ydoc !== monitoredYdoc) {
                monitoredYdoc = ydoc;
                changeNotified = false;
                ydoc.on('update', function() {
                    if (!changeNotified) {
                        changeNotified = true;
                        postProtocolMessage({ type: 'DOCUMENT_CHANGED' });
                    }
                });
            }
        } catch (error) {
            console.warn('[Omeka-EXE Bridge] Change monitor failed:', error);
        }
    }

    /**
     * Poll for documentManager and notify parent when document is loaded.
     */
    function notifyWhenDocumentLoaded() {
        var timeout = 30000;
        var start = Date.now();
        var check = function() {
            var app = window.eXeLearning?.app;
            var manager = app?.project?._yjsBridge?.documentManager;
            if (manager && !documentLoadedNotified) {
                documentLoadedNotified = true;
                postProtocolMessage({ type: 'DOCUMENT_LOADED' });
                monitorDocumentChanges();
                return;
            }
            if (Date.now() - start < timeout) {
                setTimeout(check, 150);
            }
        };
        check();
    }

    /**
     * Wait for the eXeLearning app to be ready (legacy fallback)
     */
    function waitForAppLegacy(maxAttempts) {
        maxAttempts = maxAttempts || 100;
        return new Promise(function(resolve, reject) {
            var attempts = 0;
            var check = function() {
                attempts++;
                if (window.eXeLearning && window.eXeLearning.app) {
                    resolve(window.eXeLearning.app);
                } else if (attempts < maxAttempts) {
                    setTimeout(check, 100);
                } else {
                    reject(new Error('App did not initialize'));
                }
            };
            check();
        });
    }

    /**
     * Wait for the Yjs project bridge to be ready.
     */
    function waitForBridge(maxAttempts) {
        maxAttempts = maxAttempts || 150;
        return new Promise(function(resolve, reject) {
            var attempts = 0;
            var check = function() {
                attempts++;
                var bridge = window.eXeLearning?.app?.project?._yjsBridge
                    || window.YjsModules?.getBridge?.();
                if (bridge) {
                    console.log('[Omeka-EXE Bridge] Bridge found after', attempts, 'attempts');
                    resolve(bridge);
                } else if (attempts < maxAttempts) {
                    setTimeout(check, 200);
                } else {
                    reject(new Error('Project bridge did not initialize'));
                }
            };
            check();
        });
    }

    /**
     * Show or update the loading screen
     */
    function updateLoadScreen(message, show) {
        if (show === undefined) show = true;
        var loadScreen = document.getElementById('load-screen-main');
        var loadMessage = loadScreen?.querySelector('.loading-message, p');

        if (loadScreen) {
            if (show) {
                loadScreen.classList.remove('hide');
            } else {
                loadScreen.classList.add('hide');
            }
        }

        if (loadMessage && message) {
            loadMessage.textContent = message;
        }
    }

    /**
     * Import ELP file from Omeka-S
     */
    async function importElpFromOmeka() {
        var elpUrl = config.elpUrl;
        if (!elpUrl) {
            console.log('[Omeka-EXE Bridge] No ELP URL provided, starting with empty project');
            return;
        }

        console.log('[Omeka-EXE Bridge] Starting import from:', elpUrl);

        try {
            updateLoadScreen(config.i18n?.loading || 'Loading project...');

            // Wait for the Yjs bridge to be initialized
            updateLoadScreen('Waiting for editor...');
            var bridge = await waitForBridge();

            // Fetch the ELP file
            // Use cache: 'no-cache' to force revalidation with the server.
            // Without this, the browser may serve a stale cached version after
            // the file is updated on the server (heuristic caching).
            updateLoadScreen('Downloading file...');
            var response = await fetch(elpUrl, { cache: 'no-cache' });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status + ': ' + response.statusText);
            }

            // Convert to File object
            var blob = await response.blob();
            console.log('[Omeka-EXE Bridge] File downloaded, size:', blob.size);
            var filename = elpUrl.split('/').pop().split('?')[0] || 'project.elpx';
            var file = new File([blob], filename, { type: 'application/zip' });

            // Import using the project API or bridge directly
            updateLoadScreen('Importing content...');
            var project = window.eXeLearning?.app?.project;
            if (typeof project?.importElpxFile === 'function') {
                console.log('[Omeka-EXE Bridge] Using project.importElpxFile...');
                await project.importElpxFile(file);
            } else if (typeof project?.importFromElpxViaYjs === 'function') {
                console.log('[Omeka-EXE Bridge] Using project.importFromElpxViaYjs...');
                await project.importFromElpxViaYjs(file, { clearExisting: true });
            } else {
                console.log('[Omeka-EXE Bridge] Using bridge.importFromElpx...');
                await bridge.importFromElpx(file, { clearExisting: true });
            }

            console.log('[Omeka-EXE Bridge] ELP imported successfully');
        } catch (error) {
            console.error('[Omeka-EXE Bridge] Import failed:', error);
            updateLoadScreen('Error loading project');
        } finally {
            setTimeout(function() {
                updateLoadScreen('', false);
            }, 500);
        }
    }

    /**
     * Export the loaded project to a specific format using SharedExporters.
     * Posts the resulting bytes back to the parent window for download.
     */
    async function handleExportRequest(message) {
        var requestId = message.requestId;
        var format = String(message.data?.format || message.format || '');
        try {
            if (!format) {
                throw new Error('Missing format');
            }
            var app = window.eXeLearning?.app;
            var yjsBridge = app?.project?._yjsBridge || window.YjsModules?.getBridge?.();
            if (!yjsBridge?.documentManager) {
                throw new Error('Document not ready');
            }
            if (!window.SharedExporters?.quickExport) {
                throw new Error('SharedExporters bundle not loaded');
            }

            var result = await window.SharedExporters.quickExport(
                format,
                yjsBridge.documentManager,
                yjsBridge.assetCache || null,
                yjsBridge.resourceFetcher || null,
                {},
                yjsBridge.assetManager || null
            );
            if (!result?.success || !result?.data) {
                throw new Error(result?.error || 'Export failed');
            }

            var mime = (format === 'epub3' || format === 'epub')
                ? 'application/epub+zip'
                : 'application/zip';
            var blob = new Blob([result.data], { type: mime });
            var bytes = await blob.arrayBuffer();

            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    type: 'OMEKA_EXPORT_FILE',
                    requestId: requestId,
                    format: format,
                    bytes: bytes,
                    filename: result.filename || ('project.' + format),
                    mimeType: mime,
                    size: bytes.byteLength,
                }, PARENT_ORIGIN);
            }
        } catch (error) {
            console.error('[Omeka-EXE Bridge] Export failed:', error);
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    type: 'OMEKA_REQUEST_EXPORT_ERROR',
                    requestId: requestId,
                    error: error.message || 'Export failed',
                }, PARENT_ORIGIN);
            }
        }
    }

    /**
     * Save project to Omeka-S
     */
    async function saveToOmeka() {
        // Notify parent window that save is starting
        if (window.parent !== window) {
            window.parent.postMessage({ type: 'exelearning-save-start' }, PARENT_ORIGIN);
        }

        try {
            console.log('[Omeka-EXE Bridge] Starting save...');

            // Get the project bridge for export
            var project = window.eXeLearning?.app?.project;
            var yjsBridge = project?._yjsBridge
                || window.YjsModules?.getBridge?.()
                || project?.bridge;

            if (!yjsBridge) {
                throw new Error('Project bridge not available');
            }

            // Export using quickExport or legacy createExporter
            var blob;
            if (window.SharedExporters?.quickExport) {
                console.log('[Omeka-EXE Bridge] Using SharedExporters.quickExport...');
                var result = await window.SharedExporters.quickExport(
                    'elpx',
                    yjsBridge.documentManager,
                    null,
                    yjsBridge.resourceFetcher,
                    {},
                    yjsBridge.assetManager
                );
                if (!result.success || !result.data) {
                    throw new Error('Export failed');
                }
                blob = new Blob([result.data], { type: 'application/zip' });
            } else if (window.SharedExporters?.createExporter) {
                console.log('[Omeka-EXE Bridge] Using SharedExporters.createExporter...');
                var exporter = window.SharedExporters.createExporter(
                    'elpx',
                    yjsBridge.documentManager,
                    yjsBridge.assetCache,
                    yjsBridge.resourceFetcher,
                    yjsBridge.assetManager
                );
                var exportResult = await exporter.export();
                if (!exportResult.success || !exportResult.data) {
                    throw new Error('Export failed');
                }
                blob = new Blob([exportResult.data], { type: 'application/zip' });
            } else {
                throw new Error('No exporter available');
            }

            console.log('[Omeka-EXE Bridge] Export complete, size:', blob.size);

            // Upload to Omeka-S.
            // Use raw binary body — this works in both standard PHP and php-wasm
            // (where multipart/FormData file uploads may not populate $_FILES).
            console.log('[Omeka-EXE Bridge] Uploading to:', config.saveEndpoint);

            var saveHeaders = { 'Content-Type': 'application/octet-stream' };
            if (config.csrfToken) {
                saveHeaders['X-CSRF-Token'] = config.csrfToken;
            }

            var saveResponse = await fetch(config.saveEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: saveHeaders,
                body: blob
            });

            var saveResult = await saveResponse.json();

            if (saveResult.success) {
                console.log('[Omeka-EXE Bridge] Save successful');
                showNotification('success', config.i18n?.saved || 'Saved successfully!');

                // Notify parent window
                if (window.parent !== window) {
                    window.parent.postMessage({
                        type: 'exelearning-save-complete',
                        mediaId: config.mediaId,
                        contentPath: saveResult.contentPath
                    }, PARENT_ORIGIN);
                }
            } else {
                throw new Error(saveResult.message || 'Save failed');
            }
        } catch (error) {
            console.error('[Omeka-EXE Bridge] Save failed:', error);
            showNotification('error', (config.i18n?.error || 'Error') + ': ' + error.message);

            // Notify parent window that save failed
            if (window.parent !== window) {
                window.parent.postMessage({ type: 'exelearning-save-error', message: error.message }, PARENT_ORIGIN);
            }
        }
    }

    /**
     * Show notification to user
     */
    function showNotification(type, message) {
        var existing = document.getElementById('omeka-exe-notification');
        if (existing) {
            existing.remove();
        }

        var notification = document.createElement('div');
        notification.id = 'omeka-exe-notification';
        notification.className = 'omeka-exe-notification omeka-exe-notification--' + type;
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(function() {
            notification.classList.add('omeka-exe-notification--fade');
            setTimeout(function() { notification.remove(); }, 300);
        }, 3000);
    }

    /**
     * Initialize the bridge
     */
    async function init() {
        try {
            console.log('[Omeka-EXE Bridge] Starting initialization...');

            // Wait for app initialization using the new ready promise or legacy polling
            if (window.eXeLearning?.ready) {
                await window.eXeLearning.ready;
            } else {
                await waitForAppLegacy();
            }
            console.log('[Omeka-EXE Bridge] App initialized');

            // Import ELP if URL provided
            if (config.elpUrl) {
                await importElpFromOmeka();
            } else {
                console.log('[Omeka-EXE Bridge] No elpUrl in config, skipping import');
            }

            // Notify parent window that bridge is ready
            if (window.parent !== window) {
                window.parent.postMessage({ type: 'exelearning-bridge-ready' }, PARENT_ORIGIN);
            }

            // Listen for save shortcuts (Ctrl+S / Cmd+S)
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    saveToOmeka();
                }
            });

            // Listen for messages from parent window. Reject anything that is
            // not from the parent window at a trusted origin so a hostile
            // framing page (or other frame) cannot drive save/export.
            window.addEventListener('message', function(event) {
                if (!isTrustedMessage(event)) {
                    return;
                }
                if (event.data?.type === 'exelearning-request-save') {
                    saveToOmeka();
                }
                if (event.data?.type === 'OMEKA_REQUEST_EXPORT') {
                    handleExportRequest(event.data);
                }
                // Re-check ydoc after parent messages (may trigger after import)
                setTimeout(function() {
                    monitorDocumentChanges();
                }, 500);
            });

            // Notify parent when document is loaded and start monitoring changes
            notifyWhenDocumentLoaded();

            console.log('[Omeka-EXE Bridge] Initialization complete');
        } catch (error) {
            console.error('[Omeka-EXE Bridge] Initialization failed:', error);
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose for debugging
    window.omekaExeBridge = {
        config: config,
        save: saveToOmeka,
        import: importElpFromOmeka
    };
})();
