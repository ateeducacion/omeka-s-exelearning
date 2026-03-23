# eXeLearning

<a href="https://ateeducacion.github.io/omeka-s-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Fexelearning%2Fomeka-s-exelearning%2Frefs%2Fheads%2Fmain%2Fblueprint.json">
  <img src="https://raw.githubusercontent.com/ateeducacion/omeka-s-playground/main/ogimage.png" alt="Try eXeLearning in your browser" width="220">
</a><br>
<small><a href="https://ateeducacion.github.io/omeka-s-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Fexelearning%2Fomeka-s-exelearning%2Frefs%2Fheads%2Fmain%2Fblueprint.json">Try in your browser</a></small>

![CI](https://img.shields.io/github/actions/workflow/status/exelearning/omeka-s-exelearning/ci.yml?label=CI)
[![codecov](https://codecov.io/gh/exelearning/omeka-s-exelearning/graph/badge.svg)](https://codecov.io/gh/exelearning/omeka-s-exelearning)
![Omeka S Version](https://img.shields.io/badge/Omeka_S-%3E%3D3.0-blue)
![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%207.4-8892bf)
![License: AGPL v3](https://img.shields.io/badge/License-AGPLv3-blue.svg)
![Downloads](https://img.shields.io/github/downloads/exelearning/omeka-s-exelearning/total)
![Last Commit](https://img.shields.io/github/last-commit/exelearning/omeka-s-exelearning)
![Open Issues](https://img.shields.io/github/issues/exelearning/omeka-s-exelearning)

Omeka S module for eXeLearning content management. Upload, view and edit eXeLearning `.elpx` files directly within Omeka S.

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

# Route content proxy to PHP
location ^~ /exelearning/content/ {
    try_files $uri /index.php$is_args$args;
}
```

Apache is supported automatically via the included `.htaccess` file.

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

> **Important:** It is recommended to download from [Releases](https://github.com/exelearning/omeka-s-exelearning/releases) for production use, which includes the embedded editor pre-built. If you clone the repository without building the editor, you can install it from the Omeka S admin panel at **Modules > ExeLearning > Configure** using the "Download & Install Editor" button, which fetches the latest static editor package from GitHub Releases automatically. No remote loading is used at runtime.

## Usage

### Uploading ELPX Files

1. Navigate to an Item in Omeka S
2. Click **Add media** and select your `.elpx` file
3. Save the item — the content will be displayed in the media viewer

### Editing Content

1. Go to the media page (**Admin > Items > [Your Item] > [Media]**)
2. Click **Edit in eXeLearning**
3. Make your changes and click **Save to Omeka**

## Development

```bash
make up          # Start Docker environment (http://localhost:8080)
make down        # Stop containers
make lint        # Check code style
make fix         # Auto-fix code style
make package VERSION=1.2.3  # Build a .zip release
```

Default credentials: `admin@example.com` / `PLEASE_CHANGEME`

## Requirements

- Omeka S 3.0 or higher
- PHP 7.4 or higher with ZipArchive extension

## License

This module is licensed under the AGPL v3 or later.
