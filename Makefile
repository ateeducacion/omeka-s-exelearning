# Makefile for ExeLearning Omeka-S Module

# Define SED_INPLACE based on the operating system
ifeq ($(shell uname), Darwin)
  SED_INPLACE = sed -i ''
else
  SED_INPLACE = sed -i
endif

# Detect the operating system
ifeq ($(OS),Windows_NT)
    ifdef MSYSTEM
        SYSTEM_OS := unix
    else ifdef CYGWIN
        SYSTEM_OS := unix
    else
        SYSTEM_OS := windows
    endif
else
    SYSTEM_OS := unix
endif

.PHONY: help check-docker check-bun up upd down pull build lint fix shell clean seed \
        fetch-editor-source build-editor build-editor-no-update clean-editor \
        package generate-pot update-po check-untranslated compile-mo i18n test test-coverage \
        architecture-records architecture-check

# ============================================================================
# eXeLearning Editor Build
# ============================================================================

check-bun:
	@command -v bun >/dev/null 2>&1 || { \
		echo ""; \
		echo "Error: Bun is not installed."; \
		echo "   Install it from: https://bun.sh/"; \
		echo "   Quick install: curl -fsSL https://bun.sh/install | bash"; \
		echo ""; \
		exit 1; \
	}

EDITOR_SUBMODULE_PATH := exelearning
EDITOR_OUTPUT_DIR := $(CURDIR)/dist/static
EDITOR_REPO_DEFAULT := https://github.com/exelearning/exelearning.git
EDITOR_REF_DEFAULT := main

# Fetch editor source code from remote repository (branch/tag, shallow clone)
fetch-editor-source:
	@set -e; \
	get_env() { \
		if [ -f .env ]; then \
			grep -E "^$$1=" .env | tail -n1 | cut -d '=' -f2-; \
		fi; \
	}; \
	REPO_URL="$${EXELEARNING_EDITOR_REPO_URL:-$$(get_env EXELEARNING_EDITOR_REPO_URL)}"; \
	REF="$${EXELEARNING_EDITOR_REF:-$$(get_env EXELEARNING_EDITOR_REF)}"; \
	REF_TYPE="$${EXELEARNING_EDITOR_REF_TYPE:-$$(get_env EXELEARNING_EDITOR_REF_TYPE)}"; \
	if [ -z "$$REPO_URL" ]; then REPO_URL="$(EDITOR_REPO_DEFAULT)"; fi; \
	if [ -z "$$REF" ]; then REF="$${EXELEARNING_EDITOR_DEFAULT_BRANCH:-$$(get_env EXELEARNING_EDITOR_DEFAULT_BRANCH)}"; fi; \
	if [ -z "$$REF" ]; then REF="$(EDITOR_REF_DEFAULT)"; fi; \
	if [ -z "$$REF_TYPE" ]; then REF_TYPE="auto"; fi; \
	echo "Fetching editor source from $$REPO_URL (ref=$$REF, type=$$REF_TYPE)"; \
	rm -rf $(EDITOR_SUBMODULE_PATH); \
	git init -q $(EDITOR_SUBMODULE_PATH); \
	git -C $(EDITOR_SUBMODULE_PATH) remote add origin "$$REPO_URL"; \
	case "$$REF_TYPE" in \
		tag) \
			git -C $(EDITOR_SUBMODULE_PATH) fetch --depth 1 origin "refs/tags/$$REF:refs/tags/$$REF"; \
			git -C $(EDITOR_SUBMODULE_PATH) checkout -q "tags/$$REF"; \
			;; \
		branch) \
			git -C $(EDITOR_SUBMODULE_PATH) fetch --depth 1 origin "$$REF"; \
			git -C $(EDITOR_SUBMODULE_PATH) checkout -q FETCH_HEAD; \
			;; \
		auto) \
			if git -C $(EDITOR_SUBMODULE_PATH) fetch --depth 1 origin "refs/tags/$$REF:refs/tags/$$REF" > /dev/null 2>&1; then \
				echo "Resolved $$REF as tag"; \
				git -C $(EDITOR_SUBMODULE_PATH) checkout -q "tags/$$REF"; \
			else \
				echo "Resolved $$REF as branch"; \
				git -C $(EDITOR_SUBMODULE_PATH) fetch --depth 1 origin "$$REF"; \
				git -C $(EDITOR_SUBMODULE_PATH) checkout -q FETCH_HEAD; \
			fi; \
			;; \
		*) \
			echo "Error: EXELEARNING_EDITOR_REF_TYPE must be one of: auto, branch, tag"; \
			exit 1; \
			;; \
	esac

# Build static version of eXeLearning editor
build-editor: check-bun fetch-editor-source
	@echo "Building eXeLearning static editor..."
	rm -rf $(EDITOR_OUTPUT_DIR)
	@# Resolve the version to embed in the editor build. The editor's build script
	@# (scripts/build-static-bundle.ts) reads APP_VERSION/VERSION; without it the
	@# build falls back to package.json (0.0.0-alpha). Resolution order:
	@#   APP_VERSION -> VERSION -> EXELEARNING_EDITOR_REF (env or .env) ->
	@#   exact git tag of the checked-out editor -> empty (dev default -> alpha)
	@set -e; \
	get_env() { \
		if [ -f .env ]; then \
			grep -E "^$$1=" .env | tail -n1 | cut -d '=' -f2-; \
		fi; \
	}; \
	APP_VER="$${APP_VERSION:-$${VERSION:-$${EXELEARNING_EDITOR_REF:-$$(get_env EXELEARNING_EDITOR_REF)}}}"; \
	if [ -z "$$APP_VER" ] || [ "$$APP_VER" = "main" ] || [ "$$APP_VER" = "master" ]; then \
		EXACT_TAG="$$(git -C $(EDITOR_SUBMODULE_PATH) describe --tags --exact-match 2>/dev/null || true)"; \
		if [ -n "$$EXACT_TAG" ]; then APP_VER="$$EXACT_TAG"; fi; \
	fi; \
	echo "Editor version input: $${APP_VER:-(default -> alpha)}"; \
	cd $(EDITOR_SUBMODULE_PATH) && bun install && OUTPUT_DIR=$(EDITOR_OUTPUT_DIR) APP_VERSION="$$APP_VER" bun run build:static
	@# Create symlink for Omeka asset serving
	@rm -f asset/static
	@ln -s ../dist/static asset/static
	@echo ""
	@echo "============================================"
	@echo "  Static editor built at dist/static/"
	@echo "============================================"

# Backward-compatible alias
build-editor-no-update: build-editor

clean-editor:
	rm -rf dist/static
	rm -f asset/static
	rm -rf $(EDITOR_SUBMODULE_PATH)/dist/static
	rm -rf $(EDITOR_SUBMODULE_PATH)/node_modules

# ============================================================================
# Docker Management
# ============================================================================

check-docker:
ifeq ($(SYSTEM_OS),windows)
	@echo "Detected system: Windows (cmd, powershell)"
	@docker version > NUL 2>&1 || (echo. & echo Error: Docker is not running. & exit 1)
else
	@echo "Detected system: Unix (Linux/macOS/Cygwin/MinGW)"
	@docker version > /dev/null 2>&1 || (echo "Error: Docker is not running." && exit 1)
endif

up: check-docker
	docker compose up --detach --remove-orphans
	@$(MAKE) seed
	docker compose logs -f

upd: check-docker
	docker compose up --detach --remove-orphans
	@$(MAKE) seed

down: check-docker
	docker compose down

pull: check-docker
	docker compose -f docker-compose.yml pull

build: check-docker
	docker compose build

shell: check-docker
	docker compose exec omekas sh

clean: check-docker
	docker compose down -v --remove-orphans

seed: check-docker
	@echo "Waiting for Omeka S to be ready..."
	@for i in $$(seq 1 30); do \
		STATUS=$$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/api/items 2>/dev/null); \
		if [ "$$STATUS" = "200" ]; then \
			break; \
		fi; \
		if [ $$i -eq 30 ]; then \
			echo "Timeout waiting for Omeka S API"; \
			exit 1; \
		fi; \
		sleep 2; \
	done
	@echo "Seeding test data..."
	@sh data/seed-exelearning.sh

# ============================================================================
# Code Quality
# ============================================================================

lint: architecture-check
	vendor/bin/phpcs src/ config/ scripts/ Module.php --standard=PSR2 --colors -n

fix:
	vendor/bin/phpcbf src/ config/ scripts/ Module.php --standard=PSR2 --colors || true

# Print the ADR and change indexes, derived from document frontmatter.
# Deliberately not a committed file: a generated index conflicts on every
# concurrent branch, and both hand-maintained ones had already drifted.
architecture-records:
	@node scripts/architecture-records.mts list

# Validate architecture record identifiers, metadata and cross-references.
# No Composer dependencies, so this works before `composer install` has run --
# which is why the CI workflow that guards documentation-only pull requests can
# skip the whole PHP dependency install.
architecture-check:
	@node scripts/architecture-records.mts check

test:
	@echo "Running unit tests..."
	@"vendor/bin/phpunit" -c test/phpunit.xml

# Minimum line coverage enforced by `make test-coverage`. This is a ratchet:
# raise it when coverage improves, never lower it to make a build pass.
MIN_COVERAGE ?= 90

test-coverage:
	@echo "Running unit tests with coverage (requires xdebug or pcov)..."
	@mkdir -p artifacts/coverage
	@# PHPUnit writes its own reports instead of the output being piped into
	@# `tee`. With a pipe, the recipe's exit status is tee's, so failing tests
	@# still reported success and only the coverage percentage was ever gated
	@# -- CI ran green for weeks on a suite with two failures. /bin/sh has no
	@# portable `pipefail`, so removing the pipe is the fix, not working around
	@# it. clover.xml is what Codecov consumes.
	@# pcov.directory is pinned to the repository root: left to auto-detect it
	@# resolves somewhere under src/ and silently drops the root-level
	@# Module.php from the report, so a pcov machine and an xdebug machine
	@# (what CI uses) gate on different numbers. Harmless when pcov is absent.
	@XDEBUG_MODE=coverage php -d pcov.directory="$(CURDIR)" "vendor/bin/phpunit" -c test/phpunit.xml \
		--coverage-text=artifacts/coverage/coverage.txt \
		--coverage-clover=artifacts/coverage/clover.xml \
		--coverage-html=artifacts/coverage/html
	@COVERAGE=$$(sed 's/\x1b\[[0-9;]*m//g' artifacts/coverage/coverage.txt | grep -E "Lines:" | head -1 | sed -E 's/.*Lines:[[:space:]]+([0-9]+\.[0-9]+)%.*/\1/'); \
	echo ""; \
	if [ -z "$$COVERAGE" ]; then \
		echo "Error: Could not parse coverage percentage"; \
		exit 1; \
	fi; \
	echo "Line coverage: $${COVERAGE}%"; \
	COVERAGE_INT=$$(echo "$$COVERAGE" | cut -d. -f1); \
	if [ "$$COVERAGE_INT" -lt $(MIN_COVERAGE) ]; then \
		echo "Error: Coverage ($${COVERAGE}%) is below minimum threshold ($(MIN_COVERAGE)%)"; \
		exit 1; \
	else \
		echo "Coverage check passed: $${COVERAGE}% >= $(MIN_COVERAGE)%"; \
	fi
	@echo "Reports: artifacts/coverage/{coverage.txt,clover.xml,html/index.html}"

# ============================================================================
# Packaging
# ============================================================================

package:
	@if [ -z "$(VERSION)" ]; then \
		echo "Error: VERSION not specified. Use 'make package VERSION=1.2.3'"; \
		exit 1; \
	fi
	@# The embedded editor is a release artifact (ADR-28-01): never produce a
	@# package without a valid bundled editor.
	@if [ ! -r dist/static/index.html ]; then \
		echo "Error: dist/static/index.html is missing or unreadable. Build the editor with 'make build-editor' before packaging." >&2; \
		exit 1; \
	fi
	@if [ ! -d dist/static/app ] && [ ! -d dist/static/libs ] && [ ! -d dist/static/files ]; then \
		echo "Error: dist/static/ is missing the expected editor asset directories (app/, libs/ or files/)." >&2; \
		exit 1; \
	fi
	@if [ ! -f .editor-version ] || [ -z "$$(tr -d '[:space:]' < .editor-version)" ]; then \
		echo "Error: .editor-version is missing or empty; it must name the bundled editor version." >&2; \
		exit 1; \
	fi
	@echo "Updating version to $(VERSION) in module.ini..."
	$(SED_INPLACE) 's/^\([[:space:]]*version[[:space:]]*=[[:space:]]*\).*$$/\1"$(VERSION)"/' config/module.ini
	@echo "Creating ZIP archive: ExeLearning-$(VERSION).zip..."
	rm -rf /tmp/exelearning-omeka-package
	mkdir -p /tmp/exelearning-omeka-package/ExeLearning
	rsync -av --exclude-from=.distignore ./ /tmp/exelearning-omeka-package/ExeLearning/
	cd /tmp/exelearning-omeka-package && zip -qr "$(CURDIR)/ExeLearning-$(VERSION).zip" ExeLearning
	rm -rf /tmp/exelearning-omeka-package
	@echo "Restoring version to 0.0.0 in module.ini..."
	$(SED_INPLACE) 's/^\([[:space:]]*version[[:space:]]*=[[:space:]]*\).*$$/\1"0.0.0"/' config/module.ini
	@echo "Package created: ExeLearning-$(VERSION).zip"

# ============================================================================
# Translations (i18n)
# ============================================================================

generate-pot:
	@echo "Extracting strings using xgettext..."
	find . -path ./vendor -prune -o -path ./exelearning -prune -o -path ./dist -prune -o \
		\( -name '*.php' -o -name '*.phtml' \) -print \
	| xargs xgettext \
	    --language=PHP \
	    --from-code=utf-8 \
	    --keyword=translate \
	    --keyword=translatePlural:1,2 \
	    --output=language/xgettext.pot
	@echo "Extracting strings marked with // @translate..."
	vendor/zerocrates/extract-tagged-strings/extract-tagged-strings.php > language/tagged.pot
	@echo "Merging xgettext.pot and tagged.pot into template.pot..."
	msgcat language/xgettext.pot language/tagged.pot --use-first -o language/template.pot
	@rm -f language/xgettext.pot language/tagged.pot
	@echo "Generated language/template.pot"

update-po:
	@echo "Updating translation files..."
	@find language -name "*.po" | while read po; do \
		echo "Updating $$po..."; \
		msgmerge --update --backup=off "$$po" language/template.pot; \
	done

check-untranslated:
	@echo "Checking untranslated strings..."
	@FOUND_UNTRANSLATED=0; \
	for po in language/*.po; do \
		echo ""; \
		echo "Checking $$po..."; \
		UNTRANSLATED=$$(msgattrib --untranslated "$$po" 2>/dev/null | grep -c "^msgid \"" || true); \
		if [ "$$UNTRANSLATED" -gt 1 ]; then \
			echo "  Warning: $$((UNTRANSLATED - 1)) untranslated string(s) found:"; \
			msgattrib --untranslated "$$po" 2>/dev/null | grep -A1 "^msgid \"" | grep "^msgid" | head -10; \
			FOUND_UNTRANSLATED=1; \
		else \
			echo "  All strings translated!"; \
		fi; \
	done; \
	if [ "$$FOUND_UNTRANSLATED" -eq 1 ]; then \
		echo ""; \
		echo "Error: Untranslated strings found. Run 'make update-po' and translate."; \
		exit 1; \
	fi

compile-mo:
	@echo "Compiling .po files into .mo..."
	@find language -name '*.po' | while read po; do \
		mo=$${po%.po}.mo; \
		msgfmt "$$po" -o "$$mo"; \
		echo "Compiled $$po -> $$mo"; \
	done

i18n: generate-pot update-po check-untranslated compile-mo

# ============================================================================
# Help
# ============================================================================

help:
	@echo ""
	@echo "ExeLearning Omeka-S Module"
	@echo "========================="
	@echo ""
	@echo "eXeLearning Editor:"
	@echo "  build-editor           - Build static editor from configured repo/ref"
	@echo "  build-editor-no-update - Alias of build-editor"
	@echo "  clean-editor           - Remove editor build artifacts"
	@echo "  fetch-editor-source    - Download editor source from configured repo/ref"
	@echo ""
	@echo "Docker management:"
	@echo "  up                     - Start Docker containers in interactive mode"
	@echo "  upd                    - Start Docker containers in background (detached)"
	@echo "  down                   - Stop and remove Docker containers"
	@echo "  build                  - Build or rebuild Docker containers"
	@echo "  pull                   - Pull the latest images from the registry"
	@echo "  clean                  - Stop containers and remove volumes"
	@echo "  shell                  - Open a shell inside the omekas container"
	@echo ""
	@echo "Code quality:"
	@echo "  lint                   - Run PHP linter (PHP_CodeSniffer) + architecture-check"
	@echo "  fix                    - Automatically fix PHP code style issues"
	@echo "  test                   - Run unit tests with PHPUnit"
	@echo "  test-coverage          - Run tests with coverage report (requires xdebug/pcov)"
	@echo "  architecture-records   - Print the ADR and change indexes"
	@echo "  architecture-check     - Validate architecture record identifiers/metadata"
	@echo ""
	@echo "Packaging:"
	@echo "  package VERSION=x.y.z  - Generate a .zip package of the module"
	@echo ""
	@echo "Translations (i18n):"
	@echo "  generate-pot           - Extract translatable strings to template.pot"
	@echo "  update-po              - Update .po files from template.pot"
	@echo "  check-untranslated     - Check for untranslated strings"
	@echo "  compile-mo             - Compile .mo files from .po files"
	@echo "  i18n                   - Run full translation workflow"
	@echo ""

.DEFAULT_GOAL := help
