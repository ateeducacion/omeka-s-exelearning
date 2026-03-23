/**
 * eXeLearning editor installer with adaptive strategy.
 *
 * Detects Playground (PHP-WASM) and picks the fastest path:
 *
 *   Playground → tell PHP to download via proxy URL
 *             → fallback: browser downloads via proxy + uploads blob
 *   Normal    → server-side PHP download from GitHub
 *             → fallback: browser downloads via proxy + uploads blob
 *
 * Expects a global `exelearningInstaller` object.
 */
(function () {
    'use strict';

    var cfg = window.exelearningInstaller;
    if (!cfg) return;

    var btn = document.getElementById('exelearning-install-btn');
    var progressDiv = document.getElementById('exelearning-install-progress');
    var messageSpan = document.getElementById('exelearning-install-message');
    var progressBar = document.getElementById('exelearning-install-bar');
    var resultDiv = document.getElementById('exelearning-install-result');
    var s = cfg.strings;

    if (!btn) return;

    var KNOWN_PROXY = 'https://zip-proxy.erseco.workers.dev/';

    /**
     * Resolve the install API URL.
     * In the Playground, the SW scopes URLs under /playground/SCOPE/RUNTIME/.
     * PHP's basePath() doesn't include this prefix, so we derive it from
     * the current page URL to ensure the SW routes the request correctly.
     */
    function getInstallUrl() {
        if (!isPlayground()) return cfg.installUrl;
        var path = window.location.pathname;
        var adminIdx = path.indexOf('/admin');
        if (adminIdx === -1) return cfg.installUrl;
        return path.substring(0, adminIdx) + '/admin/exelearning/install-editor';
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function showError(message) {
        progressDiv.style.display = 'none';
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<p style="color: #dc3232;">' + escapeHtml(message) + '</p>';
        btn.disabled = false;
        btn.textContent = s.tryAgain;
    }

    function showSuccess(message) {
        setProgress(100, '');
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<p style="color: #46b450; font-weight: bold;">'
            + escapeHtml(message) + '</p>';
        setTimeout(function () { location.reload(); }, 2000);
    }

    function setProgress(pct, message) {
        progressBar.style.width = Math.min(pct, 100) + '%';
        if (message) messageSpan.textContent = message;
    }

    function proxiedUrl(url, proxyBase) {
        if (!proxyBase) return null;
        try {
            var p = new URL(proxyBase);
            p.searchParams.set('url', url);
            return p.toString();
        } catch (e) { return null; }
    }

    /** Parse response as JSON safely; extract message from HTML errors. */
    function safeJsonResponse(resp) {
        var ct = resp.headers.get('Content-Type') || '';
        if (ct.indexOf('json') !== -1) {
            return resp.json();
        }
        return resp.text().then(function (text) {
            var msg = text;
            // Try <pre>, <p>, then <body> content
            var match = text.match(/<pre[^>]*>([\s\S]*?)<\/pre>/i)
                || text.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
            if (match) msg = match[1].replace(/<[^>]+>/g, '').trim();
            if (msg.length > 300) msg = msg.substring(0, 300) + '...';
            return {
                success: false,
                message: msg || ('Server error: HTTP ' + resp.status)
            };
        });
    }

    function handleResult(data) {
        if (data && data.success) {
            showSuccess(data.message);
            return true;
        }
        return false;
    }

    // ── Environment detection ───────────────────────────────────────────

    function isPlayground() {
        if (window.location.pathname.indexOf('/omeka-s-playground') !== -1) return true;
        if (window.location.search.indexOf('scope=') !== -1) return true;
        var host = window.location.hostname;
        if (host.indexOf('github.io') !== -1 && window.location.pathname.indexOf('playground') !== -1) {
            return true;
        }
        if (typeof window.__playgroundConfig !== 'undefined') return true;
        if (/\/playground\/[^/]+\/[^/]+\//.test(window.location.pathname)) return true;
        return false;
    }

    function getProxyUrl() {
        try {
            if (window.__playgroundConfig) {
                return window.__playgroundConfig.addonProxyUrl
                    || window.__playgroundConfig.proxyUrl
                    || KNOWN_PROXY;
            }
        } catch (e) { /* ignore */ }
        return KNOWN_PROXY;
    }

    // ── Version discovery ───────────────────────────────────────────────

    function discoverVersion() {
        return fetch(cfg.githubApiUrl, {
            headers: { 'Accept': 'application/vnd.github.v3+json' }
        })
        .then(function (resp) {
            if (!resp.ok) throw new Error('GitHub API error');
            return resp.json();
        })
        .then(function (data) {
            var tag = data.tag_name || '';
            if (!tag) throw new Error('No tag_name');
            return tag.replace(/^v/, '');
        })
        .catch(function () {
            return fetch(cfg.jsdelivrApiUrl)
                .then(function (resp) {
                    if (!resp.ok) throw new Error('jsDelivr error');
                    return resp.json();
                })
                .then(function (data) {
                    var ver = data.version || '';
                    if (!ver) throw new Error('No version');
                    return ver.replace(/^v/, '');
                });
        });
    }

    // ── Download with progress ──────────────────────────────────────────

    function buildReleaseUrl(version) {
        return 'https://github.com/exelearning/exelearning/releases/download/v'
            + version + '/' + cfg.assetPrefix + version + '.zip';
    }

    function buildProxyDownloadUrl(version) {
        return proxiedUrl(buildReleaseUrl(version), getProxyUrl());
    }

    function downloadWithProgress(url) {
        return fetch(url).then(function (response) {
            if (!response.ok) {
                throw new Error('Download failed: HTTP ' + response.status);
            }

            var contentLength = response.headers.get('Content-Length');
            var total = contentLength ? parseInt(contentLength, 10) : 0;

            if (!total || !response.body || !response.body.getReader) {
                setProgress(30, s.downloading);
                return response.blob();
            }

            var reader = response.body.getReader();
            var received = 0;
            var chunks = [];

            function pump() {
                return reader.read().then(function (result) {
                    if (result.done) return;
                    chunks.push(result.value);
                    received += result.value.length;
                    var pct = 5 + Math.round((received / total) * 75);
                    var mb = (received / (1024 * 1024)).toFixed(1);
                    var totalMb = (total / (1024 * 1024)).toFixed(1);
                    setProgress(pct, s.downloadingProgress
                        .replace('{downloaded}', mb)
                        .replace('{total}', totalMb));
                    return pump();
                });
            }

            return pump().then(function () {
                return new Blob(chunks);
            });
        });
    }

    // ── Install methods ─────────────────────────────────────────────────

    /** Tell PHP to download from a URL (no browser download needed). */
    function installViaUrl(downloadUrl, version) {
        var formData = new FormData();
        formData.append('csrf', cfg.csrfToken);
        formData.append('download_url', downloadUrl);
        formData.append('version', version);

        return fetch(getInstallUrl(), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        }).then(safeJsonResponse);
    }

    /**
     * Upload blob to PHP via chunked upload.
     * Splits the ZIP into small chunks (256KB) to avoid BroadcastChannel
     * message size limits in PHP-WASM. Each chunk is a separate request.
     */
    function uploadAndInstall(blob, version) {
        setProgress(82, s.installing);

        var CHUNK_SIZE = 256 * 1024; // 256KB per chunk
        var totalChunks = Math.ceil(blob.size / CHUNK_SIZE);
        var uploadId = randomId();
        var baseUrl = getInstallUrl();
        var csrfParam = '&csrf=' + encodeURIComponent(cfg.csrfToken);

        function sendChunk(index) {
            var start = index * CHUNK_SIZE;
            var end = Math.min(start + CHUNK_SIZE, blob.size);
            var chunk = blob.slice(start, end);

            var url = baseUrl + '?action=chunk&upload_id=' + uploadId + csrfParam;

            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/octet-stream' },
                body: chunk,
                credentials: 'same-origin'
            })
            .then(safeJsonResponse)
            .then(function (data) {
                if (!data.success) throw new Error(data.message || s.error);

                var pct = 82 + Math.round(((index + 1) / totalChunks) * 13);
                setProgress(pct, s.installing + ' (' + (index + 1) + '/' + totalChunks + ')');

                if (index + 1 < totalChunks) {
                    return sendChunk(index + 1);
                }
            });
        }

        // Send all chunks sequentially, then finalize
        return sendChunk(0).then(function () {
            setProgress(96, s.installing);

            var url = baseUrl + '?action=finalize&upload_id=' + uploadId
                + '&version=' + encodeURIComponent(version) + csrfParam;

            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin'
            }).then(safeJsonResponse);
        });
    }

    function randomId() {
        var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        var id = '';
        for (var i = 0; i < 16; i++) {
            id += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return id;
    }

    /** Ask PHP to download from GitHub directly (no proxy needed). */
    function serverSideInstall() {
        var progress = 0;
        var timer = setInterval(function () {
            if (progress < 90) {
                progress += (90 - progress) * 0.05;
                setProgress(progress, s.downloading);
            }
        }, 500);

        var formData = new FormData();
        formData.append('csrf', cfg.csrfToken);

        return fetch(getInstallUrl(), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function (resp) {
            clearInterval(timer);
            return safeJsonResponse(resp);
        })
        .catch(function (err) {
            clearInterval(timer);
            throw err;
        });
    }

    // ── Orchestration ───────────────────────────────────────────────────

    /**
     * Playground: browser downloads via CORS proxy (with progress bar),
     * then uploads the blob to PHP for extraction.
     */
    function playgroundInstall() {
        setProgress(2, s.discovering);

        return discoverVersion()
            .then(function (version) {
                var proxyUrl = buildProxyDownloadUrl(version);
                return browserDownloadAndUpload(proxyUrl, version);
            })
            .then(function (data) {
                if (!handleResult(data)) {
                    showError(data.message || s.error);
                }
            })
            .catch(function (err) {
                showError(err.message || s.networkError);
            });
    }

    /** Download in browser via proxy, then upload blob to PHP. */
    function browserDownloadAndUpload(proxyUrl, version) {
        setProgress(5, s.downloading);
        return downloadWithProgress(proxyUrl)
            .then(function (blob) {
                return uploadAndInstall(blob, version);
            });
    }

    /**
     * Normal: server-side download, then fallback to proxy.
     */
    function normalInstall() {
        setProgress(2, s.downloading);

        return serverSideInstall()
            .then(function (data) {
                if (handleResult(data)) return;

                // Server-side failed — try proxy path
                return proxyFallback();
            })
            .catch(function () {
                return proxyFallback();
            });
    }

    function proxyFallback() {
        setProgress(2, s.discovering);

        return discoverVersion()
            .then(function (version) {
                var proxyUrl = buildProxyDownloadUrl(version);
                return browserDownloadAndUpload(proxyUrl, version);
            })
            .then(function (data) {
                if (!handleResult(data)) {
                    showError(data.message || s.error);
                }
            })
            .catch(function (err) {
                showError(err.message || s.networkError);
            });
    }

    // ── Entry point ─────────────────────────────────────────────────────

    btn.addEventListener('click', function () {
        btn.disabled = true;
        btn.textContent = s.pleaseWait;
        resultDiv.style.display = 'none';
        resultDiv.innerHTML = '';
        progressDiv.style.display = 'block';

        if (isPlayground()) {
            playgroundInstall();
        } else {
            normalInstall();
        }
    });
})();
