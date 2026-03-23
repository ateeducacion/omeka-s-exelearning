/**
 * eXeLearning Editor Modal Handler for Omeka S
 *
 * Opens the editor page in a fullscreen modal for editing .elpx files.
 */
(function() {
    'use strict';

    var ExeLearningEditor = {
        modal: null,
        iframe: null,
        saveBtn: null,
        closeBtn: null,
        loadingModal: null,
        currentMediaId: null,
        isOpen: false,
        isSaving: false,
        hasUnsavedChanges: false,

        /**
         * Initialize the editor.
         */
        init: function() {
            this.modal = document.getElementById('exelearning-editor-modal');
            this.iframe = document.getElementById('exelearning-editor-iframe');
            this.saveBtn = document.getElementById('exelearning-editor-save');
            this.closeBtn = document.getElementById('exelearning-editor-close');

            if (this.modal) {
                this.bindEvents();
            }

            // Start save button as disabled (enabled on DOCUMENT_LOADED)
            if (this.saveBtn) {
                this.saveBtn.disabled = true;
                this.updateSaveButtonContent(false);
            }
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            var self = this;

            this.saveBtn?.addEventListener('click', function() { self.requestSave(); });
            this.closeBtn?.addEventListener('click', function() { self.close(); });
            window.addEventListener('message', function(event) { self.handleMessage(event); });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && self.isOpen) {
                    self.close();
                }
            });
        },

        /**
         * Update save button content with icon.
         *
         * @param {boolean} saving Whether save is in progress.
         */
        updateSaveButtonContent: function(saving) {
            if (!this.saveBtn) {
                return;
            }
            var i18n = window.exelearningEditorI18n || {};
            var label = saving
                ? (i18n.saving || 'Saving...')
                : (i18n.saveButton || 'Save to Omeka');
            this.saveBtn.innerHTML =
                '<span class="o-icon-upload" aria-hidden="true"></span> ' + label;
        },

        /**
         * Request save from the iframe.
         */
        requestSave: function() {
            if (this.isSaving || !this.iframe) {
                return;
            }

            var iframeWindow = this.iframe.contentWindow;
            if (iframeWindow) {
                iframeWindow.postMessage({ type: 'exelearning-request-save' }, '*');
            }
        },

        /**
         * Create the loading modal element.
         */
        createLoadingModal: function() {
            if (this.loadingModal) {
                return;
            }
            var i18n = window.exelearningEditorI18n || {};
            var savingText = i18n.saving || 'Saving...';
            var waitText = i18n.savingWait || 'Please wait while the file is being saved.';
            var closeText = i18n.close || 'Close';

            var div = document.createElement('div');
            div.className = 'exelearning-loading-modal';
            div.id = 'exelearning-loading-modal';
            div.innerHTML =
                '<div class="exelearning-loading-modal__content">' +
                    '<div class="exelearning-loading-modal__spinner"></div>' +
                    '<h3 class="exelearning-loading-modal__title">' + savingText + '</h3>' +
                    '<p class="exelearning-loading-modal__message">' + waitText + '</p>' +
                    '<div class="exelearning-loading-modal__error">' +
                        '<p class="exelearning-loading-modal__error-text"></p>' +
                        '<button type="button" class="button exelearning-loading-modal__close">' + closeText + '</button>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(div);
            this.loadingModal = div;

            var self = this;
            div.querySelector('.exelearning-loading-modal__close').addEventListener('click', function() {
                self.hideLoadingModal();
            });
        },

        /**
         * Show the loading modal.
         */
        showLoadingModal: function() {
            this.createLoadingModal();
            this.loadingModal.classList.remove('is-error');
            this.loadingModal.classList.add('is-visible');
        },

        /**
         * Hide the loading modal.
         */
        hideLoadingModal: function() {
            if (this.loadingModal) {
                this.loadingModal.classList.remove('is-visible', 'is-error');
            }
        },

        /**
         * Remove the loading modal from DOM.
         */
        removeLoadingModal: function() {
            if (this.loadingModal) {
                this.loadingModal.remove();
                this.loadingModal = null;
            }
        },

        /**
         * Show error in the loading modal.
         *
         * @param {string} message The error message.
         */
        showLoadingError: function(message) {
            this.createLoadingModal();
            this.loadingModal.classList.add('is-error');
            var errorText = this.loadingModal.querySelector('.exelearning-loading-modal__error-text');
            if (errorText) {
                errorText.textContent = message;
            }
        },

        /**
         * Set saving state and update button.
         *
         * @param {boolean} saving Whether save is in progress.
         */
        setSavingState: function(saving) {
            this.isSaving = saving;
            if (this.saveBtn) {
                this.saveBtn.disabled = saving;
                this.updateSaveButtonContent(saving);
            }
            if (saving) {
                this.showLoadingModal();
            } else {
                this.hideLoadingModal();
            }
        },

        /**
         * Open the editor modal.
         *
         * @param {number} mediaId The media ID.
         * @param {string} editorUrl The editor URL.
         */
        open: function(mediaId, editorUrl) {
            if (!mediaId || !editorUrl) {
                console.error('ExeLearningEditor: Missing mediaId or editorUrl');
                return;
            }

            this.currentMediaId = mediaId;
            this.hasUnsavedChanges = false;

            if (!this.modal) {
                window.open(editorUrl, '_blank', 'width=1200,height=800');
                return;
            }

            // Recreate iframe if it was destroyed by a previous close/save
            if (!this.iframe) {
                var iframe = document.createElement('iframe');
                iframe.id = 'exelearning-editor-iframe';
                iframe.className = 'exelearning-editor-iframe';
                this.modal.appendChild(iframe);
                this.iframe = iframe;
            }

            this.modal.style.display = 'flex';
            this.isOpen = true;
            this.iframe.src = editorUrl;
            document.body.classList.add('exelearning-editor-open');

            // Start save button as disabled until document loads
            if (this.saveBtn) {
                this.saveBtn.disabled = true;
                this.updateSaveButtonContent(false);
            }
        },

        /**
         * Close the editor modal.
         */
        close: function() {
            if (!this.isOpen) {
                return;
            }

            // Check for unsaved changes
            if (this.hasUnsavedChanges) {
                var i18n = window.exelearningEditorI18n || {};
                var message = i18n.unsavedChanges ||
                    'You have unsaved changes. Are you sure you want to close?';
                if (!window.confirm(message)) {
                    return;
                }
            }

            // Destroy the iframe to prevent beforeunload dialog
            this.destroyIframe();

            // Hide modal
            if (this.modal) {
                this.modal.style.display = 'none';
            }
            this.isOpen = false;
            this.hasUnsavedChanges = false;

            // Remove body class
            document.body.classList.remove('exelearning-editor-open');

            // Reset state
            this.currentMediaId = null;
        },

        /**
         * Handle messages from iframe.
         *
         * @param {MessageEvent} event The message event.
         */
        handleMessage: function(event) {
            var data = event.data;

            if (!data || !data.type) {
                return;
            }

            switch (data.type) {
                case 'exelearning-bridge-ready':
                    // Bridge is ready
                    console.log('ExeLearningEditor: Bridge ready');
                    break;

                case 'exelearning-save-start':
                    this.setSavingState(true);
                    break;

                case 'exelearning-save-complete':
                    this.setSavingState(false);
                    this.hasUnsavedChanges = false;
                    this.onSaveComplete(data);
                    break;

                case 'exelearning-save-error':
                    this.setSavingState(false);
                    this.showLoadingError(data.message || 'Save failed');
                    console.error('ExeLearningEditor: Save failed -', data.message || 'Unknown error');
                    break;

                case 'exelearning-close':
                    this.close();
                    break;

                case 'DOCUMENT_LOADED':
                    if (!this.isSaving && this.saveBtn) {
                        this.saveBtn.disabled = false;
                    }
                    this.hasUnsavedChanges = false;
                    break;

                case 'DOCUMENT_CHANGED':
                    this.hasUnsavedChanges = true;
                    break;
            }
        },

        /**
         * Destroy the iframe to prevent beforeunload dialogs.
         */
        destroyIframe: function() {
            if (!this.iframe) {
                return;
            }
            try {
                // Remove beforeunload handlers from the iframe's window
                this.iframe.contentWindow.onbeforeunload = null;
            } catch (e) {
                // Cross-origin or already detached
            }
            // Remove the iframe from DOM entirely - this prevents
            // any addEventListener('beforeunload') handlers from firing
            this.iframe.remove();
            this.iframe = null;
        },

        /**
         * Handle save complete.
         *
         * @param {object} data The message data.
         */
        onSaveComplete: function(data) {
            // Update the admin preview iframe with the new content URL.
            // Use window.exelearningContentBase (set by the page's inline script)
            // to prepend the correct base including the playground SW scope prefix.
            var previewIframe = document.querySelector('.preview-iframe');
            if (data.contentPath && previewIframe) {
                var base = window.exelearningContentBase || window.location.origin;
                previewIframe.src = base + data.contentPath;
            }
            // Always close the modal after a successful save — avoid
            // window.location.reload() which 404s in PHP-WASM playground.
            this.close();
        }
    };

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            ExeLearningEditor.init();
        });
    } else {
        ExeLearningEditor.init();
    }

    // Expose globally
    window.ExeLearningEditor = ExeLearningEditor;

})();
