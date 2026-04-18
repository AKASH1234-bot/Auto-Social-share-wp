/* global wpap, jQuery */
(function ($) {
    'use strict';

    // ─── Tab System ───────────────────────────────────────────────────────────
    $(document).on('click', '.wpap-tab-link', function (e) {
        e.preventDefault();
        var target = $(this).attr('href');
        $('.wpap-tab-link').removeClass('active');
        $(this).addClass('active');
        $('.wpap-tab-content').removeClass('active');
        $(target).addClass('active');
        // Persist tab in sessionStorage.
        try { sessionStorage.setItem('wpap_active_tab', target); } catch (ex) {}
    });

    // Restore last tab.
    (function () {
        try {
            var saved = sessionStorage.getItem('wpap_active_tab');
            if (saved && $(saved).length) {
                $('[href="' + saved + '"]').trigger('click');
            }
        } catch (ex) {}
    })();

    // ─── URL Shortener Toggle ─────────────────────────────────────────────────
    $('[name="url_shortener"]').on('change', function () {
        var val = $(this).val();
        $('.wpap-bitly-row').toggle(val === 'bitly');
        $('.wpap-yourls-row').toggle(val === 'yourls');
    });

    // ─── Queue Table (AJAX) ───────────────────────────────────────────────────
    var queuePage = 1;

    function loadQueue(page) {
        queuePage = page || 1;
        var status = $('#wpap-queue-filter').val();
        var $tbody = $('#wpap-queue-tbody');

        $tbody.html('<tr><td colspan="8" style="text-align:center;padding:20px;"><span class="spinner is-active" style="float:none;"></span></td></tr>');

        $.post(wpap.ajax_url, {
            action: 'wpap_get_queue',
            nonce:  wpap.nonce,
            status: status,
            page:   queuePage
        }, function (res) {
            if (!res.success) { $tbody.html('<tr><td colspan="8">Error loading queue.</td></tr>'); return; }
            var jobs  = res.data.jobs;
            var html  = '';

            if (!jobs || !jobs.length) {
                html = '<tr><td colspan="8" style="text-align:center;color:#999;">No jobs found.</td></tr>';
            } else {
                jobs.forEach(function (j) {
                    var statusBadge = '<span class="wpap-level-badge wpap-level-' + statusClass(j.status) + '">' + j.status.toUpperCase() + '</span>';
                    var actions = '';
                    if (j.status === 'failed' || j.status === 'pending') {
                        actions += '<button class="button button-small wpap-retry-job" data-id="' + j.id + '" style="margin-right:4px;">↻ Retry</button>';
                    }
                    actions += '<button class="button button-small wpap-delete-job" data-id="' + j.id + '">✕</button>';

                    html += '<tr>' +
                        '<td>' + j.id + '</td>' +
                        '<td><a href="post.php?post=' + j.post_id + '&action=edit" target="_blank">' + escHtml(j.post_title || '#' + j.post_id) + '</a></td>' +
                        '<td><span class="wpap-platform-badge wpap-badge-' + j.platform + '">' + j.platform + '</span></td>' +
                        '<td>' + statusBadge + '</td>' +
                        '<td>' + formatDate(j.scheduled_at) + '</td>' +
                        '<td>' + j.attempts + '</td>' +
                        '<td><small style="color:#dc2626;">' + escHtml(j.error_msg || '') + '</small></td>' +
                        '<td>' + actions + '</td>' +
                        '</tr>';
                });
            }

            $tbody.html(html);

            // Pagination.
            var pages = res.data.pages;
            var $pager = $('#wpap-queue-pagination');
            $pager.empty();
            if (pages > 1) {
                for (var p = 1; p <= pages; p++) {
                    $pager.append('<button class="button wpap-page-btn' + (p === queuePage ? ' button-primary' : '') + '" data-page="' + p + '">' + p + '</button> ');
                }
            }
        });
    }

    function statusClass(s) {
        return { done: 'success', failed: 'error', warning: 'warning', pending: 'info', processing: 'info', skipped: 'warning' }[s] || 'info';
    }

    function formatDate(d) { return d ? d.replace('T', ' ').substr(0, 16) : '—'; }
    function escHtml(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    // Init queue page.
    if ($('#wpap-queue-table').length) {
        loadQueue(1);
    }

    $(document).on('change', '#wpap-queue-filter', function () { loadQueue(1); });
    $(document).on('click', '#wpap-refresh-queue', function () { loadQueue(queuePage); });
    $(document).on('click', '.wpap-page-btn', function () { loadQueue(parseInt($(this).data('page'))); });

    // Retry job.
    $(document).on('click', '.wpap-retry-job', function () {
        var $btn = $(this).prop('disabled', true).text(wpap.i18n.retrying);
        $.post(wpap.ajax_url, { action: 'wpap_retry_job', nonce: wpap.nonce, job_id: $(this).data('id') }, function () {
            loadQueue(queuePage);
        }).fail(function () { $btn.prop('disabled', false).text('↻ Retry'); });
    });

    // Delete job.
    $(document).on('click', '.wpap-delete-job', function () {
        if (!confirm(wpap.i18n.confirm_del)) return;
        $.post(wpap.ajax_url, { action: 'wpap_delete_job', nonce: wpap.nonce, job_id: $(this).data('id') }, function () {
            loadQueue(queuePage);
        });
    });

    // ─── Quora Panel ──────────────────────────────────────────────────────────
    $(document).on('click', '.wpap-quora-generate', function () {
        var postId  = $(this).data('post-id');
        var $panel  = $(this).closest('.wpap-quora-panel');
        var $content = $panel.find('.wpap-quora-content');
        var $loading = $panel.find('.wpap-quora-loading');
        var $error   = $panel.find('.wpap-quora-error');

        $content.hide();
        $error.hide();
        $loading.show();

        $.post(wpap.ajax_url, {
            action:  'wpap_quora_generate',
            nonce:   wpap.nonce,
            post_id: postId
        }, function (res) {
            $loading.hide();
            if (!res.success) {
                $error.text(res.data || 'Generation failed.').show();
                return;
            }
            var d = res.data;
            $panel.find('.wpap-quora-hook').text(d.hook);
            $panel.find('.wpap-quora-value_answer').text(d.value_answer);
            $panel.find('.wpap-quora-cta').text(d.cta);

            var $ul = $panel.find('.wpap-quora-suggestions').empty();
            (d.question_suggestions || []).forEach(function (q) {
                $ul.append($('<li>').text(q));
            });

            $panel.find('.wpap-quora-full-text').val(d.full_formatted_answer);
            $panel.find('.wpap-word-count').text(d.word_count + ' words');
            $content.show();
        }).fail(function () {
            $loading.hide();
            $error.text('Network error. Please try again.').show();
        });
    });

    // Copy individual sections.
    $(document).on('click', '.wpap-copy-btn', function () {
        var target = $(this).data('target');
        var $panel  = $(this).closest('.wpap-quora-panel');
        var text    = $panel.find('.wpap-quora-' + target).text();
        copyToClipboard(text, $(this));
    });

    // Copy full answer.
    $(document).on('click', '.wpap-copy-full', function () {
        var text = $(this).closest('.wpap-quora-section').find('.wpap-quora-full-text').val();
        copyToClipboard(text, $(this));
    });

    function copyToClipboard(text, $btn) {
        if (!text) return;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () { flashBtn($btn); });
        } else {
            var $ta = $('<textarea style="position:fixed;top:-1000px">').val(text).appendTo('body');
            $ta[0].select();
            document.execCommand('copy');
            $ta.remove();
            flashBtn($btn);
        }
    }

    function flashBtn($btn) {
        var orig = $btn.text();
        $btn.text(wpap.i18n.copied).css('color', '#16a34a');
        setTimeout(function () { $btn.text(orig).css('color', ''); }, 2000);
    }

    // ─── AI Hook Generator ─────────────────────────────────────────────────────
    $(document).on('click', '.wpap-generate-hooks', function () {
        var postId = $(this).data('post-id');
        var $wrap  = $(this).siblings('.wpap-hooks-wrap');
        $(this).prop('disabled', true).text(wpap.i18n.generating);
        var $btn = $(this);

        $.post(wpap.ajax_url, { action: 'wpap_generate_hooks', nonce: wpap.nonce, post_id: postId }, function (res) {
            $btn.prop('disabled', false).text('✨ Generate Hooks');
            if (!res.success || !res.data.hooks) return;
            $wrap.empty();
            res.data.hooks.forEach(function (hook) {
                var $item = $('<div class="wpap-hook-item" style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">');
                $item.append($('<span style="flex:1;font-size:12px;">').text(hook));
                $item.append($('<button class="button button-small wpap-copy-hook">📋</button>').on('click', function () { copyToClipboard(hook, $(this)); }));
                $wrap.append($item);
            });
        }).fail(function () { $btn.prop('disabled', false).text('✨ Generate Hooks'); });
    });

})(jQuery);
