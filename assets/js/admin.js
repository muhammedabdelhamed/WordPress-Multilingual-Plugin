/**
 * Maharat Multilingual – Admin JavaScript
 *
 * Handles admin interactions: translation meta box, auto-translate buttons,
 * language management forms, and bulk operations.
 *
 * @package Maharat\Multilingual
 */

/* global jQuery, maharatAdmin */
(function ($) {
    'use strict';

    var maharat = window.maharatAdmin || {};

    /* =================================================================
     * Translation Meta Box
     * ================================================================= */

    var MetaBox = {
        init: function () {
            this.$box = $('#maharat-translation-meta-box');
            if (!this.$box.length) {
                return;
            }

            this.bindEvents();
        },

        bindEvents: function () {
            // Auto-translate button.
            this.$box.on('click', '.maharat-auto-translate', this.handleAutoTranslate.bind(this));

            // Create translation button.
            this.$box.on('click', '.maharat-create-translation', this.handleCreateTranslation.bind(this));
        },

        handleAutoTranslate: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var postId = $btn.data('post-id');
            var targetLang = $btn.data('target-lang');

            if ($btn.hasClass('loading')) {
                return;
            }

            $btn.addClass('loading').prop('disabled', true);

            $.ajax({
                url: maharat.restUrl + 'maharat/v1/auto-translate',
                method: 'POST',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', maharat.nonce);
                },
                data: JSON.stringify({
                    post_id: postId,
                    target_language: targetLang,
                }),
                contentType: 'application/json',
                success: function (response) {
                    if (response.translation_id) {
                        // Reload to show updated meta box.
                        window.location.reload();
                    }
                },
                error: function (xhr) {
                    var msg = xhr.responseJSON && xhr.responseJSON.message
                        ? xhr.responseJSON.message
                        : maharat.i18n.autoTranslateError;
                    alert(msg);
                },
                complete: function () {
                    $btn.removeClass('loading').prop('disabled', false);
                },
            });
        },

        handleCreateTranslation: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var postId = $btn.data('post-id');
            var targetLang = $btn.data('target-lang');

            if ($btn.hasClass('loading')) {
                return;
            }

            $btn.addClass('loading').prop('disabled', true);

            $.ajax({
                url: maharat.restUrl + 'maharat/v1/translations',
                method: 'POST',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', maharat.nonce);
                },
                data: JSON.stringify({
                    source_id: postId,
                    target_language: targetLang,
                }),
                contentType: 'application/json',
                success: function (response) {
                    if (response.edit_url) {
                        window.location.href = response.edit_url;
                    } else {
                        window.location.reload();
                    }
                },
                error: function (xhr) {
                    var msg = xhr.responseJSON && xhr.responseJSON.message
                        ? xhr.responseJSON.message
                        : maharat.i18n.createError;
                    alert(msg);
                },
                complete: function () {
                    $btn.removeClass('loading').prop('disabled', false);
                },
            });
        },
    };

    /* =================================================================
     * Language Management
     * ================================================================= */

    var LanguageForm = {
        init: function () {
            this.$form = $('#maharat-add-language-form');
            if (!this.$form.length) {
                return;
            }

            this.bindEvents();
        },

        bindEvents: function () {
            this.$form.on('submit', this.handleSubmit.bind(this));

            // Toggle language active/inactive.
            $(document).on('click', '.maharat-toggle-language', this.handleToggle.bind(this));

            // Delete language.
            $(document).on('click', '.maharat-delete-language', this.handleDelete.bind(this));
        },

        handleSubmit: function (e) {
            e.preventDefault();
            var $form = $(e.currentTarget);
            var data = {};

            $form.serializeArray().forEach(function (field) {
                data[field.name] = field.value;
            });

            $.ajax({
                url: maharat.restUrl + 'maharat/v1/languages',
                method: 'POST',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', maharat.nonce);
                },
                data: JSON.stringify(data),
                contentType: 'application/json',
                success: function () {
                    window.location.reload();
                },
                error: function (xhr) {
                    var msg = xhr.responseJSON && xhr.responseJSON.message
                        ? xhr.responseJSON.message
                        : maharat.i18n.addLanguageError;
                    alert(msg);
                },
            });
        },

        handleToggle: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var code = $btn.data('code');
            var active = $btn.data('active') ? 0 : 1;

            $.ajax({
                url: maharat.restUrl + 'maharat/v1/languages/' + code,
                method: 'PUT',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', maharat.nonce);
                },
                data: JSON.stringify({ is_active: active }),
                contentType: 'application/json',
                success: function () {
                    window.location.reload();
                },
            });
        },

        handleDelete: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var code = $btn.data('code');

            if (!confirm(maharat.i18n.confirmDelete)) {
                return;
            }

            $.ajax({
                url: maharat.restUrl + 'maharat/v1/languages/' + code,
                method: 'DELETE',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', maharat.nonce);
                },
                success: function () {
                    window.location.reload();
                },
                error: function (xhr) {
                    var msg = xhr.responseJSON && xhr.responseJSON.message
                        ? xhr.responseJSON.message
                        : maharat.i18n.deleteError;
                    alert(msg);
                },
            });
        },
    };

    /* =================================================================
     * String Translation Inline Edit
     * ================================================================= */

    var StringTranslation = {
        saveTimeout: null,

        init: function () {
            this.$table = $('.maharat-string-table');
            if (!this.$table.length) {
                return;
            }

            this.bindEvents();
        },

        bindEvents: function () {
            this.$table.on('input', '.maharat-translation-input', this.handleInput.bind(this));
        },

        handleInput: function (e) {
            var $input = $(e.currentTarget);

            // Debounce saves.
            clearTimeout(this.saveTimeout);
            this.saveTimeout = setTimeout(function () {
                StringTranslation.saveString($input);
            }, 800);
        },

        saveString: function ($input) {
            var original = $input.data('original');
            var lang = $input.data('lang');
            var domain = $input.data('domain');
            var value = $input.val();

            $input.css('border-color', '#dba617');

            $.ajax({
                url: maharat.restUrl + 'maharat/v1/strings',
                method: 'POST',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', maharat.nonce);
                },
                data: JSON.stringify({
                    original: original,
                    language: lang,
                    translation: value,
                    domain: domain,
                }),
                contentType: 'application/json',
                success: function () {
                    $input.css('border-color', '#00a32a');
                    setTimeout(function () {
                        $input.css('border-color', '');
                    }, 2000);
                },
                error: function () {
                    $input.css('border-color', '#d63638');
                },
            });
        },
    };

    /* =================================================================
     * Bulk Operations
     * ================================================================= */

    var BulkOps = {
        init: function () {
            $(document).on('click', '.maharat-bulk-translate', this.handleBulkTranslate.bind(this));
        },

        handleBulkTranslate: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var targetLang = $btn.data('target-lang');
            var postType = $btn.data('post-type') || 'post';

            if (!confirm(maharat.i18n.confirmBulk)) {
                return;
            }

            $btn.prop('disabled', true).text(maharat.i18n.translating);

            $.ajax({
                url: maharat.restUrl + 'maharat/v1/auto-translate/bulk',
                method: 'POST',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', maharat.nonce);
                },
                data: JSON.stringify({
                    target_language: targetLang,
                    post_type: postType,
                }),
                contentType: 'application/json',
                success: function (response) {
                    alert(
                        maharat.i18n.bulkComplete
                            .replace('%d', response.translated || 0)
                    );
                    window.location.reload();
                },
                error: function (xhr) {
                    var msg = xhr.responseJSON && xhr.responseJSON.message
                        ? xhr.responseJSON.message
                        : maharat.i18n.bulkError;
                    alert(msg);
                },
                complete: function () {
                    $btn.prop('disabled', false).text(maharat.i18n.bulkTranslate);
                },
            });
        },
    };

    /* =================================================================
     * Settings Page Tabs
     * ================================================================= */

    var Tabs = {
        init: function () {
            var $tabs = $('.maharat-tabs .maharat-tab');
            if (!$tabs.length) {
                return;
            }

            $tabs.on('click', function () {
                var target = $(this).data('tab');

                $tabs.removeClass('maharat-tab--active');
                $(this).addClass('maharat-tab--active');

                $('.maharat-tab-content').hide();
                $('#maharat-tab-' + target).show();
            });

            // Activate first tab.
            $tabs.first().trigger('click');
        },
    };

    /* =================================================================
     * Initialise
     * ================================================================= */

    $(document).ready(function () {
        MetaBox.init();
        LanguageForm.init();
        StringTranslation.init();
        BulkOps.init();
        Tabs.init();
    });

})(jQuery);
