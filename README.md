# eXeLearning
![CI](https://img.shields.io/github/actions/workflow/status/exelearning/omeka-s-exelearning/ci.yml?label=CI)
[![codecov](https://codecov.io/gh/exelearning/omeka-s-exelearning/graph/badge.svg)](https://codecov.io/gh/exelearning/omeka-s-exelearning)
![Omeka S Version](https://img.shields.io/badge/Omeka_S-%3E%3D3.0-blue)
![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%207.4-8892bf)
![License: AGPL v3](https://img.shields.io/badge/License-AGPLv3-blue.svg)
![Downloads](https://img.shields.io/github/downloads/exelearning/omeka-s-exelearning/total)
![Last Commit](https://img.shields.io/github/last-commit/exelearning/omeka-s-exelearning)

Omeka S module for eXeLearning content management. Upload, view and edit eXeLearning `.elpx` files directly within Omeka S.

<a href="https://ateeducacion.github.io/omeka-s-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Fexelearning%2Fomeka-s-exelearning%2Frefs%2Fheads%2Fmain%2Fblueprint.json" target="_blank" rel="noopener noreferrer">
  <img src="https://raw.githubusercontent.com/ateeducacion/omeka-s-playground/main/assets/playground-preview-button.svg" alt="Try eXeLearning on Omeka S Playground" width="224">
</a>

> ℹ️ The eXeLearning editor is fetched from the shared release and unpacked into the module when the playground boots, so the first load may take a few extra seconds. ELPX upload, viewer and preview work normally.

## Features

- **ELPX File Support**: Upload and manage eXeLearning `.elpx` files through Omeka S
- **Automatic Extraction**: ELPX files are automatically extracted and ready to display
- **Embedded Editor**: Edit eXeLearning content directly from Omeka S without leaving the browser
- **Automatic Thumbnails**: Generates visual thumbnails from the content's first page
- **Secure Content Delivery**: All content served through a secure proxy with CSP headers and iframe sandboxing

## Installation

### From Releases (Recommended)

1. **Download the latest release** from the [GitHub Releases page](https://github.com/exelearning/omeka-s-exelearning/releases).
2. Extract to your Omeka S `modules` directory as `ExeLearning`.
3. Log in to the admin panel, go to **Modules** and click **Install**.

### Server Configuration (nginx)

Add these rules to your nginx configuration:

```nginx
# Block direct access to extracted files
location ^~ /files/exelearning/ {
    return 403;
}

# Block direct access to the ephemeral editor-preview session store.
# REQUIRED on nginx (and any non-Apache server): these bytes are only meant to
# be served through the opaque-origin preview capability URL with a sandbox CSP;
# a direct hit would leak untrusted author HTML same-origin without that CSP.
location ^~ /files/exelearning-preview/ {
    return 403;
}

# Route content proxy to PHP
location ^~ /exelearning/content/ {
    try_files $uri /index.php$is_args$args;
}
```

Apache is supported automatically via the included `.htaccess` deny guards
(one for the extracted-content store, one written into the preview session
store). **Non-Apache deployments MUST deny direct web access to both
`{file_store}/exelearning` and `{file_store}/exelearning-preview`** — nginx and
other servers do not read `.htaccess`.

### From Source (Development)

```bash
git clone https://github.com/exelearning/omeka-s-exelearning.git
cd omeka-s-exelearning
make build-editor
```

By default, `make build-editor` fetches `https://github.com/exelearning/exelearning` from `main` using a shallow checkout. You can override source/ref at runtime:

```bash
EXELEARNING_EDITOR_REF=vX.Y.Z EXELEARNING_EDITOR_REF_TYPE=tag make build-editor
```

> **Important:** For production use, always install an official release from [Releases](https://github.com/exelearning/omeka-s-exelearning/releases): release packages include the embedded editor pre-built under `dist/static/`, and that bundle is the only editor the module ever uses. The module never downloads editor code at runtime, and administrators cannot update the editor independently of the module — updating the editor means updating the module (a new module release is published automatically for every editor release). Source checkouts do not contain `dist/static/`; build it with `make build-editor` as shown above. See [ADR-0001](docs/architecture/adr/ADR-0001-bundle-editor-exclusively-in-release-packages.md).

## Usage

### Uploading ELPX Files

1. Navigate to an Item in Omeka S
2. Click **Add media** and select your `.elpx` file
3. Save the item — the content will be displayed in the media viewer

### Editing Content

1. Go to the media page (**Admin > Items > [Your Item] > [Media]**)
2. Click **Edit in eXeLearning**
3. Make your changes and click **Save to Omeka**

## External embeds in secure mode

In secure mode the `.elpx` content is served inside an opaque-origin sandboxed iframe. That isolation is what keeps untrusted package content from reading or scripting the host page, but it also blanks third-party players (YouTube/Vimeo) and inline PDFs, which refuse to run in an opaque origin.

To restore them, the module promotes those embeds to the parent page and renders them inline:

- **In-iframe shim** (`asset/js/exe-embed-shim.js`): inside the sandbox it finds whitelisted video iframes, any `https` `.pdf` iframe, and local package PDFs, replaces each with a placeholder, and `postMessage`s the embed URL plus geometry to the parent.
- **Parent relay** (`asset/js/exe-embed-relay.js`): validates each URL against the host whitelist (YouTube/Vimeo hosts, plus PDFs), rebuilds the canonical player URL, and overlays the real player on top of the placeholder so it lines up with the content.

A cross-browser (Firefox) Playwright e2e exercises this against a static harness with the real shim/relay (no Omeka runtime needed):

```bash
npm install            # once, to fetch @playwright/test
npm run test:e2e:embed # serves the harness and runs the Firefox spec
```

## Development

```bash
make up          # Start Docker environment (http://localhost:8080)
make down        # Stop containers
make lint        # Check code style
make fix         # Auto-fix code style
make package VERSION=1.2.3  # Build a .zip release
```

Default credentials: `admin@example.com` / `PLEASE_CHANGEME`

### Architecture documentation

Architecture Decision Records (ADRs) and Software Design Documents (SDDs) live
under [`docs/architecture/`](docs/architecture/README.md). Use them for
significant design, security, storage, content-proxy, embedded-editor, or
compatibility changes.

## Requirements

- Omeka S 3.0 or higher
- PHP 7.4 or higher with ZipArchive extension

## Issues and Support

Issue tracking for this module is centralized in the main
[`exelearning/exelearning`](https://github.com/exelearning/exelearning) repository.
Please [open new issues there](https://github.com/exelearning/exelearning/issues/new),
and browse [existing `omeka-s`-labeled issues](https://github.com/exelearning/exelearning/issues?q=is%3Aissue+label%3Aomeka-s)
before reporting a bug or requesting a feature.

## License

This module is licensed under the AGPL v3 or later.
