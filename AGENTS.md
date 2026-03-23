# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Workflow

**Test and lint (required before marking done):**
```bash
make lint              # PHP_CodeSniffer PSR2 — must pass
make test              # PHPUnit unit tests
make test-coverage     # Tests + 90% coverage minimum (used in CI)
```

**Docker dev environment:**
```bash
make up                # Start → http://localhost:8080 (admin@example.com / PLEASE_CHANGEME)
make shell             # SSH into container
make down              # Stop
```

**Editor build** (requires [Bun](https://bun.sh/), not npm/yarn):
```bash
make build-editor      # Build static editor from exelearning submodule
```

**Packaging:**
```bash
make package VERSION=1.2.3
```

## Code Style

- PSR2 standard enforced by phpcs. Run `make fix` to auto-fix.
- Factories are excluded from coverage requirements.
- Test stubs live in `test/Stubs/` — add new stubs there when Omeka/Laminas collaborators are needed.

## Git Conventions

- Branch names: `feature/*`, `fix/*`, `hotfix/*`
- CI runs on push/PR: untranslated string check, lint, test-coverage (all must pass)

## Architecture Notes

See the Security & Architecture section below for the full system design. Key gotchas:
- All ELPX content is served through `ContentController` (proxy) — never expose `/files/exelearning/` directly.
- API endpoints validate CSRF tokens from session — always include `csrf_key` in API calls.
- The module uses Omeka event hooks (`api.hydrate.post`, `api.create.post`, `api.delete.pre`, `view.show.after`) — check `Module.php` before adding new lifecycle behavior.
- URL building uses `$request->getBasePath()` to support playground prefix environments.

---

# ExeLearning Module for Omeka S - Security & Architecture

This document describes the security considerations and system architecture implemented in the ExeLearning module.

## Architecture Overview

The module enables viewing and editing of eXeLearning (.elpx) files within Omeka S. The system consists of:

```
+------------------+     +-------------------+     +------------------+
|  Admin Interface |     |  Content Proxy    |     |  Editor (iframe) |
|  (media-show)    |---->|  (ContentController)|-->|  (eXeLearning)   |
+------------------+     +-------------------+     +------------------+
         |                        |                        |
         v                        v                        v
+------------------+     +-------------------+     +------------------+
|  Modal Editor    |     |  /files/exelearning/|   |  postMessage API |
|  (fullscreen)    |     |  (extracted files) |   |  (communication) |
+------------------+     +-------------------+     +------------------+
         |                                                 |
         v                                                 v
+------------------+                              +------------------+
|  API Controller  |<-----------------------------|  Bridge JS       |
|  (save/load)     |                              |  (import/export) |
+------------------+                              +------------------+
```

## File Storage

- **Original .elpx files**: Stored in Omeka's standard `/files/original/` directory
- **Extracted content**: Stored in `/files/exelearning/{sha1-hash}/` directories
- **Thumbnails**: Generated and stored as custom thumbnails for media items

## Security Measures

### 1. Iframe Sandboxing

All iframes displaying eXeLearning content use restrictive sandbox attributes:

```html
<iframe
    sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox"
    referrerpolicy="no-referrer"
    ...
></iframe>
```

**Allowed capabilities:**
- `allow-scripts`: Required for interactive content
- `allow-popups`: Some eXeLearning content may need popups
- `allow-popups-to-escape-sandbox`: Popups can function normally

**Blocked capabilities:**
- `allow-same-origin`: Prevents access to parent page cookies/storage
- `allow-forms`: Prevents form submission to external URLs
- `allow-top-navigation`: Prevents navigation of parent page

### 2. Content Security Policy (CSP)

The ContentController adds strict CSP headers for HTML content:

```
Content-Security-Policy:
  default-src 'self';
  script-src 'self' 'unsafe-inline' 'unsafe-eval';
  style-src 'self' 'unsafe-inline';
  img-src 'self' data: blob:;
  media-src 'self' data: blob:;
  font-src 'self' data:;
  frame-src 'self';
  object-src 'none';
  base-uri 'self'
```

This prevents:
- Loading external scripts (XSS mitigation)
- Connecting to external servers
- Embedding external iframes
- Using plugins (Flash, Java, etc.)

### 3. Secure Content Proxy

Direct access to `/files/exelearning/` is blocked. All content is served through a PHP proxy (`ContentController::serveAction`):

**Security validations:**
1. Hash format validation (40 hex characters - SHA1)
2. Path traversal prevention (blocks `..`)
3. File existence verification
4. MIME type detection and Content-Type headers

```php
// Hash validation
if (!preg_match('/^[a-f0-9]{40}$/', $hash)) {
    return $this->notFoundAction();
}

// Path traversal prevention
if (strpos($file, '..') !== false) {
    return $this->notFoundAction();
}
```

### 4. Additional Security Headers

```
X-Frame-Options: SAMEORIGIN
X-Content-Type-Options: nosniff
```

- **X-Frame-Options**: Prevents clickjacking by blocking external framing
- **X-Content-Type-Options**: Prevents MIME-sniffing attacks

### 5. Server Configuration (nginx)

The module requires nginx rules to:

1. **Block direct file access:**
```nginx
location ^~ /files/exelearning/ {
    return 403;
}
```

2. **Route proxy requests to PHP:**
```nginx
location ^~ /exelearning/content/ {
    try_files $uri /index.php$is_args$args;
}
```

### 6. CSRF Protection

API endpoints require a valid CSRF key:

```php
$csrfKey = $data['csrf_key'] ?? $request->getQuery('csrf_key');
$session = Container::getDefaultManager()->getStorage();
if (!$session || $csrfKey !== ($session['Omeka\Csrf'] ?? null)) {
    return new ApiProblemResponse(new ApiProblem(403, 'Invalid CSRF token'));
}
```

### 7. ACL Permissions

Edit functionality requires proper permissions:

```php
$acl = $this->getServiceLocator()->get('Omeka\Acl');
if (!$acl->userIsAllowed('Omeka\Entity\Media', 'update')) {
    return new ApiProblemResponse(new ApiProblem(403, 'Permission denied'));
}
```

## Communication Flow

### Parent-Iframe Communication

Communication uses `postMessage` API with origin validation:

**Editor to Parent:**
```javascript
window.parent.postMessage({
    type: 'exelearning-bridge-ready'
}, window.location.origin);

window.parent.postMessage({
    type: 'exelearning-save-complete',
    success: true
}, window.location.origin);
```

**Parent to Editor:**
```javascript
iframe.contentWindow.postMessage({
    type: 'exelearning-request-save'
}, '*');
```

### Save Flow

1. User clicks "Save to Omeka" button
2. Parent sends `exelearning-request-save` message
3. Bridge exports ELPX from editor
4. Bridge POSTs to `/api/exelearning/save/{id}` with CSRF token
5. Server validates token, permissions, and saves file
6. Bridge sends `exelearning-save-complete` message
7. Parent closes modal and refreshes preview

## File Types and MIME Detection

The module handles various file types within .elpx archives:

| Extension | MIME Type |
|-----------|-----------|
| .html | text/html |
| .css | text/css |
| .js | application/javascript |
| .json | application/json |
| .png | image/png |
| .jpg/.jpeg | image/jpeg |
| .gif | image/gif |
| .svg | image/svg+xml |
| .mp4 | video/mp4 |
| .webm | video/webm |
| .mp3 | audio/mpeg |
| .ogg | audio/ogg |
| .woff/.woff2 | font/woff, font/woff2 |
| .ttf | font/ttf |
| .pdf | application/pdf |

## Potential Attack Vectors (Mitigated)

1. **XSS via uploaded content**: Mitigated by CSP headers and iframe sandboxing
2. **Path traversal**: Mitigated by `..` filtering and hash validation
3. **CSRF attacks**: Mitigated by CSRF token validation
4. **Unauthorized editing**: Mitigated by ACL permission checks
5. **Clickjacking**: Mitigated by X-Frame-Options header
6. **Direct file access**: Mitigated by nginx rules blocking /files/exelearning/
7. **MIME sniffing**: Mitigated by X-Content-Type-Options header

## Recommendations for Administrators

1. Ensure nginx is properly configured with the blocking rules
2. Review CSP headers if specific eXeLearning content requires external resources
3. Keep the module updated for security patches
4. Monitor server logs for suspicious access patterns
5. Consider additional rate limiting on API endpoints
