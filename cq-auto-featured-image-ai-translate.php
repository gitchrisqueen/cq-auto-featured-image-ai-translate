<?php
/**
 * Plugin Name: CQ Auto Featured Image + AI Translate
 * Description: Full multilingual content repair tool with background queue processing for featured images, Polylang relationships, missing translations, and OpenAI translation automation.
 * Version: 1.11.2
 * Author: Christopher Queen / ChatGPT
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: false
 */

if (!defined('ABSPATH')) {
    exit;
}

final class CQ_Auto_Featured_Image_AI_Translate {
    const OPTION_API_KEY = 'cq_afi_openai_api_key';
    const OPTION_MODEL = 'cq_afi_openai_model';
    const OPTION_CUSTOM_MODEL = 'cq_afi_openai_custom_model';
    const OPTION_USE_CUSTOM_MODEL = 'cq_afi_use_custom_model';
    const OPTION_MODEL_VALIDATION = 'cq_afi_model_validation';
    const OPTION_SOURCE_LANG = 'cq_afi_source_lang';
    const OPTION_MIN_IMAGE_SCORE = 'cq_afi_min_image_score';
    const OPTION_BATCH_SIZE = 'cq_afi_batch_size';
    const OPTION_JOB_STATUS = 'cq_afi_job_status';
    const OPTION_MANUAL_QUEUE = 'cq_afi_manual_queue';
    const OPTION_LOGS = 'cq_afi_logs';
    const OPTION_REPAIR_REPORT = 'cq_afi_repair_report';
    const OPTION_REPAIR_QUEUE = 'cq_afi_repair_queue';
    const OPTION_REPAIR_SETTINGS = 'cq_afi_repair_settings';
    const OPTION_BACKGROUND_STATUS = 'cq_afi_background_status';
    const OPTION_RETRY_QUEUE = 'cq_afi_retry_queue';
    const OPTION_AUDIT_STATUS = 'cq_afi_audit_status';
    const OPTION_AUDIT_AGGREGATE = 'cq_afi_audit_aggregate';
    const OPTION_AUDIT_ITEMS = 'cq_afi_audit_items';

    const BACKGROUND_EVENT = 'cq_afi_background_repair_worker';
    const AS_BACKGROUND_HOOK = 'cq_afi_as_background_repair_worker';
    const AUDIT_EVENT = 'cq_afi_background_audit_worker';
    const AS_AUDIT_HOOK = 'cq_afi_as_background_audit_worker';
    const AUDIT_BATCH_SIZE = 10;
    const AUDIT_CHECKPOINT_EVERY = 2;

    const DEFAULT_MODEL = 'gpt-5.4-nano';
    const MAX_LOGS = 300;

    const META_LOCK = '_cq_afi_processing_lock';
    const META_ATTEMPTED = '_cq_afi_attempted';
    const META_IMAGE_SCORE = '_cq_afi_image_match_score';
    const META_IMAGE_REASON = '_cq_afi_image_match_reason';
    const META_IMAGE_NO_MATCH = '_cq_afi_image_no_match';
    const META_IMAGE_NO_MATCH_SCORE = '_cq_afi_image_no_match_score';
    const META_TRANSLATION_FAILED = '_cq_afi_translation_failed';
    const META_TRANSLATION_FAILED_REASON = '_cq_afi_translation_failed_reason';
    const META_POLYLANG_RELINK_ATTEMPTED = '_cq_afi_polylang_relink_attempted';
    const META_POLYLANG_RELINK_SCORE = '_cq_afi_polylang_relink_score';
    const META_AI_TRANSLATION = '_cq_afi_ai_translation_generated';
    const META_SOURCE_POST = '_cq_afi_source_post_id';
    const META_ORIGINAL_POST_DATE = '_cq_afi_original_post_date';
    const META_ORIGINAL_POST_DATE_GMT = '_cq_afi_original_post_date_gmt';

    const SINGLE_EVENT = 'cq_afi_process_post_after_publish';
    const BACKFILL_EVENT = 'cq_afi_hourly_backfill';
    const MANUAL_EVENT = 'cq_afi_manual_run';
    const RETRANSLATE_EVENT = 'cq_afi_retranslate_post_event';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_init', [__CLASS__, 'handle_admin_actions']);
        add_action('admin_init', [__CLASS__, 'handle_log_export']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_enqueue_scripts']);

        add_action('wp_ajax_cq_afi_get_status', [__CLASS__, 'ajax_get_status']);
        add_action('wp_ajax_cq_afi_run_now', [__CLASS__, 'ajax_run_now']);
        add_action('wp_ajax_cq_afi_process_manual_queue', [__CLASS__, 'ajax_process_manual_queue']);
        add_action('wp_ajax_cq_afi_validate_model', [__CLASS__, 'ajax_validate_model']);
        add_action('wp_ajax_cq_afi_clear_logs', [__CLASS__, 'ajax_clear_logs']);
        add_action('wp_ajax_cq_afi_run_full_repair_scan', [__CLASS__, 'ajax_run_full_repair_scan']);
        add_action('wp_ajax_cq_afi_process_repair_queue', [__CLASS__, 'ajax_process_repair_queue']);
        add_action('wp_ajax_cq_afi_start_background_repair', [__CLASS__, 'ajax_start_background_repair']);
        add_action('wp_ajax_cq_afi_pause_background_repair', [__CLASS__, 'ajax_pause_background_repair']);
        add_action('wp_ajax_cq_afi_resume_background_repair', [__CLASS__, 'ajax_resume_background_repair']);
        add_action('wp_ajax_cq_afi_cancel_background_repair', [__CLASS__, 'ajax_cancel_background_repair']);
        add_action('wp_ajax_cq_afi_retry_background_errors', [__CLASS__, 'ajax_retry_background_errors']);
        add_action('wp_ajax_cq_afi_start_background_audit', [__CLASS__, 'ajax_start_background_audit']);
        add_action('wp_ajax_cq_afi_start_audit_and_repair', [__CLASS__, 'ajax_start_audit_and_repair']);
        add_action('wp_ajax_cq_afi_pause_all', [__CLASS__, 'ajax_pause_all']);
        add_action('wp_ajax_cq_afi_resume_all', [__CLASS__, 'ajax_resume_all']);
        add_action('wp_ajax_cq_afi_cancel_all', [__CLASS__, 'ajax_cancel_all']);
        add_action('wp_ajax_cq_afi_pause_background_audit', [__CLASS__, 'ajax_pause_background_audit']);
        add_action('wp_ajax_cq_afi_resume_background_audit', [__CLASS__, 'ajax_resume_background_audit']);
        add_action('wp_ajax_cq_afi_cancel_background_audit', [__CLASS__, 'ajax_cancel_background_audit']);
        add_action('wp_ajax_cq_afi_kick_background_audit', [__CLASS__, 'ajax_kick_background_audit']);
        add_action('wp_ajax_cq_afi_process_audit_batch_now', [__CLASS__, 'ajax_process_audit_batch_now']);
        add_action('wp_ajax_cq_afi_restore_translation_dates', [__CLASS__, 'ajax_restore_translation_dates']);

        add_action(self::BACKGROUND_EVENT, [__CLASS__, 'background_repair_worker']);
        add_action(self::AS_BACKGROUND_HOOK, [__CLASS__, 'background_repair_worker']);
        add_action(self::AUDIT_EVENT, [__CLASS__, 'background_audit_worker']);
        add_action(self::AS_AUDIT_HOOK, [__CLASS__, 'background_audit_worker']);

        add_action('transition_post_status', [__CLASS__, 'on_transition_post_status'], 10, 3);
        add_action(self::SINGLE_EVENT, [__CLASS__, 'process_post'], 10, 1);
        add_action(self::BACKFILL_EVENT, [__CLASS__, 'run_backfill']);
        add_action(self::MANUAL_EVENT, [__CLASS__, 'run_manual_job']);

        // Public integration hook: other plugins fire do_action('cq_afi_retranslate_post', $source_post_id)
        // when a source-language post's content changes, to refresh existing translations.
        add_action('cq_afi_retranslate_post', [__CLASS__, 'schedule_retranslate'], 10, 1);
        add_action(self::RETRANSLATE_EVENT, [__CLASS__, 'retranslate_existing_translations'], 10, 1);
    }

    public static function activate(): void {
        self::ensure_defaults();

        if (!wp_next_scheduled(self::BACKFILL_EVENT)) {
            wp_schedule_event(time() + 300, 'hourly', self::BACKFILL_EVENT);
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::BACKFILL_EVENT);
        wp_clear_scheduled_hook(self::SINGLE_EVENT);
        wp_clear_scheduled_hook(self::MANUAL_EVENT);
        wp_clear_scheduled_hook(self::BACKGROUND_EVENT);
        wp_clear_scheduled_hook(self::AUDIT_EVENT);
        wp_clear_scheduled_hook(self::RETRANSLATE_EVENT);
    }

    public static function ensure_defaults(): void {
        if (!get_option(self::OPTION_MODEL)) {
            update_option(self::OPTION_MODEL, self::DEFAULT_MODEL);
        }

        if (get_option(self::OPTION_USE_CUSTOM_MODEL, null) === null) {
            update_option(self::OPTION_USE_CUSTOM_MODEL, 0);
        }

        if (!get_option(self::OPTION_SOURCE_LANG)) {
            update_option(self::OPTION_SOURCE_LANG, 'en');
        }

        if (!get_option(self::OPTION_MIN_IMAGE_SCORE)) {
            update_option(self::OPTION_MIN_IMAGE_SCORE, 45);
        }

        if (!get_option(self::OPTION_BATCH_SIZE)) {
            update_option(self::OPTION_BATCH_SIZE, 10);
        }

        if (!get_option(self::OPTION_JOB_STATUS)) {
            update_option(self::OPTION_JOB_STATUS, self::default_job_status());
        }

        if (!get_option(self::OPTION_BACKGROUND_STATUS)) {
            update_option(self::OPTION_BACKGROUND_STATUS, self::default_background_status());
        }

        if (!get_option(self::OPTION_AUDIT_STATUS)) {
            update_option(self::OPTION_AUDIT_STATUS, self::default_audit_status());
        }
    }

    public static function hardcoded_translation_models(): array {
        return [
            'gpt-5.4-nano' => 'gpt-5.4-nano, cheapest/smallest default',
            'gpt-5.4-mini' => 'gpt-5.4-mini, stronger small model',
            'gpt-5.4' => 'gpt-5.4, stronger general model',
            'gpt-5.5' => 'gpt-5.5, strongest current flagship',
            'gpt-4.1-nano' => 'gpt-4.1-nano, legacy low-cost fallback',
            'gpt-4.1-mini' => 'gpt-4.1-mini, legacy balanced fallback',
            'gpt-4.1' => 'gpt-4.1, legacy high-quality fallback',
            'gpt-4o-mini' => 'gpt-4o-mini, older low-cost fallback',
        ];
    }

    public static function admin_menu(): void {
        add_options_page(
            'CQ Auto Image + Translate',
            'CQ Auto Image + Translate',
            'manage_options',
            'cq-auto-featured-image-ai-translate',
            [__CLASS__, 'settings_page']
        );
    }

    public static function register_settings(): void {
        register_setting('cq_afi_settings', self::OPTION_API_KEY, [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_api_key'],
            'default' => '',
        ]);

        register_setting('cq_afi_settings', self::OPTION_MODEL, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => self::DEFAULT_MODEL,
        ]);

        register_setting('cq_afi_settings', self::OPTION_CUSTOM_MODEL, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('cq_afi_settings', self::OPTION_USE_CUSTOM_MODEL, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0,
        ]);

        register_setting('cq_afi_settings', self::OPTION_SOURCE_LANG, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_key',
            'default' => 'en',
        ]);

        register_setting('cq_afi_settings', self::OPTION_MIN_IMAGE_SCORE, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 45,
        ]);

        register_setting('cq_afi_settings', self::OPTION_BATCH_SIZE, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 10,
        ]);
    }

    public static function sanitize_api_key($value): string {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return (string) get_option(self::OPTION_API_KEY, '');
        }

        return sanitize_text_field($value);
    }

    public static function handle_log_export(): void {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (empty($_GET['cq_afi_export_logs'])) {
            return;
        }

        check_admin_referer('cq_afi_export_logs_action', 'cq_afi_export_logs_nonce');

        $mode = isset($_GET['cq_afi_export_mode']) ? sanitize_key((string) $_GET['cq_afi_export_mode']) : 'all';
        $days = isset($_GET['cq_afi_export_days']) ? max(1, min(3650, absint($_GET['cq_afi_export_days']))) : 7;
        $start = isset($_GET['cq_afi_export_start']) ? sanitize_text_field(wp_unslash((string) $_GET['cq_afi_export_start'])) : '';
        $end = isset($_GET['cq_afi_export_end']) ? sanitize_text_field(wp_unslash((string) $_GET['cq_afi_export_end'])) : '';

        $logs = self::filter_logs_for_export(self::get_logs(), $mode, $days, $start, $end);

        $filename = 'cq-afi-logs-' . gmdate('Y-m-d-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['time', 'level', 'post_id', 'post_title', 'message', 'context']);

        foreach ($logs as $log) {
            $post_id = (int) ($log['post_id'] ?? 0);
            $post_title = $post_id ? get_the_title($post_id) : '';
            $context = !empty($log['context']) ? wp_json_encode($log['context']) : '';

            fputcsv($out, [
                $log['time'] ?? '',
                $log['level'] ?? '',
                $post_id ?: '',
                $post_title,
                $log['message'] ?? '',
                $context,
            ]);
        }

        fclose($out);
        exit;
    }

    private static function filter_logs_for_export(array $logs, string $mode, int $days, string $start, string $end): array {
        if ($mode === 'all') {
            return $logs;
        }

        $start_ts = null;
        $end_ts = null;

        if ($mode === 'days') {
            $start_ts = strtotime('-' . $days . ' days', current_time('timestamp'));
            $end_ts = current_time('timestamp');
        } elseif ($mode === 'range') {
            if ($start) {
                $start_ts = strtotime($start . ' 00:00:00');
            }

            if ($end) {
                $end_ts = strtotime($end . ' 23:59:59');
            }
        }

        return array_values(array_filter($logs, function ($log) use ($start_ts, $end_ts) {
            $time = isset($log['time']) ? strtotime((string) $log['time']) : false;

            if (!$time) {
                return false;
            }

            if ($start_ts !== null && $time < $start_ts) {
                return false;
            }

            if ($end_ts !== null && $time > $end_ts) {
                return false;
            }

            return true;
        }));
    }

    public static function handle_admin_actions(): void {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (!empty($_POST['cq_afi_emergency_audit_batch_submit'])) {
            check_admin_referer('cq_afi_emergency_audit_batch_action', 'cq_afi_emergency_audit_batch_nonce');
            self::log('warning', 'Emergency non-AJAX audit batch form submitted.');
            self::background_audit_worker();
            add_settings_error('cq_afi_settings', 'cq_afi_emergency_audit_batch_done', 'Emergency audit batch attempted. Check Background Site Audit status and logs.', 'updated');
        }

        if (!empty($_POST['cq_afi_validate_model_submit'])) {
            check_admin_referer('cq_afi_validate_model_action', 'cq_afi_validate_model_nonce');
            $result = self::validate_selected_model();

            if (is_wp_error($result)) {
                add_settings_error('cq_afi_settings', 'cq_afi_model_invalid', esc_html($result->get_error_message()), 'error');
            } else {
                add_settings_error('cq_afi_settings', 'cq_afi_model_valid', 'Selected model validated successfully against OpenAI.', 'success');
            }
        }

        if (!empty($_POST['cq_afi_run_now_submit'])) {
            check_admin_referer('cq_afi_run_now_action', 'cq_afi_run_now_nonce');
            self::schedule_manual_run();
            add_settings_error('cq_afi_settings', 'cq_afi_run_queued', 'Manual processing job queued. Check back here for progress.', 'success');
        }
    }

    public static function admin_enqueue_scripts(string $hook): void {
        if ($hook !== 'settings_page_cq-auto-featured-image-ai-translate') {
            return;
        }

        wp_enqueue_script('jquery');

        $nonce = wp_create_nonce('cq_afi_ajax_nonce');

        $script = "
        jQuery(function($) {
            const ajaxUrl = ajaxurl;
            const nonce = " . wp_json_encode($nonce) . ";

            function renderStatus(data) {
                if (!data || !data.success) return;
                const s = data.data;

                $('#cq-afi-missing-featured-images').text(s.counts.posts_without_featured_images);
                $('#cq-afi-posts-without-alt').text(s.counts.posts_without_translated_alternatives);
                $('#cq-afi-missing-language-total').text(s.counts.total_missing_language_versions);
                $('#cq-afi-lang-breakdown').html(s.counts.missing_by_language_html);

                $('#cq-afi-job-state').text(s.job.state);
                $('#cq-afi-job-started').text(s.job.started_at || 'Not started');
                $('#cq-afi-job-finished').text(s.job.finished_at || 'Not finished');
                $('#cq-afi-job-processed').text(s.job.processed || '0');
                $('#cq-afi-job-message').text(s.job.message || '');
                $('#cq-afi-job-images-set').text(s.job.images_set || '0');
                $('#cq-afi-job-image-no-match').text(s.job.image_no_match || '0');
                $('#cq-afi-job-translations-created').text(s.job.translations_created || '0');
                $('#cq-afi-job-translation-failed').text(s.job.translation_failed || '0');
                $('#cq-afi-job-errors').text(s.job.errors || '0');

                if (s.logs_html) {
                    $('#cq-afi-log-table-wrap').html(s.logs_html);
                }

                if (s.repair_report_html) {
                    $('#cq-afi-repair-report-wrap').html(s.repair_report_html);
                }

                if (s.background_status_html) {
                    $('#cq-afi-background-status-wrap').html(s.background_status_html);
                }

                if (s.audit_status_html) {
                    $('#cq-afi-audit-status-wrap').html(s.audit_status_html);
                }

                if (s.job.state === 'running' || s.job.state === 'queued') {
                    $('#cq-afi-progress-wrap').show();
                    const pct = s.job.total > 0 ? Math.round((s.job.processed / s.job.total) * 100) : 0;
                    $('#cq-afi-progress-bar').css('width', pct + '%').text(pct + '%');
                } else {
                    $('#cq-afi-progress-wrap').hide();
                }

                if (s.validation && s.validation.message) {
                    $('#cq-afi-model-validation-status').text(s.validation.message);
                }
            }

            let cqAfiManualRunning = false;

            function pollStatus() {
                $.post(ajaxUrl, { action: 'cq_afi_get_status', nonce: nonce }, function(data) {
                    renderStatus(data);
                    if (data && data.success && data.data && data.data.job && data.data.job.state === 'running' && data.data.job.mode === 'manual-browser') {
                        processManualQueue();
                    }
                });
            }

            function processManualQueue() {
                if (cqAfiManualRunning) return;
                cqAfiManualRunning = true;

                $.post(ajaxUrl, { action: 'cq_afi_process_manual_queue', nonce: nonce }, function(data) {
                    renderStatus(data);
                    cqAfiManualRunning = false;

                    if (data && data.success && data.data && data.data.job && data.data.job.state === 'running') {
                        setTimeout(processManualQueue, 800);
                    }
                }).fail(function() {
                    cqAfiManualRunning = false;
                    setTimeout(pollStatus, 5000);
                });
            }

            $('#cq-afi-run-now-ajax').on('click', function(e) {
                e.preventDefault();
                const btn = $(this);
                btn.prop('disabled', true).text('Starting...');
                $.post(ajaxUrl, { action: 'cq_afi_run_now', nonce: nonce }, function(data) {
                    renderStatus(data);
                    btn.prop('disabled', false).text('Run Process Now');
                    processManualQueue();
                });
            });

            $('#cq-afi-clear-logs-ajax').on('click', function(e) {
                e.preventDefault();
                if (!confirm('Clear the plugin log?')) return;
                $.post(ajaxUrl, { action: 'cq_afi_clear_logs', nonce: nonce }, renderStatus);
            });

            $('#cq-afi-start-audit-and-repair').on('click', function(e) {
                e.preventDefault();
                $.post(ajaxUrl, { action: 'cq_afi_start_audit_and_repair', nonce: nonce }, function(data) {
                    renderStatus(data);
                    alert('Audit started. Featured images, translation relinks, and missing translations will be repaired automatically when the audit completes. You may leave this page.');
                });
            });

            $('#cq-afi-pause-all').on('click', function(e) {
                e.preventDefault();
                $.post(ajaxUrl, { action: 'cq_afi_pause_all', nonce: nonce }, function(data) {
                    renderStatus(data);
                    alert('Paused.');
                });
            });

            $('#cq-afi-resume-all').on('click', function(e) {
                e.preventDefault();
                $.post(ajaxUrl, { action: 'cq_afi_resume_all', nonce: nonce }, function(data) {
                    renderStatus(data);
                    alert('Resumed.');
                });
            });

            $('#cq-afi-cancel-all').on('click', function(e) {
                e.preventDefault();
                if (!confirm('Cancel the running audit and repair? Remaining queued repair items will be cleared.')) return;
                $.post(ajaxUrl, { action: 'cq_afi_cancel_all', nonce: nonce }, function(data) {
                    renderStatus(data);
                    alert('Cancelled.');
                });
            });

            $('#cq-afi-retry-background-errors').on('click', function(e) {
                e.preventDefault();
                $.post(ajaxUrl, { action: 'cq_afi_retry_background_errors', nonce: nonce }, function(data) {
                    renderStatus(data);
                    alert('Retryable error items were requeued.');
                });
            });

            $('#cq-afi-validate-model-ajax').on('click', function(e) {
                e.preventDefault();
                const btn = $(this);
                btn.prop('disabled', true).text('Validating...');
                $.post(ajaxUrl, { action: 'cq_afi_validate_model', nonce: nonce }, function(data) {
                    renderStatus(data);
                    btn.prop('disabled', false).text('Validate Selected Model');
                    if (data && data.data && data.data.validation) {
                        alert(data.data.validation.message);
                    }
                });
            });

            pollStatus();
            setInterval(pollStatus, 15000);
        });
        ";

        wp_add_inline_script('jquery', $script);
    }

    public static function settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        self::ensure_defaults();

        $api_key = (string) get_option(self::OPTION_API_KEY, '');
        $selected_model = (string) get_option(self::OPTION_MODEL, self::DEFAULT_MODEL);
        $custom_model = (string) get_option(self::OPTION_CUSTOM_MODEL, '');
        $use_custom = (int) get_option(self::OPTION_USE_CUSTOM_MODEL, 0);
        $counts = self::get_dashboard_counts();
        $job = self::get_job_status();
        $validation = get_option(self::OPTION_MODEL_VALIDATION, []);

        ?>
        <div class="wrap">
            <h1>CQ Auto Featured Image + AI Translate</h1>

            <?php settings_errors('cq_afi_settings'); ?>

            <p>
                This plugin finds posts without featured images, matches images from the Media Library by title/filename similarity,
                sets featured images, and creates missing Polylang translations using OpenAI.
            </p>

            <style>
                .cq-afi-cards { display: flex; flex-wrap: wrap; gap: 16px; margin: 20px 0; }
                .cq-afi-card { background: #fff; border: 1px solid #ccd0d4; padding: 16px; min-width: 220px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
                .cq-afi-card h2 { margin-top: 0; font-size: 14px; color: #50575e; }
                .cq-afi-big { font-size: 34px; font-weight: 700; line-height: 1.2; }
                .cq-afi-progress { width: 100%; background: #e5e5e5; border-radius: 4px; overflow: hidden; height: 24px; margin-top: 8px; display: none; }
                .cq-afi-progress-bar { width: 0%; height: 24px; line-height: 24px; text-align: center; background: #2271b1; color: #fff; font-size: 12px; }
                .cq-afi-muted { color: #646970; }
                .cq-afi-log-table { width: 100%; border-collapse: collapse; background: #fff; }
                .cq-afi-log-table th, .cq-afi-log-table td { border: 1px solid #ccd0d4; padding: 8px; vertical-align: top; }
                .cq-afi-log-table th { background: #f6f7f7; text-align: left; }
                .cq-afi-log-error { color: #b32d2e; font-weight: 600; }
                .cq-afi-log-warning { color: #996800; font-weight: 600; }
                .cq-afi-log-info { color: #2271b1; font-weight: 600; }
                .cq-afi-job-metrics span { display: inline-block; min-width: 28px; font-weight: 700; }
            </style>

            <h2>Processing Dashboard</h2>

            <div class="cq-afi-cards">
                <div class="cq-afi-card">
                    <h2>Posts Without Featured Images</h2>
                    <div class="cq-afi-big" id="cq-afi-missing-featured-images"><?php echo esc_html($counts['posts_without_featured_images']); ?></div>
                </div>

                <div class="cq-afi-card">
                    <h2>English Posts Without Any Translation</h2>
                    <div class="cq-afi-big" id="cq-afi-posts-without-alt"><?php echo esc_html($counts['posts_without_translated_alternatives']); ?></div>
                    <p class="cq-afi-muted">This focuses on posts that only exist in English.</p>
                </div>

                <div class="cq-afi-card">
                    <h2>Total Missing Language Versions</h2>
                    <div class="cq-afi-big" id="cq-afi-missing-language-total"><?php echo esc_html($counts['total_missing_language_versions']); ?></div>
                    <div id="cq-afi-lang-breakdown"><?php echo wp_kses_post($counts['missing_by_language_html']); ?></div>
                </div>

                <div class="cq-afi-card">
                    <h2>Known Issues</h2>
                    <p><strong>Image no-match:</strong> <?php echo esc_html(self::count_posts_with_meta(self::META_IMAGE_NO_MATCH)); ?></p>
                    <p><strong>Invalid/empty thumbnail meta:</strong> <?php echo esc_html(self::count_invalid_thumbnail_meta_posts()); ?></p>
                    <p><strong>Translation failures:</strong> <?php echo esc_html(self::count_posts_with_meta(self::META_TRANSLATION_FAILED)); ?></p>
                    <p><strong>Polylang relink attempts:</strong> <?php echo esc_html(self::count_posts_with_meta(self::META_POLYLANG_RELINK_ATTEMPTED)); ?></p>
                    <p><strong>Plugin/cron errors:</strong> <?php echo esc_html(self::count_logs_by_level('error')); ?></p>
                    <p class="cq-afi-muted">These explain why attempted jobs may not reduce the missing-image or missing-translation counts.</p>
                </div>

                <div class="cq-afi-card">
                    <h2>Current Job</h2>
                    <p><strong>Status:</strong> <span id="cq-afi-job-state"><?php echo esc_html($job['state']); ?></span></p>
                    <p><strong>Started:</strong> <span id="cq-afi-job-started"><?php echo esc_html($job['started_at'] ?: 'Not started'); ?></span></p>
                    <p><strong>Finished:</strong> <span id="cq-afi-job-finished"><?php echo esc_html($job['finished_at'] ?: 'Not finished'); ?></span></p>
                    <p><strong>Processed:</strong> <span id="cq-afi-job-processed"><?php echo esc_html($job['processed']); ?></span></p>
                    <div class="cq-afi-job-metrics">
                        <p><strong>Images set:</strong> <span id="cq-afi-job-images-set"><?php echo esc_html($job['images_set'] ?? 0); ?></span></p>
                        <p><strong>No image match:</strong> <span id="cq-afi-job-image-no-match"><?php echo esc_html($job['image_no_match'] ?? 0); ?></span></p>
                        <p><strong>Translations created:</strong> <span id="cq-afi-job-translations-created"><?php echo esc_html($job['translations_created'] ?? 0); ?></span></p>
                        <p><strong>Translation failed:</strong> <span id="cq-afi-job-translation-failed"><?php echo esc_html($job['translation_failed'] ?? 0); ?></span></p>
                        <p><strong>Errors:</strong> <span id="cq-afi-job-errors"><?php echo esc_html($job['errors'] ?? 0); ?></span></p>
                    </div>
                    <p id="cq-afi-job-message"><?php echo esc_html($job['message']); ?></p>
                    <div class="cq-afi-progress" id="cq-afi-progress-wrap">
                        <div class="cq-afi-progress-bar" id="cq-afi-progress-bar">0%</div>
                    </div>
                    <p>
                        <button class="button button-primary" id="cq-afi-run-now-ajax">Run Process Now</button>
                    </p>
                    <p class="cq-afi-muted">This queues the job immediately and returns control to the browser. The dashboard refreshes every 15 seconds. Manual runs process through this admin page and do not rely on WP-Cron, so keep this page open until finished.</p>
                </div>
            </div>

            <form method="post">
                <?php wp_nonce_field('cq_afi_run_now_action', 'cq_afi_run_now_nonce'); ?>
                <input type="hidden" name="cq_afi_run_now_submit" value="1" />
                <?php submit_button('Run Process Now Without JavaScript', 'secondary'); ?>
            </form>


            <hr />

            <h2>Full Content Library Repair</h2>
            <p class="cq-afi-muted">
                This scans the entire content library (not just newly published posts) and, in one pass, sets missing/invalid
                featured images, relinks language versions that lost their Polylang association, and creates any genuinely
                missing translations. Existing translations are relinked &mdash; never duplicated &mdash; using both the
                Polylang relationship and the plugin&rsquo;s own source-post record.
            </p>
            <p>
                <button class="button button-primary" id="cq-afi-start-audit-and-repair">Run Audit &amp; Repair</button>
                <button class="button" id="cq-afi-pause-all">Pause</button>
                <button class="button" id="cq-afi-resume-all">Resume</button>
                <button class="button" id="cq-afi-cancel-all">Cancel</button>
                <button class="button" id="cq-afi-retry-background-errors">Retry Errors</button>
            </p>
            <p class="cq-afi-muted">
                Runs in the background; you can leave this page. Pause/Resume/Cancel act on whichever phase
                (audit or repair) is currently running. After this completes, run the WP-CLI
                <code>tools/reorder-post-dates.php</code> tool to order posts by ID with translation groups sharing a date.
            </p>

            <div id="cq-afi-audit-status-wrap">
                <?php echo wp_kses_post(self::render_audit_status()); ?>
            </div>

            <div id="cq-afi-background-status-wrap">
                <?php echo wp_kses_post(self::render_background_status()); ?>
            </div>

            <div id="cq-afi-repair-report-wrap">
                <?php echo wp_kses_post(self::render_repair_report()); ?>
            </div>

            <hr />

            <h2>Recent Plugin Log</h2>
            <p class="cq-afi-muted">
                This captures missing image threshold failures, translation failures, model/API issues, cron/job issues, and processing outcomes.
            </p>
            <form method="get" style="background:#fff;border:1px solid #ccd0d4;padding:12px;margin:12px 0;">
                <input type="hidden" name="page" value="cq-auto-featured-image-ai-translate" />
                <input type="hidden" name="cq_afi_export_logs" value="1" />
                <?php wp_nonce_field('cq_afi_export_logs_action', 'cq_afi_export_logs_nonce'); ?>

                <strong>Export logs:</strong>

                <label style="margin-left:10px;">
                    <input type="radio" name="cq_afi_export_mode" value="all" checked />
                    All
                </label>

                <label style="margin-left:10px;">
                    <input type="radio" name="cq_afi_export_mode" value="days" />
                    Last
                    <input type="number" name="cq_afi_export_days" value="7" min="1" max="365" style="width:70px;" />
                    days
                </label>

                <label style="margin-left:10px;">
                    <input type="radio" name="cq_afi_export_mode" value="range" />
                    Date range
                    <input type="date" name="cq_afi_export_start" />
                    to
                    <input type="date" name="cq_afi_export_end" />
                </label>

                <?php submit_button('Export CSV', 'secondary', 'submit', false, ['style' => 'margin-left:10px;']); ?>
            </form>

            <p>
                <button class="button" id="cq-afi-clear-logs-ajax">Clear Logs</button>
            </p>
            <div id="cq-afi-log-table-wrap">
                <?php echo wp_kses_post(self::render_logs_table()); ?>
            </div>

            <hr />

            <h2>Settings</h2>

            <form method="post" action="options.php">
                <?php settings_fields('cq_afi_settings'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_API_KEY); ?>">OpenAI API Key</label></th>
                        <td>
                            <input type="password"
                                   class="regular-text"
                                   id="<?php echo esc_attr(self::OPTION_API_KEY); ?>"
                                   name="<?php echo esc_attr(self::OPTION_API_KEY); ?>"
                                   value=""
                                   placeholder="<?php echo $api_key ? esc_attr('Saved. Leave blank to keep current key.') : esc_attr('Paste OpenAI API key'); ?>" />
                            <p class="description">
                                Saved in WordPress options. Leave blank when saving settings to keep the existing key.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_MODEL); ?>">Translation Model</label></th>
                        <td>
                            <select id="<?php echo esc_attr(self::OPTION_MODEL); ?>"
                                    name="<?php echo esc_attr(self::OPTION_MODEL); ?>">
                                <?php foreach (self::hardcoded_translation_models() as $model_id => $label): ?>
                                    <option value="<?php echo esc_attr($model_id); ?>" <?php selected($selected_model, $model_id); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <p class="description">
                                Default: <code><?php echo esc_html(self::DEFAULT_MODEL); ?></code>.
                            </p>

                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr(self::OPTION_USE_CUSTOM_MODEL); ?>"
                                       value="1"
                                       <?php checked($use_custom, 1); ?> />
                                Use custom model ID instead
                            </label>

                            <br />

                            <input type="text"
                                   class="regular-text"
                                   id="<?php echo esc_attr(self::OPTION_CUSTOM_MODEL); ?>"
                                   name="<?php echo esc_attr(self::OPTION_CUSTOM_MODEL); ?>"
                                   value="<?php echo esc_attr($custom_model); ?>"
                                   placeholder="Example: gpt-5.4-nano" />

                            <p>
                                <button class="button" id="cq-afi-validate-model-ajax">Validate Selected Model</button>
                            </p>

                            <p id="cq-afi-model-validation-status" class="description">
                                <?php echo esc_html(!empty($validation['message']) ? $validation['message'] : 'Model has not been validated yet.'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_SOURCE_LANG); ?>">Source Language Slug</label></th>
                        <td>
                            <input type="text"
                                   class="small-text"
                                   id="<?php echo esc_attr(self::OPTION_SOURCE_LANG); ?>"
                                   name="<?php echo esc_attr(self::OPTION_SOURCE_LANG); ?>"
                                   value="<?php echo esc_attr(get_option(self::OPTION_SOURCE_LANG, 'en')); ?>" />
                            <p class="description">
                                Must match your Polylang source/default language slug, usually <code>en</code>.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_MIN_IMAGE_SCORE); ?>">Minimum Image Match Score</label></th>
                        <td>
                            <input type="number"
                                   class="small-text"
                                   min="1"
                                   max="100"
                                   id="<?php echo esc_attr(self::OPTION_MIN_IMAGE_SCORE); ?>"
                                   name="<?php echo esc_attr(self::OPTION_MIN_IMAGE_SCORE); ?>"
                                   value="<?php echo esc_attr(get_option(self::OPTION_MIN_IMAGE_SCORE, 45)); ?>" />
                            <p class="description">
                                Raise this if wrong images are being matched. Lower it if valid images are being missed.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_BATCH_SIZE); ?>">Processing Batch Size</label></th>
                        <td>
                            <input type="number"
                                   class="small-text"
                                   min="1"
                                   max="100"
                                   id="<?php echo esc_attr(self::OPTION_BATCH_SIZE); ?>"
                                   name="<?php echo esc_attr(self::OPTION_BATCH_SIZE); ?>"
                                   value="<?php echo esc_attr(get_option(self::OPTION_BATCH_SIZE, 10)); ?>" />
                            <p class="description">
                                Used by hourly backfill and manual runs to limit work per execution.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>

            <form method="post">
                <?php wp_nonce_field('cq_afi_validate_model_action', 'cq_afi_validate_model_nonce'); ?>
                <input type="hidden" name="cq_afi_validate_model_submit" value="1" />
                <?php submit_button('Validate Selected Model Without JavaScript', 'secondary'); ?>
            </form>

            <hr />

            <h2>Recommended real server cron</h2>
            <p>For reliable automation, disable visitor-triggered WP-Cron and run cron from your server.</p>
            <pre><code>define('DISABLE_WP_CRON', true);</code></pre>
            <pre><code>*/5 * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1</code></pre>
        </div>
        <?php
    }

    public static function ajax_get_status(): void {
        self::verify_ajax();

        wp_send_json_success(self::status_payload());
    }

    public static function ajax_run_now(): void {
        self::verify_ajax();
        self::start_manual_browser_run();

        wp_send_json_success(self::status_payload());
    }

    public static function ajax_process_manual_queue(): void {
        self::verify_ajax();
        self::process_manual_queue_step();

        wp_send_json_success(self::status_payload());
    }

    public static function ajax_validate_model(): void {
        self::verify_ajax();
        self::validate_selected_model();

        wp_send_json_success(self::status_payload());
    }

    public static function ajax_clear_logs(): void {
        self::verify_ajax();
        update_option(self::OPTION_LOGS, []);
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_run_full_repair_scan(): void {
        self::verify_ajax();
        self::run_full_library_scan();
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_process_repair_queue(): void {
        self::verify_ajax();
        self::process_repair_queue_step();
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_start_background_repair(): void {
        self::verify_ajax();
        self::start_background_repair();
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_pause_background_repair(): void {
        self::verify_ajax();
        self::pause_background_repair();
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_resume_background_repair(): void {
        self::verify_ajax();
        self::resume_background_repair();
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_cancel_background_repair(): void {
        self::verify_ajax();
        self::cancel_background_repair();
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_retry_background_errors(): void {
        self::verify_ajax();
        self::retry_background_errors();
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_start_background_audit(): void {
        self::verify_ajax();
        self::start_background_audit(false);
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_start_audit_and_repair(): void {
        self::verify_ajax();
        self::start_background_audit(true);
        wp_send_json_success(self::status_payload());
    }

    // Combined controls: act on whichever phase (audit or repair) is currently running.
    public static function ajax_pause_all(): void {
        self::verify_ajax();
        self::pause_background_audit();
        self::pause_background_repair();
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_resume_all(): void {
        self::verify_ajax();
        self::resume_background_audit();
        self::resume_background_repair();
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_cancel_all(): void {
        self::verify_ajax();
        self::cancel_background_audit();
        self::cancel_background_repair();
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_pause_background_audit(): void {
        self::verify_ajax();
        self::pause_background_audit();
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_resume_background_audit(): void {
        self::verify_ajax();
        self::resume_background_audit();
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_cancel_background_audit(): void {
        self::verify_ajax();
        self::cancel_background_audit();
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_kick_background_audit(): void {
        self::verify_ajax();
        self::kick_background_audit();
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_process_audit_batch_now(): void {
        self::verify_ajax();
        self::log('warning', 'Manual audit batch button request received.');
        self::background_audit_worker();
        wp_send_json_success(self::status_payload());
    }

    public static function ajax_restore_translation_dates(): void {
        self::verify_ajax();
        $count = self::restore_translation_dates_from_sources();
        $message = 'Restored publish dates for ' . $count . ' translated post(s).';
        set_transient('cq_afi_date_restore_message', $message, 60);
        self::log('info', $message);
        wp_send_json_success(self::status_payload());
    }

    private static function verify_ajax(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        check_ajax_referer('cq_afi_ajax_nonce', 'nonce');
    }

    private static function status_payload(): array {
        return [
            'counts' => self::get_dashboard_counts(),
            'job' => self::get_job_status(),
            'validation' => get_option(self::OPTION_MODEL_VALIDATION, []),
            'logs_html' => self::render_logs_table(),
            'repair_report' => self::get_repair_report(),
            'repair_report_html' => self::render_repair_report(),
            'background_status' => self::get_background_status(),
            'background_status_html' => self::render_background_status(),
            'audit_status' => self::get_audit_status(),
            'audit_status_html' => self::render_audit_status(),
            'date_restore_message' => get_transient('cq_afi_date_restore_message') ?: '',
        ];
    }

    private static function start_manual_browser_run(): void {
        $batch_size = max(1, min(100, absint(get_option(self::OPTION_BATCH_SIZE, 10))));
        $post_ids = self::get_posts_needing_processing($batch_size);

        update_option(self::OPTION_MANUAL_QUEUE, array_values(array_map('intval', $post_ids)), false);

        self::update_job_status([
            'state' => count($post_ids) ? 'running' : 'idle',
            'mode' => 'manual-browser',
            'queued_at' => current_time('mysql'),
            'started_at' => current_time('mysql'),
            'finished_at' => count($post_ids) ? '' : current_time('mysql'),
            'processed' => 0,
            'total' => count($post_ids),
            'images_set' => 0,
            'image_no_match' => 0,
            'translations_created' => 0,
            'translation_failed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'message' => count($post_ids)
                ? 'Manual browser-driven processing started. Keep this admin page open until it finishes.'
                : 'No eligible posts needed processing.',
        ]);

        self::log('info', 'Manual browser-driven processing started.', 0, [
            'batch_size' => $batch_size,
            'queued_posts' => count($post_ids),
        ]);
    }

    private static function process_manual_queue_step(): void {
        $job = self::get_job_status();

        if (($job['state'] ?? '') !== 'running' || ($job['mode'] ?? '') !== 'manual-browser') {
            return;
        }

        $queue = get_option(self::OPTION_MANUAL_QUEUE, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        $post_id = array_shift($queue);
        update_option(self::OPTION_MANUAL_QUEUE, $queue, false);

        if (!$post_id) {
            self::finish_manual_browser_run();
            return;
        }

        $result = self::process_post((int) $post_id);
        $job = self::get_job_status();

        foreach (['images_set', 'image_no_match', 'translations_created', 'translation_failed', 'skipped', 'errors'] as $metric) {
            $job[$metric] = (int) ($job[$metric] ?? 0) + (int) ($result[$metric] ?? 0);
        }

        $job['processed'] = (int) ($job['processed'] ?? 0) + 1;
        $job['message'] = self::format_process_result_message((int) $post_id, $result);

        update_option(self::OPTION_JOB_STATUS, $job);

        if (!$queue) {
            self::finish_manual_browser_run();
        }
    }

    private static function finish_manual_browser_run(): void {
        $final = self::get_job_status();
        $summary = sprintf(
            'Manual browser batch completed. Images set: %d. No image match: %d. Translations created: %d. Translation failed: %d. Errors: %d.',
            (int) ($final['images_set'] ?? 0),
            (int) ($final['image_no_match'] ?? 0),
            (int) ($final['translations_created'] ?? 0),
            (int) ($final['translation_failed'] ?? 0),
            (int) ($final['errors'] ?? 0)
        );

        self::update_job_status([
            'state' => 'idle',
            'finished_at' => current_time('mysql'),
            'message' => $summary,
        ]);

        update_option(self::OPTION_MANUAL_QUEUE, [], false);
        self::log('info', $summary);
    }

    private static function schedule_manual_run(): void {
        $job = self::default_job_status();
        $job['state'] = 'queued';
        $job['mode'] = 'wp-cron';
        $job['queued_at'] = current_time('mysql');
        $job['message'] = 'Manual processing job queued through WP-Cron. If it stays queued, WP-Cron is not firing.';
        update_option(self::OPTION_JOB_STATUS, $job);
        self::log('info', 'Manual processing job queued from admin.', 0, ['event' => self::MANUAL_EVENT]);

        if (!wp_next_scheduled(self::MANUAL_EVENT)) {
            $scheduled = wp_schedule_single_event(time() + 5, self::MANUAL_EVENT);
            if ($scheduled === false) {
                self::log('error', 'wp_schedule_single_event returned false for the manual job.', 0, ['event' => self::MANUAL_EVENT]);
            }
        }

        $spawn = wp_remote_post(site_url('wp-cron.php?doing_wp_cron=' . urlencode((string) microtime(true))), [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);

        if (is_wp_error($spawn)) {
            self::log('error', 'Attempt to spawn WP-Cron failed: ' . $spawn->get_error_message(), 0, ['event' => self::MANUAL_EVENT]);
        } else {
            self::log('info', 'Attempted to spawn WP-Cron for manual job.', 0, ['event' => self::MANUAL_EVENT]);
        }
    }

    private static function default_job_status(): array {
        return [
            'state' => 'idle',
            'mode' => '',
            'queued_at' => '',
            'started_at' => '',
            'finished_at' => '',
            'processed' => 0,
            'total' => 0,
            'images_set' => 0,
            'image_no_match' => 0,
            'translations_created' => 0,
            'translation_failed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'message' => '',
        ];
    }

    private static function get_job_status(): array {
        $job = get_option(self::OPTION_JOB_STATUS, self::default_job_status());
        $job = is_array($job) ? array_merge(self::default_job_status(), $job) : self::default_job_status();

        if ($job['state'] === 'queued' && !empty($job['queued_at'])) {
            $queued_ts = strtotime((string) $job['queued_at']);
            if ($queued_ts && (current_time('timestamp') - $queued_ts) > 5 * MINUTE_IN_SECONDS) {
                $job['state'] = 'stale-queued';
                $job['message'] = 'The job is still queued after 5 minutes. WP-Cron probably did not fire. Use Run Process Now, which now processes through the admin AJAX queue, or configure real server cron.';
                update_option(self::OPTION_JOB_STATUS, $job);
                self::log('error', 'Manual job became stale in queued status. WP-Cron likely did not fire.', 0, [
                    'queued_at' => (string) $job['queued_at'],
                    'event' => self::MANUAL_EVENT,
                ]);
            }
        }

        return $job;
    }

    private static function update_job_status(array $patch): void {
        update_option(self::OPTION_JOB_STATUS, array_merge(self::get_job_status(), $patch));
    }





    private static function capture_post_dates(int $post_id): array {
        $post = get_post($post_id);

        if (!$post) {
            return [];
        }

        if (!get_post_meta($post_id, self::META_ORIGINAL_POST_DATE, true)) {
            update_post_meta($post_id, self::META_ORIGINAL_POST_DATE, $post->post_date);
            update_post_meta($post_id, self::META_ORIGINAL_POST_DATE_GMT, $post->post_date_gmt);
        }

        return [
            'post_date' => $post->post_date,
            'post_date_gmt' => $post->post_date_gmt,
            'post_modified' => $post->post_modified,
            'post_modified_gmt' => $post->post_modified_gmt,
        ];
    }

    private static function restore_post_dates(int $post_id, array $dates): void {
        if (!$post_id || empty($dates['post_date']) || empty($dates['post_date_gmt'])) {
            return;
        }

        global $wpdb;

        $wpdb->update(
            $wpdb->posts,
            [
                'post_date' => $dates['post_date'],
                'post_date_gmt' => $dates['post_date_gmt'],
                'post_modified' => $dates['post_modified'] ?? $dates['post_date'],
                'post_modified_gmt' => $dates['post_modified_gmt'] ?? $dates['post_date_gmt'],
            ],
            ['ID' => $post_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        clean_post_cache($post_id);
    }

    private static function preserve_post_dates_for_callable(int $post_id, callable $callback) {
        $dates = self::capture_post_dates($post_id);

        try {
            return $callback();
        } finally {
            if ($dates) {
                self::restore_post_dates($post_id, $dates);
            }
        }
    }

    private static function restore_translation_dates_from_sources(): int {
        if (!self::polylang_available()) {
            self::log('warning', 'Date restore skipped because Polylang is not available.');
            return 0;
        }

        $source_lang = self::get_source_language_slug();
        $languages = pll_languages_list(['fields' => 'slug']);

        if (!$languages || count($languages) < 2) {
            return 0;
        }

        $post_ids = get_posts([
            'post_type' => 'post',
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => false,
        ]);

        $restored = 0;

        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            $lang = pll_get_post_language($post_id);

            if (!$lang || $lang === $source_lang) {
                continue;
            }

            $source_id = pll_get_post($post_id, $source_lang);

            if (!$source_id) {
                continue;
            }

            $source = get_post((int) $source_id);
            $target = get_post($post_id);

            if (!$source || !$target) {
                continue;
            }

            if ($target->post_date === $source->post_date && $target->post_date_gmt === $source->post_date_gmt) {
                continue;
            }

            self::restore_post_dates($post_id, [
                'post_date' => $source->post_date,
                'post_date_gmt' => $source->post_date_gmt,
                'post_modified' => $target->post_modified,
                'post_modified_gmt' => $target->post_modified_gmt,
            ]);

            update_post_meta($post_id, self::META_ORIGINAL_POST_DATE, $source->post_date);
            update_post_meta($post_id, self::META_ORIGINAL_POST_DATE_GMT, $source->post_date_gmt);

            $restored++;

            self::log('info', 'Translated post publish date restored from source-language post.', $post_id, [
                'source_post_id' => (int) $source_id,
                'source_date' => $source->post_date,
            ]);
        }

        return $restored;
    }

    private static function default_audit_status(): array {
        return [
            'state' => 'idle',
            'engine' => self::action_scheduler_available() ? 'action-scheduler' : 'wp-cron-fallback',
            'started_at' => '',
            'last_run_at' => '',
            'finished_at' => '',
            'last_post_id' => 0,
            'scanned_posts' => 0,
            'estimated_total_posts' => self::count_auditable_posts(),
            'auto_start_repair' => 0,
            'message' => '',
        ];
    }

    private static function get_audit_status(): array {
        $status = get_option(self::OPTION_AUDIT_STATUS, self::default_audit_status());
        $status = is_array($status) ? array_merge(self::default_audit_status(), $status) : self::default_audit_status();
        $status['engine'] = self::action_scheduler_available() ? 'action-scheduler' : 'wp-cron-fallback';
        $status['estimated_total_posts'] = self::count_auditable_posts();
        return $status;
    }

    private static function update_audit_status(array $patch): void {
        update_option(self::OPTION_AUDIT_STATUS, array_merge(self::get_audit_status(), $patch), false);
    }

    private static function render_audit_status(): string {
        $s = self::get_audit_status();
        $total = max(0, (int) $s['estimated_total_posts']);
        $scanned = max(0, (int) $s['scanned_posts']);
        $pct = $total > 0 ? min(100, round(($scanned / $total) * 100, 1)) : 0;

        $html = '<div class="cq-afi-card" style="max-width:900px;">';
        $html .= '<h2>Background Site Audit</h2>';
        $html .= '<p><strong>State:</strong> ' . esc_html($s['state']) . '</p>';
        $html .= '<p><strong>Engine:</strong> ' . esc_html($s['engine']) . '</p>';
        $html .= '<p><strong>Started:</strong> ' . esc_html($s['started_at'] ?: 'Not started') . '</p>';
        $html .= '<p><strong>Last Run:</strong> ' . esc_html($s['last_run_at'] ?: 'Not run yet') . '</p>';
        $html .= '<p><strong>Finished:</strong> ' . esc_html($s['finished_at'] ?: 'Not finished') . '</p>';
        $html .= '<p><strong>Scanned:</strong> ' . esc_html((string) $scanned) . ' / ' . esc_html((string) $total) . ' (' . esc_html((string) $pct) . '%)</p>';
        $html .= '<p><strong>Last Post ID:</strong> ' . esc_html((string) $s['last_post_id']) . '</p>';
        $html .= '<p><strong>Auto-start repair:</strong> ' . esc_html(!empty($s['auto_start_repair']) ? 'Yes' : 'No') . '</p>';
        $html .= '<p><strong>Message:</strong> ' . esc_html($s['message']) . '</p>';
        if (self::audit_appears_stuck($s)) {
            $html .= '<p class="cq-afi-log-warning"><strong>Warning:</strong> This audit appears stuck. Click Kick/Requeue Audit Worker or confirm Action Scheduler/WP-Cron is running.</p>';
        }
        $html .= '</div>';

        return $html;
    }

    private static function count_auditable_posts(): int {
        global $wpdb;

        $statuses = ["publish", "draft", "pending", "future", "private"];
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        // $placeholders is a generated list of %s tokens (not user data); the
        // status values are bound through $wpdb->prepare() below.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare(
            "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ($placeholders)",
            $statuses
        );

        return (int) $wpdb->get_var($sql);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function start_background_audit(bool $auto_start_repair): void {
        update_option(self::OPTION_AUDIT_AGGREGATE, self::empty_audit_aggregate(), false);
        update_option(self::OPTION_AUDIT_ITEMS, [], false);
        self::set_repair_queue([]);
        update_option(self::OPTION_RETRY_QUEUE, [], false);

        self::update_audit_status([
            'state' => 'running',
            'started_at' => current_time('mysql'),
            'last_run_at' => '',
            'finished_at' => '',
            'last_post_id' => 0,
            'scanned_posts' => 0,
            'estimated_total_posts' => self::count_auditable_posts(),
            'auto_start_repair' => $auto_start_repair ? 1 : 0,
            'message' => $auto_start_repair
                ? 'Background audit started. Repairs will start automatically when the audit completes.'
                : 'Background audit started.',
        ]);

        self::update_background_status([
            'state' => 'idle',
            'started_at' => '',
            'last_run_at' => '',
            'finished_at' => '',
            'processed' => 0,
            'queue_total_at_start' => 0,
            'queue_remaining' => 0,
            'errors' => 0,
            'message' => 'Waiting for audit to build repair queue.',
        ]);

        self::log('info', 'Background audit started.', 0, [
            'auto_start_repair' => $auto_start_repair ? 'yes' : 'no',
            'engine' => self::action_scheduler_available() ? 'action-scheduler' : 'wp-cron-fallback',
        ]);

        self::schedule_audit_worker();
    }

    private static function pause_background_audit(): void {
        self::update_audit_status([
            'state' => 'paused',
            'message' => 'Background audit paused by admin.',
        ]);

        self::log('info', 'Background audit paused by admin.');
    }

    private static function resume_background_audit(): void {
        $status = self::get_audit_status();

        if (!in_array($status['state'], ['paused', 'idle'], true)) {
            self::update_audit_status([
                'message' => 'Audit resume ignored because audit is not paused.',
            ]);
            return;
        }

        self::update_audit_status([
            'state' => 'running',
            'message' => 'Background audit resumed.',
        ]);

        self::log('info', 'Background audit resumed by admin.');
        self::schedule_audit_worker();
    }

    private static function cancel_background_audit(): void {
        self::update_audit_status([
            'state' => 'cancelled',
            'finished_at' => current_time('mysql'),
            'message' => 'Background audit cancelled by admin.',
        ]);

        self::log('warning', 'Background audit cancelled by admin.');
    }


    private static function audit_appears_stuck(array $status = []): bool {
        $status = $status ?: self::get_audit_status();

        if (($status['state'] ?? '') !== 'running') {
            return false;
        }

        if (empty($status['last_run_at']) && !empty($status['started_at'])) {
            return (time() - strtotime((string) $status['started_at'])) > 300;
        }

        if (!empty($status['last_run_at'])) {
            return (time() - strtotime((string) $status['last_run_at'])) > 600;
        }

        return false;
    }

    private static function kick_background_audit(): void {
        $status = self::get_audit_status();

        if (($status['state'] ?? '') !== 'running') {
            self::update_audit_status([
                'message' => 'Kick ignored because audit is not running.',
            ]);
            self::log('warning', 'Audit kick ignored because audit is not running.', 0, [
                'state' => $status['state'] ?? '',
            ]);
            return;
        }

        self::log('warning', 'Audit worker manually kicked/requeued by admin.', 0, [
            'last_post_id' => (int) ($status['last_post_id'] ?? 0),
            'scanned_posts' => (int) ($status['scanned_posts'] ?? 0),
        ]);

        self::update_audit_status([
            'message' => 'Audit worker was manually kicked/requeued.',
        ]);

        self::schedule_audit_worker(true);
    }

    private static function schedule_audit_worker(bool $force = false): void {
        $status = self::get_audit_status();

        if ($status['state'] !== 'running') {
            return;
        }

        $scheduled = [];

        if (self::action_scheduler_available()) {
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(self::AS_AUDIT_HOOK, [], 'cq-afi');
                $scheduled[] = 'action-scheduler-async';
            } elseif (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time() + 5, self::AS_AUDIT_HOOK, [], 'cq-afi');
                $scheduled[] = 'action-scheduler-single';
            }
        }

        // Always schedule the WP-Cron fallback too. Some hosts block Action Scheduler async loopbacks,
        // and Action Scheduler itself still depends on a working runner/cron.
        if ($force || !wp_next_scheduled(self::AUDIT_EVENT)) {
            wp_schedule_single_event(time() + 5, self::AUDIT_EVENT);
            $scheduled[] = 'wp-cron-fallback';
        }

        $spawn = wp_remote_post(site_url('wp-cron.php?doing_wp_cron=' . urlencode((string) microtime(true))), [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);

        self::log('info', 'Background audit worker scheduled.', 0, [
            'methods' => implode(',', $scheduled),
            'force' => $force ? 'yes' : 'no',
            'spawn_error' => is_wp_error($spawn) ? $spawn->get_error_message() : '',
        ]);
    }

    public static function background_audit_worker(): void {
        self::log('info', 'Background audit worker entered.');

        try {
            self::background_audit_worker_inner();
        } catch (Throwable $e) {
            self::update_audit_status([
                'last_run_at' => current_time('mysql'),
                'message' => 'Audit worker error: ' . $e->getMessage(),
            ]);

            self::log('error', 'Background audit worker failed with Throwable.', 0, [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => (string) $e->getLine(),
            ]);

            self::schedule_audit_worker(true);
        }
    }

    private static function background_audit_worker_inner(): void {
        $status = self::get_audit_status();

        if ($status['state'] !== 'running') {
            self::log('info', 'Background audit worker exited because audit is not running.', 0, [
                'state' => $status['state'],
            ]);
            return;
        }

        $batch_size = (int) apply_filters('cq_afi_audit_batch_size', self::AUDIT_BATCH_SIZE);
        $batch_size = max(1, min(25, $batch_size));

        $last_post_id = (int) $status['last_post_id'];

        self::update_audit_status([
            'last_run_at' => current_time('mysql'),
            'message' => 'Audit worker started batch after post ID ' . $last_post_id . '.',
        ]);

        $post_ids = self::get_audit_post_ids_after($last_post_id, $batch_size);

        self::log('info', 'Background audit worker loaded post IDs.', 0, [
            'requested_batch_size' => $batch_size,
            'loaded_count' => count($post_ids),
            'last_post_id' => $last_post_id,
        ]);

        if (!$post_ids) {
            self::finish_background_audit();
            return;
        }

        $aggregate = self::get_audit_aggregate();
        $items = self::get_audit_items();
        $queue = self::get_repair_queue();

        $processed_in_batch = 0;

        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;

            try {
                self::audit_single_post($post_id, $aggregate, $items, $queue);
            } catch (Throwable $e) {
                $aggregate['errors'] = (int) ($aggregate['errors'] ?? 0) + 1;
                $items[] = [
                    'type' => 'audit_error',
                    'post_id' => $post_id,
                    'lang' => '',
                    'message' => 'Audit error: ' . $e->getMessage(),
                    'action' => 'manual_review',
                ];

                self::log('error', 'Single post audit failed.', $post_id, [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => (string) $e->getLine(),
                ]);
            }

            $last_post_id = max($last_post_id, $post_id);
            $processed_in_batch++;

            if (($processed_in_batch % self::AUDIT_CHECKPOINT_EVERY) === 0) {
                self::save_audit_checkpoint($aggregate, $items, $queue, $last_post_id, (int) $status['scanned_posts'] + $processed_in_batch, 'Checkpoint saved during audit batch.');
            }
        }

        self::save_audit_checkpoint($aggregate, $items, $queue, $last_post_id, (int) $status['scanned_posts'] + $processed_in_batch, 'Scanned ' . $processed_in_batch . ' posts in the latest batch.');

        self::log('info', 'Background audit batch processed.', 0, [
            'batch_count' => $processed_in_batch,
            'last_post_id' => $last_post_id,
            'queue_remaining' => count(self::get_repair_queue()),
        ]);

        self::schedule_audit_worker(true);
    }

    private static function save_audit_checkpoint(array $aggregate, array $items, array $queue, int $last_post_id, int $scanned_posts, string $message): void {
        $aggregate['items'] = array_slice($items, 0, 500);
        update_option(self::OPTION_AUDIT_AGGREGATE, $aggregate, false);
        update_option(self::OPTION_AUDIT_ITEMS, array_slice($items, -1000), false);
        self::set_repair_queue(self::dedupe_repair_queue($queue));

        self::update_audit_status([
            'last_run_at' => current_time('mysql'),
            'last_post_id' => $last_post_id,
            'scanned_posts' => $scanned_posts,
            'message' => $message,
        ]);
    }

    private static function get_audit_post_ids_after(int $last_post_id, int $limit): array {
        global $wpdb;

        $statuses = ["publish", "draft", "pending", "future", "private"];
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $params = array_merge([$last_post_id], $statuses, [$limit]);

        // $status_placeholders is a generated list of %s tokens (not user data);
        // every value is bound through $wpdb->prepare() below.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE ID > %d
                AND post_type = 'post'
                AND post_status IN ($status_placeholders)
             ORDER BY ID ASC
             LIMIT %d",
            $params
        );

        return array_map('intval', (array) $wpdb->get_col($sql));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function empty_audit_aggregate(): array {
        return [
            'last_scan_at' => '',
            'scanned_posts' => 0,
            'source_posts' => 0,
            'translation_posts' => 0,
            'missing_images_total' => 0,
            'missing_images_source' => 0,
            'missing_images_translation' => 0,
            'translation_groups_missing_images_keys' => [],
            'broken_translation_links' => 0,
            'source_posts_without_any_translation' => 0,
            'total_missing_language_versions' => 0,
            'manual_review' => 0,
            'queue_total' => 0,
            'queue_remaining' => 0,
            'fixed_images' => 0,
            'fixed_links' => 0,
            'created_translations' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];
    }

    private static function get_audit_aggregate(): array {
        $agg = get_option(self::OPTION_AUDIT_AGGREGATE, self::empty_audit_aggregate());
        return is_array($agg) ? array_merge(self::empty_audit_aggregate(), $agg) : self::empty_audit_aggregate();
    }

    private static function get_audit_items(): array {
        $items = get_option(self::OPTION_AUDIT_ITEMS, []);
        return is_array($items) ? $items : [];
    }

    private static function audit_single_post(int $post_id, array &$agg, array &$items, array &$queue): void {
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'post') {
            return;
        }

        $source_lang = self::get_source_language_slug();
        $languages = self::polylang_available() ? pll_languages_list(['fields' => 'slug']) : [];
        $lang = self::polylang_available() ? pll_get_post_language($post_id) : '';
        $is_source = (!$lang || $lang === $source_lang);

        $agg['scanned_posts']++;

        if ($is_source) {
            $agg['source_posts']++;
        } else {
            $agg['translation_posts']++;
        }

        $missing_reason = self::get_featured_image_missing_reason($post_id);
        if ($missing_reason !== '') {
            $agg['missing_images_total']++;

            if ($is_source) {
                $agg['missing_images_source']++;
            } else {
                $agg['missing_images_translation']++;
            }

            $group = self::get_polylang_post_group($post_id);
            $source_post_id = (int) ($group['source_post_id'] ?? 0);
            if ($source_post_id) {
                $agg['translation_groups_missing_images_keys'][$source_post_id] = true;
            }

            $items[] = [
                'type' => $is_source ? 'source_missing_image' : 'translation_missing_image',
                'post_id' => $post_id,
                'lang' => $lang ?: $source_lang,
                'message' => 'Featured image missing/invalid: ' . $missing_reason,
                'action' => 'repair_image_group',
            ];

            $queue[] = [
                'type' => 'repair_image_group',
                'post_id' => $post_id,
                'lang' => $lang ?: $source_lang,
                'reason' => $missing_reason,
            ];
        }

        if (self::polylang_available() && !$is_source) {
            $source_id = pll_get_post($post_id, $source_lang);

            if (!$source_id) {
                $agg['broken_translation_links']++;
                $match = self::resolve_unlinked_translation_source($post_id, $source_lang);

                // Authoritative meta is always trusted; a heuristic match must be strong and unambiguous.
                $confident = !empty($match['post_id'])
                    && ((($match['via'] ?? '') === 'meta') || ((float) $match['score'] >= 80 && empty($match['ambiguous'])));

                if ($confident) {
                    $items[] = [
                        'type' => 'broken_translation_link',
                        'post_id' => $post_id,
                        'lang' => $lang,
                        'message' => 'Unlinked translation will be relinked to source #' . (int) $match['post_id'] . ' (' . ($match['reason'] ?? '') . ').',
                        'action' => 'repair_translation_link',
                    ];

                    $queue[] = [
                        'type' => 'repair_translation_link',
                        'post_id' => $post_id,
                        'candidate_source_id' => (int) $match['post_id'],
                        'score' => (float) $match['score'],
                    ];
                } else {
                    $agg['manual_review']++;
                    $items[] = [
                        'type' => 'manual_review',
                        'post_id' => $post_id,
                        'lang' => $lang,
                        'message' => 'Unlinked translation has no authoritative source meta and no clear source-language candidate.',
                        'action' => 'manual_review',
                    ];
                }
            }
        }

        if (self::polylang_available() && $is_source && $languages && count($languages) > 1) {
            $map = self::get_existing_translation_map($post_id, $source_lang, $languages);
            $orphans = self::get_orphan_translations_for_source($post_id, $source_lang);
            $has_alt = false;
            $missing_any = false;
            $relinkable = false;

            foreach ($languages as $target_lang) {
                if ($target_lang === $source_lang) {
                    continue;
                }

                if (!empty($map[$target_lang])) {
                    $has_alt = true; // Already linked in Polylang.
                    continue;
                }

                // Not linked: it is missing unless an orphaned translation can be relinked.
                $agg['total_missing_language_versions']++;
                $missing_any = true;
                if (!empty($orphans[$target_lang])) {
                    $has_alt = true; // An alternative exists, just not linked yet.
                    $relinkable = true;
                }
            }

            if (!$has_alt) {
                $agg['source_posts_without_any_translation']++;
            }

            // Queue one fill pass whenever any language is missing or merely unlinked.
            // translate_post_if_needed() will relink orphans first, then create the rest.
            if ($missing_any) {
                $items[] = [
                    'type' => 'source_without_translation',
                    'post_id' => $post_id,
                    'lang' => $source_lang,
                    'message' => $relinkable
                        ? 'Source post has unlinked/missing language versions (some can be relinked).'
                        : 'Source post is missing one or more language versions.',
                    'action' => 'repair_missing_translation',
                ];
                $queue[] = [
                    'type' => 'repair_missing_translation',
                    'post_id' => $post_id,
                ];
            }
        }

        if (count($items) > 1000) {
            $items = array_slice($items, -1000);
        }
    }

    private static function finish_background_audit(): void {
        $agg = self::get_audit_aggregate();
        $items = self::get_audit_items();
        $queue = self::dedupe_repair_queue(self::get_repair_queue());

        $report = [
            'last_scan_at' => current_time('mysql'),
            'scanned_posts' => (int) $agg['scanned_posts'],
            'source_posts' => (int) $agg['source_posts'],
            'translation_posts' => (int) $agg['translation_posts'],
            'missing_images_total' => (int) $agg['missing_images_total'],
            'missing_images_source' => (int) $agg['missing_images_source'],
            'missing_images_translation' => (int) $agg['missing_images_translation'],
            'translation_groups_missing_images' => count((array) $agg['translation_groups_missing_images_keys']),
            'broken_translation_links' => (int) $agg['broken_translation_links'],
            'source_posts_without_any_translation' => (int) $agg['source_posts_without_any_translation'],
            'total_missing_language_versions' => (int) $agg['total_missing_language_versions'],
            'manual_review' => (int) $agg['manual_review'],
            'queue_total' => count($queue),
            'queue_remaining' => count($queue),
            'fixed_images' => 0,
            'fixed_links' => 0,
            'created_translations' => 0,
            'skipped' => 0,
            'errors' => 0,
            'items' => array_slice($items, 0, 500),
        ];

        update_option(self::OPTION_REPAIR_REPORT, $report, false);
        self::set_repair_queue($queue);

        $status = self::get_audit_status();

        self::update_audit_status([
            'state' => 'complete',
            'last_run_at' => current_time('mysql'),
            'finished_at' => current_time('mysql'),
            'message' => 'Background audit completed. Repair queue contains ' . count($queue) . ' item(s).',
        ]);

        self::log('info', 'Background audit completed.', 0, [
            'scanned_posts' => (int) $agg['scanned_posts'],
            'queue_total' => count($queue),
            'auto_start_repair' => !empty($status['auto_start_repair']) ? 'yes' : 'no',
        ]);

        if (!empty($status['auto_start_repair']) && count($queue) > 0) {
            self::start_background_repair();
        }
    }

    private static function default_background_status(): array {
        return [
            'state' => 'idle',
            'engine' => self::action_scheduler_available() ? 'action-scheduler' : 'wp-cron-fallback',
            'started_at' => '',
            'last_run_at' => '',
            'finished_at' => '',
            'processed' => 0,
            'queue_total_at_start' => 0,
            'queue_remaining' => count(self::get_repair_queue()),
            'errors' => 0,
            'message' => '',
        ];
    }

    private static function get_background_status(): array {
        $status = get_option(self::OPTION_BACKGROUND_STATUS, self::default_background_status());
        $status = is_array($status) ? array_merge(self::default_background_status(), $status) : self::default_background_status();
        $status['engine'] = self::action_scheduler_available() ? 'action-scheduler' : 'wp-cron-fallback';
        $status['queue_remaining'] = count(self::get_repair_queue());
        return $status;
    }

    private static function update_background_status(array $patch): void {
        update_option(self::OPTION_BACKGROUND_STATUS, array_merge(self::get_background_status(), $patch), false);
    }

    private static function render_background_status(): string {
        $s = self::get_background_status();

        $html = '<div class="cq-afi-card" style="max-width:900px;">';
        $html .= '<h2>Background Repair Worker</h2>';
        $html .= '<p><strong>State:</strong> ' . esc_html($s['state']) . '</p>';
        $html .= '<p><strong>Engine:</strong> ' . esc_html($s['engine']) . '</p>';
        $html .= '<p><strong>Started:</strong> ' . esc_html($s['started_at'] ?: 'Not started') . '</p>';
        $html .= '<p><strong>Last Run:</strong> ' . esc_html($s['last_run_at'] ?: 'Not run yet') . '</p>';
        $html .= '<p><strong>Finished:</strong> ' . esc_html($s['finished_at'] ?: 'Not finished') . '</p>';
        $html .= '<p><strong>Processed:</strong> ' . esc_html((string) $s['processed']) . '</p>';
        $html .= '<p><strong>Remaining:</strong> ' . esc_html((string) $s['queue_remaining']) . '</p>';
        $html .= '<p><strong>Errors:</strong> ' . esc_html((string) $s['errors']) . '</p>';
        $html .= '<p><strong>Message:</strong> ' . esc_html($s['message']) . '</p>';

        if (!self::action_scheduler_available()) {
            $html .= '<p class="cq-afi-muted"><strong>Note:</strong> Action Scheduler was not detected. The bundled WP-Cron fallback worker is active. For very large queues, installing WooCommerce or the Action Scheduler plugin will improve reliability.</p>';
        } else {
            $html .= '<p class="cq-afi-muted">Action Scheduler detected. Background repair uses async scheduled actions.</p>';
        }

        $html .= '</div>';

        return $html;
    }

    private static function action_scheduler_available(): bool {
        return function_exists('as_enqueue_async_action') || function_exists('as_schedule_single_action');
    }

    private static function start_background_repair(): void {
        $queue = self::get_repair_queue();

        if (!$queue) {
            self::log('warning', 'Background repair was not started because the repair queue is empty. Run Full Library Scan first.');
            self::update_background_status([
                'state' => 'idle',
                'message' => 'Repair queue is empty. Run Full Library Scan first.',
                'queue_remaining' => 0,
            ]);
            return;
        }

        self::update_background_status([
            'state' => 'running',
            'started_at' => current_time('mysql'),
            'finished_at' => '',
            'queue_total_at_start' => count($queue),
            'queue_remaining' => count($queue),
            'message' => 'Background repair started.',
        ]);

        self::log('info', 'Background repair started.', 0, [
            'engine' => self::action_scheduler_available() ? 'action-scheduler' : 'wp-cron-fallback',
            'queue_remaining' => count($queue),
        ]);

        self::schedule_background_worker();
    }

    private static function pause_background_repair(): void {
        self::update_background_status([
            'state' => 'paused',
            'message' => 'Background repair paused by admin.',
        ]);

        self::log('info', 'Background repair paused by admin.');
    }

    private static function resume_background_repair(): void {
        if (!self::get_repair_queue()) {
            self::update_background_status([
                'state' => 'idle',
                'message' => 'No queued items to resume.',
            ]);
            return;
        }

        self::update_background_status([
            'state' => 'running',
            'message' => 'Background repair resumed.',
        ]);

        self::log('info', 'Background repair resumed by admin.');
        self::schedule_background_worker();
    }

    private static function cancel_background_repair(): void {
        $remaining = count(self::get_repair_queue());
        self::set_repair_queue([]);

        self::update_background_status([
            'state' => 'cancelled',
            'finished_at' => current_time('mysql'),
            'queue_remaining' => 0,
            'message' => 'Background repair cancelled. Cleared ' . $remaining . ' queued items.',
        ]);

        self::log('warning', 'Background repair cancelled by admin.', 0, [
            'cleared_items' => $remaining,
        ]);
    }

    private static function retry_background_errors(): void {
        $retry = get_option(self::OPTION_RETRY_QUEUE, []);
        $retry = is_array($retry) ? $retry : [];

        if (!$retry) {
            self::update_background_status([
                'message' => 'No retryable error items found.',
            ]);
            return;
        }

        $queue = self::get_repair_queue();
        $queue = array_merge($queue, $retry);
        $queue = self::dedupe_repair_queue($queue);

        update_option(self::OPTION_RETRY_QUEUE, [], false);
        self::set_repair_queue($queue);

        self::update_background_status([
            'state' => 'running',
            'message' => 'Retryable error items requeued.',
            'queue_remaining' => count($queue),
        ]);

        self::log('info', 'Retryable error items requeued.', 0, [
            'requeued' => count($retry),
            'queue_remaining' => count($queue),
        ]);

        self::schedule_background_worker();
    }

    private static function schedule_background_worker(bool $force = false): void {
        $status = self::get_background_status();

        if ($status['state'] !== 'running') {
            return;
        }

        $scheduled = [];

        if (self::action_scheduler_available()) {
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(self::AS_BACKGROUND_HOOK, [], 'cq-afi');
                $scheduled[] = 'action-scheduler-async';
            } elseif (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time() + 5, self::AS_BACKGROUND_HOOK, [], 'cq-afi');
                $scheduled[] = 'action-scheduler-single';
            }
        }

        if ($force || !wp_next_scheduled(self::BACKGROUND_EVENT)) {
            wp_schedule_single_event(time() + 5, self::BACKGROUND_EVENT);
            $scheduled[] = 'wp-cron-fallback';
        }

        $spawn = wp_remote_post(site_url('wp-cron.php?doing_wp_cron=' . urlencode((string) microtime(true))), [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);

        self::log('info', 'Background repair worker scheduled.', 0, [
            'methods' => implode(',', $scheduled),
            'force' => $force ? 'yes' : 'no',
            'spawn_error' => is_wp_error($spawn) ? $spawn->get_error_message() : '',
        ]);
    }

    public static function background_repair_worker(): void {
        $status = self::get_background_status();

        if ($status['state'] !== 'running') {
            self::log('info', 'Background worker exited because queue is not running.', 0, [
                'state' => $status['state'],
            ]);
            return;
        }

        $before = count(self::get_repair_queue());

        if (!$before) {
            self::update_background_status([
                'state' => 'complete',
                'finished_at' => current_time('mysql'),
                'queue_remaining' => 0,
                'message' => 'Background repair completed.',
            ]);
            self::log('info', 'Background repair completed.');
            return;
        }

        self::process_repair_queue_step();

        $after = count(self::get_repair_queue());
        $processed = max(0, $before - $after);
        $new_status = self::get_background_status();

        self::update_background_status([
            'last_run_at' => current_time('mysql'),
            'processed' => (int) $new_status['processed'] + $processed,
            'queue_remaining' => $after,
            'message' => 'Processed ' . $processed . ' queued repair item(s).',
        ]);

        if ($after > 0 && self::get_background_status()['state'] === 'running') {
            self::schedule_background_worker();
        } else {
            self::update_background_status([
                'state' => 'complete',
                'finished_at' => current_time('mysql'),
                'queue_remaining' => 0,
                'message' => 'Background repair completed.',
            ]);

            self::log('info', 'Background repair completed.');
        }
    }

    private static function add_retry_item(array $item): void {
        $retry = get_option(self::OPTION_RETRY_QUEUE, []);
        $retry = is_array($retry) ? $retry : [];
        $retry[] = $item;
        update_option(self::OPTION_RETRY_QUEUE, self::dedupe_repair_queue($retry), false);
    }

    private static function get_repair_report(): array {
        $default = [
            'last_scan_at' => '',
            'scanned_posts' => 0,
            'source_posts' => 0,
            'translation_posts' => 0,
            'missing_images_total' => 0,
            'missing_images_source' => 0,
            'missing_images_translation' => 0,
            'translation_groups_missing_images' => 0,
            'broken_translation_links' => 0,
            'source_posts_without_any_translation' => 0,
            'total_missing_language_versions' => 0,
            'manual_review' => 0,
            'queue_total' => 0,
            'queue_remaining' => count(self::get_repair_queue()),
            'fixed_images' => 0,
            'fixed_links' => 0,
            'created_translations' => 0,
            'skipped' => 0,
            'errors' => 0,
            'items' => [],
        ];

        $report = get_option(self::OPTION_REPAIR_REPORT, []);
        return is_array($report) ? array_merge($default, $report) : $default;
    }

    private static function update_repair_report(array $patch): void {
        update_option(self::OPTION_REPAIR_REPORT, array_merge(self::get_repair_report(), $patch), false);
    }

    private static function get_repair_queue(): array {
        $queue = get_option(self::OPTION_REPAIR_QUEUE, []);
        return is_array($queue) ? $queue : [];
    }

    private static function set_repair_queue(array $queue): void {
        update_option(self::OPTION_REPAIR_QUEUE, array_values($queue), false);
        self::update_repair_report([
            'queue_remaining' => count($queue),
        ]);
    }

    private static function render_repair_report(): string {
        $r = self::get_repair_report();

        $html = '<div class="cq-afi-cards">';
        $cards = [
            'Last Scan' => $r['last_scan_at'] ?: 'Never',
            'Scanned Posts' => (int) $r['scanned_posts'],
            'Posts Missing Images' => (int) $r['missing_images_total'],
            'Source Missing Images' => (int) $r['missing_images_source'],
            'Translation Missing Images' => (int) $r['missing_images_translation'],
            'Groups Missing Images' => (int) $r['translation_groups_missing_images'],
            'Broken Translation Links' => (int) $r['broken_translation_links'],
            'Source Posts Without Translation' => (int) $r['source_posts_without_any_translation'],
            'Missing Language Versions' => (int) $r['total_missing_language_versions'],
            'Needs Manual Review' => (int) $r['manual_review'],
            'Queue Remaining' => (int) $r['queue_remaining'],
        ];

        foreach ($cards as $label => $value) {
            $html .= '<div class="cq-afi-card"><h2>' . esc_html($label) . '</h2><div class="cq-afi-big">' . esc_html((string) $value) . '</div></div>';
        }

        $html .= '</div>';

        if (!empty($r['items']) && is_array($r['items'])) {
            $html .= '<h3>Scan Findings</h3>';
            $html .= '<table class="cq-afi-log-table"><thead><tr><th>Type</th><th>Post</th><th>Language</th><th>Message</th><th>Action</th></tr></thead><tbody>';

            foreach (array_slice($r['items'], 0, 150) as $item) {
                $post_id = (int) ($item['post_id'] ?? 0);
                $post_cell = $post_id ? '<a href="' . esc_url(get_edit_post_link($post_id)) . '">#' . esc_html((string) $post_id) . '</a>' : '';
                $html .= '<tr>';
                $html .= '<td>' . esc_html((string) ($item['type'] ?? '')) . '</td>';
                $html .= '<td>' . $post_cell . '</td>';
                $html .= '<td>' . esc_html((string) ($item['lang'] ?? '')) . '</td>';
                $html .= '<td>' . esc_html((string) ($item['message'] ?? '')) . '</td>';
                $html .= '<td>' . esc_html((string) ($item['action'] ?? '')) . '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';

            if (count($r['items']) > 150) {
                $html .= '<p class="cq-afi-muted">Showing first 150 findings. Export logs for more detail.</p>';
            }
        } else {
            $html .= '<p class="cq-afi-muted">No full-library scan findings yet.</p>';
        }

        return $html;
    }

    private static function run_full_library_scan(): void {
        $source_lang = self::get_source_language_slug();
        $languages = self::polylang_available() ? pll_languages_list(['fields' => 'slug']) : [];
        $items = [];
        $queue = [];
        $scanned = 0;
        $source_posts = 0;
        $translation_posts = 0;
        $missing_images_total = 0;
        $missing_images_source = 0;
        $missing_images_translation = 0;
        $groups_missing_images = [];
        $broken_translation_links = 0;
        $source_posts_without_any_translation = 0;
        $total_missing_language_versions = 0;
        $manual_review = 0;

        $post_ids = get_posts([
            'post_type' => 'post',
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => false,
        ]);

        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            $scanned++;
            $lang = self::polylang_available() ? pll_get_post_language($post_id) : '';
            $is_source = (!$lang || $lang === $source_lang);

            if ($is_source) {
                $source_posts++;
            } else {
                $translation_posts++;
            }

            $missing_reason = self::get_featured_image_missing_reason($post_id);
            if ($missing_reason !== '') {
                $missing_images_total++;

                if ($is_source) {
                    $missing_images_source++;
                } else {
                    $missing_images_translation++;
                }

                $group = self::get_polylang_post_group($post_id);
                $source_post_id = (int) ($group['source_post_id'] ?? 0);
                if ($source_post_id) {
                    $groups_missing_images[$source_post_id] = true;
                }

                $items[] = [
                    'type' => $is_source ? 'source_missing_image' : 'translation_missing_image',
                    'post_id' => $post_id,
                    'lang' => $lang ?: $source_lang,
                    'message' => 'Featured image missing/invalid: ' . $missing_reason,
                    'action' => 'repair_image_group',
                ];

                $queue[] = [
                    'type' => 'repair_image_group',
                    'post_id' => $post_id,
                    'lang' => $lang ?: $source_lang,
                    'reason' => $missing_reason,
                ];
            }

            if (self::polylang_available() && !$is_source) {
                $source_id = pll_get_post($post_id, $source_lang);
                if (!$source_id) {
                    $broken_translation_links++;
                    $match = self::resolve_unlinked_translation_source($post_id, $source_lang);

                    $confident = !empty($match['post_id'])
                        && ((($match['via'] ?? '') === 'meta') || ((float) $match['score'] >= 80 && empty($match['ambiguous'])));

                    if ($confident) {
                        $items[] = [
                            'type' => 'broken_translation_link',
                            'post_id' => $post_id,
                            'lang' => $lang,
                            'message' => 'Unlinked translation will be relinked to source #' . (int) $match['post_id'] . ' (' . ($match['reason'] ?? '') . ').',
                            'action' => 'repair_translation_link',
                        ];

                        $queue[] = [
                            'type' => 'repair_translation_link',
                            'post_id' => $post_id,
                            'candidate_source_id' => (int) $match['post_id'],
                            'score' => (float) $match['score'],
                        ];
                    } else {
                        $manual_review++;
                        $items[] = [
                            'type' => 'manual_review',
                            'post_id' => $post_id,
                            'lang' => $lang,
                            'message' => 'Unlinked translation has no authoritative source meta and no clear source-language candidate.',
                            'action' => 'manual_review',
                        ];
                    }
                }
            }
        }

        if (self::polylang_available() && $languages && count($languages) > 1) {
            foreach ($post_ids as $post_id) {
                $post_id = (int) $post_id;
                $lang = pll_get_post_language($post_id);
                if ($lang && $lang !== $source_lang) {
                    continue;
                }

                $map = self::get_existing_translation_map($post_id, $source_lang, $languages);
                $has_alt = false;

                foreach ($languages as $target_lang) {
                    if ($target_lang === $source_lang) {
                        continue;
                    }
                    if (!empty($map[$target_lang])) {
                        $has_alt = true;
                    } else {
                        $total_missing_language_versions++;
                    }
                }

                if (!$has_alt) {
                    $source_posts_without_any_translation++;
                    $items[] = [
                        'type' => 'source_without_translation',
                        'post_id' => $post_id,
                        'lang' => $source_lang,
                        'message' => 'Source post has no translated alternatives.',
                        'action' => 'repair_missing_translation',
                    ];
                    $queue[] = [
                        'type' => 'repair_missing_translation',
                        'post_id' => $post_id,
                    ];
                }
            }
        }

        $queue = self::dedupe_repair_queue($queue);

        $report = [
            'last_scan_at' => current_time('mysql'),
            'scanned_posts' => $scanned,
            'source_posts' => $source_posts,
            'translation_posts' => $translation_posts,
            'missing_images_total' => $missing_images_total,
            'missing_images_source' => $missing_images_source,
            'missing_images_translation' => $missing_images_translation,
            'translation_groups_missing_images' => count($groups_missing_images),
            'broken_translation_links' => $broken_translation_links,
            'source_posts_without_any_translation' => $source_posts_without_any_translation,
            'total_missing_language_versions' => $total_missing_language_versions,
            'manual_review' => $manual_review,
            'queue_total' => count($queue),
            'queue_remaining' => count($queue),
            'fixed_images' => 0,
            'fixed_links' => 0,
            'created_translations' => 0,
            'skipped' => 0,
            'errors' => 0,
            'items' => $items,
        ];

        update_option(self::OPTION_REPAIR_REPORT, $report, false);
        self::set_repair_queue($queue);
        self::update_background_status([
            'state' => 'idle',
            'started_at' => '',
            'last_run_at' => '',
            'finished_at' => '',
            'processed' => 0,
            'queue_total_at_start' => count($queue),
            'queue_remaining' => count($queue),
            'errors' => 0,
            'message' => 'Full library scan completed. Background repair is ready to start.',
        ]);

        self::log('info', 'Full content library scan completed.', 0, [
            'scanned_posts' => $scanned,
            'queue_total' => count($queue),
            'missing_images_total' => $missing_images_total,
            'broken_translation_links' => $broken_translation_links,
            'manual_review' => $manual_review,
        ]);
    }

    private static function dedupe_repair_queue(array $queue): array {
        $seen = [];
        $out = [];

        foreach ($queue as $item) {
            $key = ($item['type'] ?? '') . ':' . (int) ($item['post_id'] ?? 0);
            if ($key === ':' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }

    private static function process_repair_queue_step(): void {
        $queue = self::get_repair_queue();
        $batch_size = max(1, min(25, absint(get_option(self::OPTION_BATCH_SIZE, 10))));
        $processed = 0;
        $report_updates = [
            'fixed_images' => 0,
            'fixed_links' => 0,
            'created_translations' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        while ($processed < $batch_size && !empty($queue)) {
            $item = array_shift($queue);
            $processed++;
            $type = (string) ($item['type'] ?? '');
            $post_id = (int) ($item['post_id'] ?? 0);

            if (!$post_id) {
                $report_updates['skipped']++;
                continue;
            }

            $had_error = false;

            if ($type === 'repair_image_group') {
                $res = self::process_featured_image_group($post_id);
                $report_updates['fixed_images'] += (int) ($res['images_set'] ?? 0);
                $report_updates['errors'] += (int) ($res['errors'] ?? 0);
                $report_updates['skipped'] += (int) ($res['skipped'] ?? 0) + (int) ($res['image_no_match'] ?? 0);
                $had_error = !empty($res['errors']);
            } elseif ($type === 'repair_translation_link') {
                $source_id = (int) ($item['candidate_source_id'] ?? 0);
                $ok = self::repair_translation_link($post_id, $source_id);
                if ($ok) {
                    $report_updates['fixed_links']++;
                    self::process_featured_image_group($post_id);
                } else {
                    $report_updates['errors']++;
                    $had_error = true;
                }
            } elseif ($type === 'repair_missing_translation') {
                $res = self::translate_post_if_needed($post_id);
                $report_updates['created_translations'] += (int) ($res['translations_created'] ?? 0);
                $report_updates['fixed_links'] += (int) ($res['translations_linked'] ?? 0);
                $report_updates['errors'] += (int) ($res['errors'] ?? 0);
                $report_updates['skipped'] += (int) ($res['skipped'] ?? 0) + (int) ($res['translation_failed'] ?? 0);
                $had_error = !empty($res['errors']);
            } else {
                $report_updates['skipped']++;
                self::log('warning', 'Unknown repair queue item skipped.', $post_id, ['type' => $type]);
            }

            if ($had_error) {
                self::add_retry_item($item);
            }
        }

        self::set_repair_queue($queue);

        $current = self::get_repair_report();
        self::update_repair_report([
            'fixed_images' => (int) $current['fixed_images'] + (int) $report_updates['fixed_images'],
            'fixed_links' => (int) $current['fixed_links'] + (int) $report_updates['fixed_links'],
            'created_translations' => (int) $current['created_translations'] + (int) $report_updates['created_translations'],
            'skipped' => (int) $current['skipped'] + (int) $report_updates['skipped'],
            'errors' => (int) $current['errors'] + (int) $report_updates['errors'],
            'queue_remaining' => count($queue),
        ]);

        $background = self::get_background_status();
        self::update_background_status([
            'errors' => (int) $background['errors'] + (int) $report_updates['errors'],
            'queue_remaining' => count($queue),
        ]);

        self::log('info', 'Repair queue step processed.', 0, [
            'processed' => $processed,
            'queue_remaining' => count($queue),
            'fixed_images' => $report_updates['fixed_images'],
            'fixed_links' => $report_updates['fixed_links'],
            'created_translations' => $report_updates['created_translations'],
            'errors' => $report_updates['errors'],
        ]);
    }

    private static function repair_translation_link(int $translation_post_id, int $source_post_id): bool {
        if (!self::polylang_available() || !$translation_post_id || !$source_post_id) {
            return false;
        }

        $source_lang = self::get_source_language_slug();
        $translation_lang = pll_get_post_language($translation_post_id);

        if (!$translation_lang || $translation_lang === $source_lang) {
            self::log('warning', 'Translation relink skipped because target post language is missing or is source language.', $translation_post_id, [
                'source_post_id' => $source_post_id,
                'translation_lang' => $translation_lang,
            ]);
            return false;
        }

        $languages = pll_languages_list(['fields' => 'slug']);
        $map = self::get_existing_translation_map($source_post_id, $source_lang, $languages);
        $map[$source_lang] = $source_post_id;

        if (!empty($map[$translation_lang]) && (int) $map[$translation_lang] !== $translation_post_id) {
            self::log('warning', 'Translation relink skipped because source already has a different post for this language.', $translation_post_id, [
                'source_post_id' => $source_post_id,
                'translation_lang' => $translation_lang,
                'existing_post_id' => (int) $map[$translation_lang],
            ]);
            return false;
        }

        $translation_dates = self::capture_post_dates($translation_post_id);
        $source_dates = self::capture_post_dates($source_post_id);

        $map[$translation_lang] = $translation_post_id;
        pll_save_post_translations($map);

        if ($translation_dates) {
            self::restore_post_dates($translation_post_id, $translation_dates);
        }
        if ($source_dates) {
            self::restore_post_dates($source_post_id, $source_dates);
        }

        self::log('info', 'Polylang translation relationship repaired.', $translation_post_id, [
            'source_post_id' => $source_post_id,
            'translation_lang' => $translation_lang,
        ]);

        return true;
    }

    public static function get_dashboard_counts(): array {
        $counts = [
            'posts_without_featured_images' => self::count_posts_without_featured_images(),
            'posts_without_translated_alternatives' => 0,
            'total_missing_language_versions' => 0,
            'missing_by_language' => [],
            'missing_by_language_html' => '<p class="cq-afi-muted">Polylang not available or no languages configured.</p>',
        ];

        if (!self::polylang_available()) {
            return $counts;
        }

        $source_lang = self::get_source_language_slug();
        $languages = pll_languages_list(['fields' => 'slug']);

        if (!$languages || count($languages) < 2) {
            return $counts;
        }

        foreach ($languages as $lang) {
            if ($lang !== $source_lang) {
                $counts['missing_by_language'][$lang] = 0;
            }
        }

        $source_posts = self::get_source_post_ids(-1);
        foreach ($source_posts as $post_id) {
            $translations = self::get_existing_translation_map((int) $post_id, $source_lang, $languages);
            $has_alt = false;

            foreach ($languages as $lang) {
                if ($lang === $source_lang) {
                    continue;
                }

                if (!empty($translations[$lang])) {
                    $has_alt = true;
                } else {
                    $counts['total_missing_language_versions']++;
                    $counts['missing_by_language'][$lang]++;
                }
            }

            if (!$has_alt) {
                $counts['posts_without_translated_alternatives']++;
            }
        }

        $html = '<ul>';
        foreach ($counts['missing_by_language'] as $lang => $missing) {
            $html .= '<li><strong>' . esc_html($lang) . ':</strong> ' . esc_html((string) $missing) . '</li>';
        }
        $html .= '</ul>';
        $counts['missing_by_language_html'] = $html;

        return $counts;
    }

    private static function count_posts_without_featured_images(): int {
        return count(self::get_missing_featured_image_post_ids(-1, true));
    }

    private static function count_invalid_thumbnail_meta_posts(): int {
        $count = 0;
        foreach (self::get_all_processable_post_ids(-1) as $post_id) {
            $raw = get_post_meta((int) $post_id, '_thumbnail_id', true);
            if ($raw !== '' && !self::post_has_valid_featured_image((int) $post_id)) {
                $count++;
            }
        }
        return $count;
    }

    private static function get_all_processable_post_ids(int $limit = -1): array {
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => self::get_processable_post_statuses(),
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        return array_values(array_map('intval', $posts));
    }

    private static function get_processable_post_statuses(): array {
        /**
         * Filter the post statuses included in dashboard counts and processing.
         * Default intentionally includes common non-trash editorial statuses because the WordPress Posts screen may show drafts/pending/future/private posts without featured images.
         */
        return (array) apply_filters('cq_afi_processable_post_statuses', ['publish', 'private', 'future', 'draft', 'pending']);
    }

    private static function post_has_valid_featured_image(int $post_id): bool {
        $thumbnail_id = (int) get_post_meta($post_id, '_thumbnail_id', true);

        if ($thumbnail_id <= 0) {
            return false;
        }

        $attachment = get_post($thumbnail_id);

        if (!$attachment || $attachment->post_type !== 'attachment') {
            return false;
        }

        return wp_attachment_is_image($thumbnail_id);
    }

    private static function get_featured_image_missing_reason(int $post_id): string {
        $raw = get_post_meta($post_id, '_thumbnail_id', true);

        if ($raw === '') {
            return 'missing _thumbnail_id meta';
        }

        $thumbnail_id = (int) $raw;

        if ($thumbnail_id <= 0) {
            return 'empty or zero _thumbnail_id meta';
        }

        $attachment = get_post($thumbnail_id);

        if (!$attachment || $attachment->post_type !== 'attachment') {
            return 'thumbnail attachment does not exist';
        }

        if (!wp_attachment_is_image($thumbnail_id)) {
            return 'thumbnail attachment is not an image';
        }

        return 'featured image is valid';
    }

    private static function get_missing_featured_image_post_ids(int $limit = -1, bool $include_no_match = true): array {
        $ids = [];

        foreach (self::get_all_processable_post_ids(-1) as $post_id) {
            if (!$include_no_match && get_post_meta((int) $post_id, self::META_IMAGE_NO_MATCH, true)) {
                continue;
            }

            if (!self::post_has_valid_featured_image((int) $post_id)) {
                $ids[] = (int) $post_id;
            }

            if ($limit > 0 && count($ids) >= $limit) {
                break;
            }
        }

        return $ids;
    }

    private static function get_source_post_ids(int $limit): array {
        $args = [
            'post_type' => 'post',
            'post_status' => self::get_processable_post_statuses(),
            'fields' => 'ids',
            'posts_per_page' => $limit,
        ];

        $posts = get_posts($args);

        if (!self::polylang_available()) {
            return $posts;
        }

        $source_lang = self::get_source_language_slug();

        // Strict: only posts EXPLICITLY in the source language. Posts in another
        // language — or with no language assigned — are excluded, so source-post
        // candidate lists never leak other languages.
        return array_values(array_filter($posts, function ($post_id) use ($source_lang) {
            return pll_get_post_language((int) $post_id) === $source_lang;
        }));
    }

    private static function count_posts_with_meta(string $meta_key): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            $meta_key
        ));
    }

    private static function get_logs(): array {
        $logs = get_option(self::OPTION_LOGS, []);
        return is_array($logs) ? $logs : [];
    }

    private static function count_logs_by_level(string $level): int {
        $count = 0;

        foreach (self::get_logs() as $log) {
            if (($log['level'] ?? '') === $level) {
                $count++;
            }
        }

        return $count;
    }

    private static function log(string $level, string $message, int $post_id = 0, array $context = []): void {
        $level = in_array($level, ['info', 'warning', 'error'], true) ? $level : 'info';

        $entry = [
            'time' => current_time('mysql'),
            'level' => $level,
            'message' => sanitize_text_field($message),
            'post_id' => $post_id,
            'context' => self::sanitize_log_context($context),
        ];

        $logs = self::get_logs();
        array_unshift($logs, $entry);
        $logs = array_slice($logs, 0, self::MAX_LOGS);

        update_option(self::OPTION_LOGS, $logs, false);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[CQ AFI] ' . strtoupper($level) . ': ' . $message . ($post_id ? ' Post ID: ' . $post_id : ''));
        }
    }

    private static function sanitize_log_context(array $context): array {
        $safe = [];

        foreach ($context as $key => $value) {
            $key = sanitize_key((string) $key);

            if (is_scalar($value) || $value === null) {
                $safe[$key] = sanitize_text_field((string) $value);
            } else {
                $safe[$key] = sanitize_text_field(wp_json_encode($value));
            }
        }

        return $safe;
    }

    private static function render_logs_table(): string {
        $logs = array_slice(self::get_logs(), 0, 75);

        if (!$logs) {
            return '<p class="cq-afi-muted">No plugin log entries yet.</p>';
        }

        $html = '<table class="cq-afi-log-table">';
        $html .= '<thead><tr><th>Time</th><th>Level</th><th>Post</th><th>Message</th><th>Context</th></tr></thead><tbody>';

        foreach ($logs as $log) {
            $level = esc_html($log['level'] ?? 'info');
            $class = 'cq-afi-log-' . sanitize_html_class($level);
            $post_id = (int) ($log['post_id'] ?? 0);

            $post_cell = $post_id
                ? '<a href="' . esc_url(get_edit_post_link($post_id)) . '">#' . esc_html((string) $post_id) . '</a>'
                : '';

            $context = '';
            if (!empty($log['context']) && is_array($log['context'])) {
                $pairs = [];
                foreach ($log['context'] as $key => $value) {
                    $pairs[] = esc_html($key) . ': ' . esc_html((string) $value);
                }
                $context = implode('<br>', $pairs);
            }

            $html .= '<tr>';
            $html .= '<td>' . esc_html($log['time'] ?? '') . '</td>';
            $html .= '<td class="' . esc_attr($class) . '">' . $level . '</td>';
            $html .= '<td>' . $post_cell . '</td>';
            $html .= '<td>' . esc_html($log['message'] ?? '') . '</td>';
            $html .= '<td>' . $context . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    public static function validate_selected_model() {
        $api_key = self::get_openai_api_key();

        if (!$api_key) {
            $validation = [
                'valid' => false,
                'model' => self::get_openai_model(),
                'checked_at' => current_time('mysql'),
                'message' => 'Model was not validated because no OpenAI API key is saved.',
            ];
            update_option(self::OPTION_MODEL_VALIDATION, $validation);
            self::log('error', $validation['message']);
            return new WP_Error('missing_api_key', $validation['message']);
        }

        $model = self::get_openai_model();

        $response = wp_remote_get('https://api.openai.com/v1/models/' . rawurlencode($model), [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
            ],
        ]);

        if (is_wp_error($response)) {
            $validation = [
                'valid' => false,
                'model' => $model,
                'checked_at' => current_time('mysql'),
                'message' => 'Model validation failed: ' . $response->get_error_message(),
            ];
            update_option(self::OPTION_MODEL_VALIDATION, $validation);
            self::log('error', $validation['message']);
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300 && is_array($body) && !empty($body['id'])) {
            $validation = [
                'valid' => true,
                'model' => $model,
                'checked_at' => current_time('mysql'),
                'message' => 'Model "' . $model . '" is available for this OpenAI API key.',
            ];
            update_option(self::OPTION_MODEL_VALIDATION, $validation);
            self::log('info', $validation['message']);
            return true;
        }

        $message = 'Model "' . $model . '" was not validated. It may not exist or may not be available to this OpenAI API key.';
        if (!empty($body['error']['message'])) {
            $message .= ' OpenAI says: ' . sanitize_text_field($body['error']['message']);
        }

        $validation = [
            'valid' => false,
            'model' => $model,
            'checked_at' => current_time('mysql'),
            'message' => $message,
        ];
        update_option(self::OPTION_MODEL_VALIDATION, $validation);
        self::log('error', $message);

        return new WP_Error('invalid_model', $message);
    }

    public static function on_transition_post_status(string $new_status, string $old_status, WP_Post $post): void {
        if ($new_status !== 'publish' || $post->post_type !== 'post') {
            return;
        }

        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return;
        }

        if (get_post_meta($post->ID, self::META_AI_TRANSLATION, true)) {
            return;
        }

        if (!wp_next_scheduled(self::SINGLE_EVENT, [$post->ID])) {
            wp_schedule_single_event(time() + 60, self::SINGLE_EVENT, [$post->ID]);
        }
    }

    public static function run_backfill(): void {
        self::run_processing_batch('hourly');
    }

    public static function run_manual_job(): void {
        self::run_processing_batch('manual');
    }

    private static function run_processing_batch(string $mode): void {
        $batch_size = max(1, min(100, absint(get_option(self::OPTION_BATCH_SIZE, 10))));
        $post_ids = self::get_posts_needing_processing($batch_size);

        self::update_job_status([
            'state' => 'running',
            'mode' => $mode,
            'started_at' => current_time('mysql'),
            'finished_at' => '',
            'processed' => 0,
            'total' => count($post_ids),
            'images_set' => 0,
            'image_no_match' => 0,
            'translations_created' => 0,
            'translation_failed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'message' => ucfirst($mode) . ' job is running.',
        ]);

        self::log('info', ucfirst($mode) . ' processing batch started.', 0, [
            'batch_size' => $batch_size,
            'queued_posts' => count($post_ids),
        ]);

        foreach ($post_ids as $index => $post_id) {
            $result = self::process_post((int) $post_id);
            $job = self::get_job_status();

            foreach (['images_set', 'image_no_match', 'translations_created', 'translation_failed', 'skipped', 'errors'] as $metric) {
                $job[$metric] = (int) ($job[$metric] ?? 0) + (int) ($result[$metric] ?? 0);
            }

            $job['processed'] = $index + 1;
            $job['message'] = self::format_process_result_message((int) $post_id, $result);

            update_option(self::OPTION_JOB_STATUS, $job);
        }

        $final = self::get_job_status();
        $summary = count($post_ids)
            ? sprintf(
                'Batch completed. Images set: %d. No image match: %d. Translations created: %d. Translation failed: %d. Errors: %d.',
                (int) $final['images_set'],
                (int) $final['image_no_match'],
                (int) $final['translations_created'],
                (int) $final['translation_failed'],
                (int) $final['errors']
            )
            : 'No eligible posts needed processing.';

        self::update_job_status([
            'state' => 'idle',
            'finished_at' => current_time('mysql'),
            'message' => $summary,
        ]);

        self::log('info', $summary);
    }

    private static function format_process_result_message(int $post_id, array $result): string {
        $parts = [];

        if (!empty($result['images_set'])) {
            $parts[] = 'featured image set';
        }

        if (!empty($result['image_no_match'])) {
            $parts[] = 'no suitable image found';
        }

        if (!empty($result['translations_created'])) {
            $parts[] = 'translation created';
        }

        if (!empty($result['translation_failed'])) {
            $parts[] = 'translation failed';
        }

        if (!empty($result['skipped'])) {
            $parts[] = 'skipped';
        }

        if (!empty($result['errors'])) {
            $parts[] = 'error';
        }

        return 'Processed post ID ' . $post_id . ': ' . ($parts ? implode(', ', $parts) : 'no changes');
    }

    private static function get_posts_needing_processing(int $limit): array {
        $ids = [];

        $missing_image_ids = self::get_missing_featured_image_post_ids($limit, true);
        foreach ($missing_image_ids as $post_id) {
            $ids[] = (int) $post_id;

            self::log('info', 'Post queued for featured image processing.', (int) $post_id, [
                'missing_reason' => self::get_featured_image_missing_reason((int) $post_id),
                'previous_no_match' => get_post_meta((int) $post_id, self::META_IMAGE_NO_MATCH, true) ? 'yes' : 'no',
            ]);
        }

        if (count($ids) < $limit && self::polylang_available()) {
            $source_posts = self::get_source_post_ids(-1);
            $source_lang = self::get_source_language_slug();
            $languages = pll_languages_list(['fields' => 'slug']);

            foreach ($source_posts as $post_id) {
                if (count($ids) >= $limit) {
                    break;
                }

                if (in_array((int) $post_id, $ids, true)) {
                    continue;
                }

                $map = self::get_existing_translation_map((int) $post_id, $source_lang, $languages);
                $has_alt = false;

                foreach ($languages as $lang) {
                    if ($lang === $source_lang) {
                        continue;
                    }

                    if (!empty($map[$lang])) {
                        $has_alt = true;
                        break;
                    }
                }

                // Per requirement, the process only needs to ensure there is more than English.
                if (!$has_alt) {
                    $ids[] = (int) $post_id;
                    self::log('info', 'Post queued for translation processing because it has no translated alternative.', (int) $post_id, [
                        'source_lang' => $source_lang,
                    ]);
                }
            }
        }

        $ids = array_values(array_unique(array_map('intval', array_slice($ids, 0, $limit))));
        self::log('info', 'Processing queue built.', 0, [
            'limit' => $limit,
            'queued_count' => count($ids),
            'post_ids' => implode(',', $ids),
        ]);

        return $ids;
    }

    public static function process_post(int $post_id): array {
        $result = [
            'images_set' => 0,
            'image_no_match' => 0,
            'translations_created' => 0,
            'translation_failed' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'post' || !in_array($post->post_status, self::get_processable_post_statuses(), true)) {
            $result['skipped'] = 1;
            self::log('warning', 'Post skipped because it is missing, unsupported status, or not a post.', $post_id, [
                'status' => $post ? $post->post_status : 'missing',
                'type' => $post ? $post->post_type : 'missing',
            ]);
            return $result;
        }

        if (self::is_locked($post_id)) {
            $result['skipped'] = 1;
            self::log('warning', 'Post skipped because it is already locked by another process.', $post_id);
            return $result;
        }

        self::lock($post_id);
        $original_dates = self::capture_post_dates($post_id);

        try {
            $image_result = self::process_featured_image_group($post_id);
            $result['images_set'] += (int) ($image_result['images_set'] ?? 0);
            $result['image_no_match'] += (int) ($image_result['image_no_match'] ?? 0);
            $result['errors'] += (int) ($image_result['errors'] ?? 0);
            $result['skipped'] += (int) ($image_result['skipped'] ?? 0);

            update_post_meta($post_id, self::META_ATTEMPTED, current_time('mysql'));

            $translation_result = self::translate_post_if_needed($post_id);
            $result['translations_created'] += (int) ($translation_result['translations_created'] ?? 0);
            $result['translation_failed'] += (int) ($translation_result['translation_failed'] ?? 0);
            $result['skipped'] += (int) ($translation_result['skipped'] ?? 0);
            $result['errors'] += (int) ($translation_result['errors'] ?? 0);
        } catch (Throwable $e) {
            $result['errors'] = 1;
            self::log('error', 'Unhandled plugin error while processing post: ' . $e->getMessage(), $post_id, [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        } finally {
            if (!empty($original_dates)) {
                self::restore_post_dates($post_id, $original_dates);
            }
            self::unlock($post_id);
        }

        return $result;
    }

    private static function is_locked(int $post_id): bool {
        $lock = (int) get_post_meta($post_id, self::META_LOCK, true);
        return $lock && $lock > (time() - 15 * MINUTE_IN_SECONDS);
    }

    private static function lock(int $post_id): void {
        update_post_meta($post_id, self::META_LOCK, time());
    }

    private static function unlock(int $post_id): void {
        delete_post_meta($post_id, self::META_LOCK);
    }

    private static function process_featured_image_group(int $post_id): array {
        $result = [
            'images_set' => 0,
            'image_no_match' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $group = self::get_polylang_post_group($post_id);
        $source_post_id = (int) $group['source_post_id'];
        $group_post_ids = array_values(array_unique(array_filter(array_map('intval', $group['post_ids']))));

        if (!$source_post_id) {
            $source_post_id = $post_id;
        }

        if (!$group_post_ids) {
            $group_post_ids = [$post_id];
        }

        $source_post = get_post($source_post_id);

        if (!$source_post) {
            $result['errors'] = 1;
            self::log('error', 'Featured image group processing failed because the source-language post could not be loaded.', $post_id, [
                'source_post_id' => $source_post_id,
            ]);
            return $result;
        }

        $thumbnail_id = 0;
        $source_thumbnail_id = self::post_has_valid_featured_image($source_post_id) ? (int) get_post_meta($source_post_id, '_thumbnail_id', true) : 0;

        if ($source_thumbnail_id) {
            $thumbnail_id = $source_thumbnail_id;
            self::log('info', 'Using existing source-language featured image for translation group.', $post_id, [
                'source_post_id' => $source_post_id,
                'attachment_id' => $thumbnail_id,
            ]);
        } else {
            foreach ($group_post_ids as $group_post_id) {
                $existing = self::post_has_valid_featured_image((int) $group_post_id) ? (int) get_post_meta((int) $group_post_id, '_thumbnail_id', true) : 0;
                if ($existing) {
                    $thumbnail_id = $existing;
                    self::log('info', 'Using existing translated-post featured image for translation group.', $post_id, [
                        'source_post_id' => $source_post_id,
                        'existing_post_id' => $group_post_id,
                        'attachment_id' => $thumbnail_id,
                    ]);
                    break;
                }
            }
        }

        if (!$thumbnail_id) {
            $match = self::find_best_image_for_title($source_post->post_title);

            if (empty($match['id'])) {
                foreach ($group_post_ids as $group_post_id) {
                    update_post_meta($group_post_id, self::META_IMAGE_NO_MATCH, current_time('mysql'));
                    update_post_meta($group_post_id, self::META_IMAGE_NO_MATCH_SCORE, (float) ($match['score'] ?? 0));
                    self::log('warning', 'Post still has no featured image after source-title matching attempt.', (int) $group_post_id, [
                        'missing_reason' => self::get_featured_image_missing_reason((int) $group_post_id),
                        'source_post_id' => $source_post_id,
                        'best_score' => (float) ($match['score'] ?? 0),
                    ]);
                }

                $result['image_no_match'] = 1;

                self::log('warning', 'No suitable featured image found for source-language title. Marked translation group as no-match.', $post_id, [
                    'source_post_id' => $source_post_id,
                    'source_title' => $source_post->post_title,
                    'best_score' => (float) ($match['score'] ?? 0),
                    'reason' => (string) ($match['reason'] ?? ''),
                    'threshold' => (int) get_option(self::OPTION_MIN_IMAGE_SCORE, 45),
                    'group_post_ids' => implode(',', $group_post_ids),
                ]);

                return $result;
            }

            $thumbnail_id = (int) $match['id'];

            update_post_meta($source_post_id, self::META_IMAGE_SCORE, (float) $match['score']);
            update_post_meta($source_post_id, self::META_IMAGE_REASON, sanitize_text_field($match['reason']));

            self::log('info', 'Matched featured image using source-language title.', $source_post_id, [
                'attachment_id' => $thumbnail_id,
                'score' => (float) $match['score'],
                'source_title' => $source_post->post_title,
                'group_post_ids' => implode(',', $group_post_ids),
            ]);
        }

        foreach ($group_post_ids as $group_post_id) {
            if (self::post_has_valid_featured_image((int) $group_post_id)) {
                continue;
            }

            $group_post_dates = self::capture_post_dates($group_post_id);

            if (set_post_thumbnail($group_post_id, $thumbnail_id)) {
                if ($group_post_dates) {
                    self::restore_post_dates($group_post_id, $group_post_dates);
                }
                delete_post_meta($group_post_id, self::META_IMAGE_NO_MATCH);
                delete_post_meta($group_post_id, self::META_IMAGE_NO_MATCH_SCORE);
                update_post_meta($group_post_id, self::META_IMAGE_SCORE, (float) get_post_meta($source_post_id, self::META_IMAGE_SCORE, true));
                update_post_meta($group_post_id, self::META_IMAGE_REASON, 'Assigned from Polylang translation group source image.');
                $result['images_set']++;

                self::log('info', 'Featured image assigned to post in translation group.', $group_post_id, [
                    'source_post_id' => $source_post_id,
                    'attachment_id' => $thumbnail_id,
                ]);
            } else {
                if ($group_post_dates) {
                    self::restore_post_dates($group_post_id, $group_post_dates);
                }
                $result['errors']++;
                self::log('error', 'WordPress failed to assign featured image to post in translation group.', $group_post_id, [
                    'source_post_id' => $source_post_id,
                    'attachment_id' => $thumbnail_id,
                ]);
            }
        }

        if (!$result['images_set']) {
            $result['skipped'] = 1;
        }

        return $result;
    }

    private static function get_polylang_post_group(int $post_id): array {
        $source_lang = self::get_source_language_slug();
        $source_post_id = $post_id;
        $post_ids = [$post_id];

        if (!self::polylang_available()) {
            return [
                'source_post_id' => $source_post_id,
                'post_ids' => $post_ids,
            ];
        }

        $languages = pll_languages_list(['fields' => 'slug']);

        if (!$languages) {
            return [
                'source_post_id' => $source_post_id,
                'post_ids' => $post_ids,
            ];
        }

        $current_lang = pll_get_post_language($post_id);
        $maybe_source_id = pll_get_post($post_id, $source_lang);

        if ($maybe_source_id) {
            $source_post_id = (int) $maybe_source_id;
        } elseif (!$current_lang || $current_lang === $source_lang) {
            $source_post_id = $post_id;
        } else {
            $repaired_source_id = self::maybe_repair_polylang_translation_link($post_id, $current_lang, $source_lang);
            $source_post_id = $repaired_source_id ? $repaired_source_id : $post_id;
        }

        foreach ($languages as $lang) {
            $translated_id = pll_get_post($source_post_id, $lang);
            if ($translated_id) {
                $post_ids[] = (int) $translated_id;
            }
        }

        if (!in_array($source_post_id, $post_ids, true)) {
            $post_ids[] = $source_post_id;
        }

        if (!in_array($post_id, $post_ids, true)) {
            $post_ids[] = $post_id;
        }

        return [
            'source_post_id' => $source_post_id,
            'post_ids' => array_values(array_unique(array_filter(array_map('intval', $post_ids)))),
        ];
    }

    private static function maybe_repair_polylang_translation_link(int $translated_post_id, string $translated_lang, string $source_lang): int {
        if (!self::polylang_available() || !$translated_lang || $translated_lang === $source_lang) {
            return 0;
        }

        $existing_source = pll_get_post($translated_post_id, $source_lang);
        if ($existing_source) {
            return (int) $existing_source;
        }

        if (!get_post($translated_post_id)) {
            return 0;
        }

        // Authoritative link: translations this plugin created carry the exact source
        // post ID in META_SOURCE_POST. Trust that over any cross-language text guess —
        // foreign titles/slugs barely overlap their English originals, so the heuristic
        // below can otherwise mislink to an unrelated same-author/same-category post.
        $claimed_source = (int) get_post_meta($translated_post_id, self::META_SOURCE_POST, true);
        if ($claimed_source) {
            $claimed_post = get_post($claimed_source);
            $claimed_valid = $claimed_post
                && $claimed_post->post_type === 'post'
                && pll_get_post_language($claimed_source) === $source_lang;

            if ($claimed_valid) {
                if (self::repair_translation_link($translated_post_id, $claimed_source)) {
                    update_post_meta($translated_post_id, self::META_POLYLANG_RELINK_ATTEMPTED, current_time('mysql'));
                    update_post_meta($translated_post_id, self::META_POLYLANG_RELINK_SCORE, 100);
                    self::log('info', 'Polylang association repaired from authoritative source meta.', $translated_post_id, [
                        'source_post_id' => $claimed_source,
                        'translated_lang' => $translated_lang,
                        'source_lang' => $source_lang,
                    ]);
                    return $claimed_source;
                }

                // We had authoritative info but could not link (e.g. the source already
                // has a different post for this language). Do NOT fall back to guessing.
                update_post_meta($translated_post_id, self::META_POLYLANG_RELINK_ATTEMPTED, current_time('mysql'));
                self::log('warning', 'Authoritative source meta could not be linked; skipping heuristic match to avoid a wrong link.', $translated_post_id, [
                    'claimed_source' => $claimed_source,
                    'translated_lang' => $translated_lang,
                ]);
                return 0;
            }

            self::log('warning', 'Stored source post meta was invalid; falling back to heuristic matching.', $translated_post_id, [
                'claimed_source' => $claimed_source,
            ]);
        }

        $candidates = self::find_source_post_candidates_for_unlinked_translation($translated_post_id, $translated_lang, $source_lang);

        if (!$candidates) {
            update_post_meta($translated_post_id, self::META_POLYLANG_RELINK_ATTEMPTED, current_time('mysql'));
            self::log('warning', 'Polylang relink skipped. No source-language candidates found for unlinked translated post.', $translated_post_id, [
                'translated_lang' => $translated_lang,
                'source_lang' => $source_lang,
            ]);
            return 0;
        }

        $best = $candidates[0];
        $second_score = isset($candidates[1]) ? (float) $candidates[1]['score'] : 0.0;
        $score = (float) $best['score'];
        $margin = $score - $second_score;

        update_post_meta($translated_post_id, self::META_POLYLANG_RELINK_ATTEMPTED, current_time('mysql'));
        update_post_meta($translated_post_id, self::META_POLYLANG_RELINK_SCORE, $score);

        if ($score < 65 || ($second_score > 0 && $margin < 12)) {
            self::log('warning', 'Polylang relink skipped because the source match was not clear enough.', $translated_post_id, [
                'translated_lang' => $translated_lang,
                'best_source_post_id' => (int) $best['post_id'],
                'best_score' => $score,
                'second_score' => $second_score,
                'margin' => $margin,
                'reason' => (string) $best['reason'],
            ]);
            return 0;
        }

        $source_post_id = (int) $best['post_id'];
        $languages = pll_languages_list(['fields' => 'slug']);
        $map = self::get_existing_translation_map($source_post_id, $source_lang, $languages);
        $map[$source_lang] = $source_post_id;

        if (!empty($map[$translated_lang]) && (int) $map[$translated_lang] !== $translated_post_id) {
            self::log('warning', 'Polylang relink skipped because the source post already has a different post for that language.', $translated_post_id, [
                'translated_lang' => $translated_lang,
                'source_post_id' => $source_post_id,
                'existing_translated_post_id' => (int) $map[$translated_lang],
                'candidate_score' => $score,
            ]);
            return 0;
        }

        $map[$translated_lang] = $translated_post_id;
        pll_save_post_translations($map);

        self::log('info', 'Polylang association repaired for unlinked translated post.', $translated_post_id, [
            'source_post_id' => $source_post_id,
            'translated_lang' => $translated_lang,
            'source_lang' => $source_lang,
            'score' => $score,
            'reason' => (string) $best['reason'],
        ]);

        return $source_post_id;
    }

    private static function find_source_post_candidates_for_unlinked_translation(int $translated_post_id, string $translated_lang, string $source_lang): array {
        $translated_post = get_post($translated_post_id);
        if (!$translated_post) {
            return [];
        }

        $translated_slug_key = self::normalize_slug_for_matching($translated_post->post_name, $translated_lang);
        $translated_title = self::normalize_text($translated_post->post_title);
        $translated_terms = self::get_post_term_id_set($translated_post_id);
        $translated_date = strtotime($translated_post->post_date_gmt ?: $translated_post->post_date);
        $candidates = [];

        foreach (self::get_source_post_ids(-1) as $source_post_id) {
            $source_post_id = (int) $source_post_id;
            if ($source_post_id === $translated_post_id) {
                continue;
            }
            if (pll_get_post($source_post_id, $translated_lang)) {
                continue;
            }
            $source_post = get_post($source_post_id);
            if (!$source_post || $source_post->post_type !== 'post') {
                continue;
            }
            $source_slug_key = self::normalize_slug_for_matching($source_post->post_name, $source_lang);
            $source_title = self::normalize_text($source_post->post_title);
            similar_text($translated_slug_key, $source_slug_key, $slug_percent);
            similar_text($translated_title, $source_title, $title_percent);
            $score = max($slug_percent, $title_percent * 0.75);
            $reasons = [];
            if ($translated_slug_key && $source_slug_key && $translated_slug_key === $source_slug_key) {
                $score += 35;
                $reasons[] = 'normalized slug exact match';
            } elseif ($slug_percent >= 80) {
                $score += 15;
                $reasons[] = 'strong slug similarity';
            }
            if ((int) $source_post->post_author === (int) $translated_post->post_author) {
                $score += 5;
                $reasons[] = 'same author';
            }
            $source_terms = self::get_post_term_id_set($source_post_id);
            $term_overlap = count(array_intersect($translated_terms, $source_terms));
            if ($term_overlap > 0) {
                $score += min(12, $term_overlap * 4);
                $reasons[] = 'taxonomy overlap';
            }
            $source_date = strtotime($source_post->post_date_gmt ?: $source_post->post_date);
            if ($translated_date && $source_date) {
                $days_apart = abs($translated_date - $source_date) / DAY_IN_SECONDS;
                if ($days_apart <= 3) {
                    $score += 8;
                    $reasons[] = 'published within 3 days';
                } elseif ($days_apart <= 30) {
                    $score += 3;
                    $reasons[] = 'published within 30 days';
                }
            }
            $score = min(100, round($score, 2));
            if ($score > 0) {
                $candidates[] = [
                    'post_id' => $source_post_id,
                    'score' => $score,
                    'reason' => $reasons ? implode(', ', $reasons) : 'title/slug similarity',
                ];
            }
        }
        usort($candidates, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        return $candidates;
    }

    private static function normalize_slug_for_matching(string $slug, string $lang = ''): string {
        $slug = strtolower($slug);
        if ($lang) {
            $slug = preg_replace('/-(?:' . preg_quote($lang, '/') . ')$/', '', $slug);
            $slug = preg_replace('/^(?:' . preg_quote($lang, '/') . ')-/', '', $slug);
        }
        $slug = preg_replace('/-(?:en|es|fr|de|it|pt|ar|zh|ja|ko|ru|hi)$/', '', $slug);
        $slug = preg_replace('/^(?:en|es|fr|de|it|pt|ar|zh|ja|ko|ru|hi)-/', '', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', ' ', $slug);
        $slug = preg_replace('/\s+/', ' ', $slug);
        return trim((string) $slug);
    }

    private static function get_post_term_id_set(int $post_id): array {
        $ids = [];
        $taxonomies = get_object_taxonomies('post');
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy === 'language' || $taxonomy === 'post_translations') {
                continue;
            }
            $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
            if (!is_wp_error($terms)) {
                $ids = array_merge($ids, array_map('intval', $terms));
            }
        }
        return array_values(array_unique($ids));
    }

    public static function find_best_image_for_title(string $raw_title): array {
        $title = self::normalize_text($raw_title);
        $min_score = max(1, min(100, absint(get_option(self::OPTION_MIN_IMAGE_SCORE, 45))));

        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => 500,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $best = ['id' => 0, 'score' => 0, 'reason' => 'No matching image found'];

        foreach ($attachments as $attachment) {
            $file = get_attached_file($attachment->ID);
            $filename = $file ? pathinfo($file, PATHINFO_FILENAME) : '';

            $candidate = self::normalize_text(implode(' ', [
                $attachment->post_title,
                $attachment->post_excerpt,
                $attachment->post_content,
                $filename,
            ]));

            if (!$candidate) {
                continue;
            }

            similar_text($title, $candidate, $percent);

            $title_words = self::important_words($title);
            $candidate_words = self::important_words($candidate);
            $overlap = count(array_intersect($title_words, $candidate_words));
            $slug_bonus = self::slugish_contains_bonus($title, $candidate);
            $score = min(100, $percent + ($overlap * 12) + $slug_bonus);

            if ($score > $best['score']) {
                $best = [
                    'id' => (int) $attachment->ID,
                    'score' => round($score, 2),
                    'reason' => 'Best source-language title/filename similarity match',
                ];
            }
        }

        if ($best['score'] < $min_score) {
            return ['id' => 0, 'score' => $best['score'], 'reason' => 'Best score below configured threshold'];
        }

        return $best;
    }

    public static function find_best_image_for_post(int $post_id): array {
        $post = get_post($post_id);

        if (!$post) {
            return ['id' => 0, 'score' => 0, 'reason' => 'Post not found'];
        }

        return self::find_best_image_for_title($post->post_title);
    }

    private static function normalize_text(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strtolower($text);
        $text = preg_replace('/[-_]+/', ' ', $text);
        $text = preg_replace('/[^a-z0-9 ]+/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string) $text);
    }

    private static function important_words(string $text): array {
        $stop_words = ['a','an','and','are','as','at','be','by','for','from','how','in','is','it','of','on','or','that','the','this','to','with','your','you'];
        $words = array_filter(explode(' ', $text), function ($word) use ($stop_words) {
            return strlen($word) > 2 && !in_array($word, $stop_words, true);
        });
        return array_values(array_unique($words));
    }

    private static function slugish_contains_bonus(string $title, string $candidate): int {
        $title_words = self::important_words($title);
        if (!$title_words) {
            return 0;
        }
        return strpos($candidate, implode(' ', $title_words)) !== false ? 20 : 0;
    }

    public static function translate_post_if_needed(int $source_post_id): array {
        $result = [
            'translations_created' => 0,
            'translations_linked' => 0,
            'translation_failed' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        if (!self::polylang_available()) {
            $result['skipped'] = 1;
            self::log('warning', 'Translation skipped because Polylang functions are not available.', $source_post_id);
            return $result;
        }

        $source_lang = self::get_source_language_slug();
        $current_lang = pll_get_post_language($source_post_id);

        if (!$current_lang) {
            pll_set_post_language($source_post_id, $source_lang);
            $current_lang = $source_lang;
            self::log('info', 'Source language was missing and has been set.', $source_post_id, ['source_lang' => $source_lang]);
        }

        // If the post is itself a non-source-language post, redirect the work to its
        // real source (via authoritative meta or Polylang) so the whole group is filled.
        if ($current_lang !== $source_lang) {
            $resolved = self::resolve_unlinked_translation_source($source_post_id, $source_lang);
            $linked_source = pll_get_post($source_post_id, $source_lang);
            $real_source = $linked_source ? (int) $linked_source : (int) ($resolved['post_id'] ?? 0);

            if ($real_source && $real_source !== $source_post_id) {
                self::log('info', 'Translation request redirected from a translation post to its source.', $source_post_id, [
                    'source_post_id' => $real_source,
                ]);
                return self::translate_post_if_needed($real_source);
            }

            $result['skipped'] = 1;
            self::log('info', 'Translation skipped because post is not in the configured source language and no source could be resolved.', $source_post_id, [
                'post_lang' => $current_lang,
                'source_lang' => $source_lang,
            ]);
            return $result;
        }

        $languages = pll_languages_list(['fields' => 'slug']);

        if (!$languages || count($languages) < 2 || !in_array($source_lang, $languages, true)) {
            $result['skipped'] = 1;
            self::log('warning', 'Translation skipped because Polylang has fewer than two languages or source language is not configured.', $source_post_id, [
                'source_lang' => $source_lang,
                'languages' => $languages,
            ]);
            return $result;
        }

        // Build the picture from BOTH signals: Polylang's own links AND any orphaned
        // translations that still carry this post's ID in _cq_afi_source_post_id.
        $translation_map = self::get_existing_translation_map($source_post_id, $source_lang, $languages);
        $translation_map[$source_lang] = $source_post_id;
        $orphans = self::get_orphan_translations_for_source($source_post_id, $source_lang);

        $last_error = '';
        $any_failure = false;

        // Fill EVERY missing language: link an existing orphan if one exists, otherwise create.
        foreach ($languages as $target_lang) {
            if ($target_lang === $source_lang || !empty($translation_map[$target_lang])) {
                continue; // Source language, or already linked in Polylang.
            }

            // Prefer relinking an existing orphaned translation over creating a duplicate.
            if (!empty($orphans[$target_lang])) {
                $orphan_id = (int) $orphans[$target_lang];
                if (self::repair_translation_link($orphan_id, $source_post_id)) {
                    $translation_map[$target_lang] = $orphan_id;
                    $result['translations_linked']++;
                    self::log('info', 'Existing orphaned translation relinked to its source instead of recreating it.', $source_post_id, [
                        'target_lang' => $target_lang,
                        'translated_post_id' => $orphan_id,
                    ]);
                    continue;
                }
                // Relink failed (e.g. a conflicting post already holds this language); fall through to create.
                self::log('warning', 'Could not relink an orphaned translation; will attempt to create one.', $source_post_id, [
                    'target_lang' => $target_lang,
                    'orphan_post_id' => $orphan_id,
                ]);
            }

            $new_post_id = self::create_translation($source_post_id, $source_lang, $target_lang, $translation_map);

            if ($new_post_id) {
                $translation_map[$target_lang] = $new_post_id;
                pll_save_post_translations($translation_map);
                $result['translations_created']++;
                self::log('info', 'Translation created and associated with source post.', $source_post_id, [
                    'target_lang' => $target_lang,
                    'translated_post_id' => $new_post_id,
                ]);
                continue;
            }

            $any_failure = true;
            $last_error = 'Translation failed for target language "' . $target_lang . '".';
            self::log('error', $last_error, $source_post_id, ['target_lang' => $target_lang]);
        }

        if ($result['translations_created'] > 0 || $result['translations_linked'] > 0) {
            delete_post_meta($source_post_id, self::META_TRANSLATION_FAILED);
            delete_post_meta($source_post_id, self::META_TRANSLATION_FAILED_REASON);
        }

        if ($any_failure) {
            update_post_meta($source_post_id, self::META_TRANSLATION_FAILED, current_time('mysql'));
            update_post_meta($source_post_id, self::META_TRANSLATION_FAILED_REASON, $last_error ?: 'One or more translations could not be created.');
            $result['translation_failed'] = 1;
            $result['errors'] = 1;
        }

        if ($result['translations_created'] === 0 && $result['translations_linked'] === 0 && !$any_failure) {
            $result['skipped'] = 1;
        }

        return $result;
    }

    /**
     * Public integration entry point. Fired via do_action('cq_afi_retranslate_post', $source_post_id)
     * by other plugins (e.g. the CQC Yoast Optimizer) when a source-language post changes.
     *
     * Defers the work to a single WP-Cron event and spawns cron immediately, mirroring
     * schedule_manual_run() so the calling request never blocks or times out.
     */
    public static function schedule_retranslate($source_post_id): void {
        $source_post_id = (int) $source_post_id;

        if (!$source_post_id) {
            return;
        }

        if (wp_next_scheduled(self::RETRANSLATE_EVENT, [$source_post_id])) {
            return; // Already queued for this post.
        }

        $scheduled = wp_schedule_single_event(time() + 10, self::RETRANSLATE_EVENT, [$source_post_id]);

        if ($scheduled === false) {
            self::log('error', 'Failed to schedule re-translation event; running inline as a fallback.', $source_post_id);
            self::retranslate_existing_translations($source_post_id);
            return;
        }

        self::log('info', 'Re-translation of existing translations scheduled.', $source_post_id, ['event' => self::RETRANSLATE_EVENT]);

        $spawn = wp_remote_post(site_url('wp-cron.php?doing_wp_cron=' . urlencode((string) microtime(true))), [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);

        if (is_wp_error($spawn)) {
            self::log('warning', 'Attempt to spawn WP-Cron for re-translation failed: ' . $spawn->get_error_message(), $source_post_id);
        }
    }

    /**
     * Re-translate every EXISTING translation of a source-language post from its current
     * content, updating each foreign post in place (same ID/URL). A revision of each foreign
     * post is saved first so the change can be rolled back. Original publish dates are preserved.
     *
     * Unlike translate_post_if_needed(), this updates posts that already have translations —
     * it does not create missing ones.
     *
     * @param int $source_post_id Source-language post ID.
     * @return array Metrics.
     */
    public static function retranslate_existing_translations($source_post_id): array {
        $source_post_id = (int) $source_post_id;
        $result = [
            'translations_updated' => 0,
            'translation_failed' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        if (!self::polylang_available()) {
            $result['skipped'] = 1;
            self::log('warning', 'Re-translation skipped because Polylang functions are not available.', $source_post_id);
            return $result;
        }

        $source_post = get_post($source_post_id);
        if (!$source_post) {
            $result['errors'] = 1;
            self::log('error', 'Re-translation failed because the source post could not be loaded.', $source_post_id);
            return $result;
        }

        $source_lang = self::get_source_language_slug();
        $current_lang = pll_get_post_language($source_post_id);

        if ($current_lang && $current_lang !== $source_lang) {
            $result['skipped'] = 1;
            self::log('info', 'Re-translation skipped because the post is not in the source language.', $source_post_id, [
                'post_lang' => $current_lang,
                'source_lang' => $source_lang,
            ]);
            return $result;
        }

        $languages = pll_languages_list(['fields' => 'slug']);
        if (!$languages || count($languages) < 2) {
            $result['skipped'] = 1;
            self::log('warning', 'Re-translation skipped because Polylang has fewer than two languages.', $source_post_id);
            return $result;
        }

        $map = self::get_existing_translation_map($source_post_id, $source_lang, $languages);
        $map[$source_lang] = $source_post_id;

        $payload_source = [
            'title' => $source_post->post_title,
            'excerpt' => $source_post->post_excerpt,
            'content' => $source_post->post_content,
        ];

        foreach ($languages as $target_lang) {
            if ($target_lang === $source_lang) {
                continue;
            }

            $target_id = isset($map[$target_lang]) ? (int) $map[$target_lang] : 0;
            if (!$target_id) {
                // No existing translation in this language; creation is handled by
                // translate_post_if_needed()/the repair tools, not here.
                continue;
            }

            $payload = self::openai_translate_post_payload($payload_source, $target_lang);

            if (!$payload || empty($payload['title']) || empty($payload['content'])) {
                $result['translation_failed']++;
                $result['errors']++;
                update_post_meta($target_id, self::META_TRANSLATION_FAILED, current_time('mysql'));
                update_post_meta($target_id, self::META_TRANSLATION_FAILED_REASON, 'Re-translation returned empty or invalid content.');
                self::log('error', 'Re-translation failed because OpenAI returned empty or invalid content.', $source_post_id, [
                    'target_lang' => $target_lang,
                    'translated_post_id' => $target_id,
                ]);
                continue;
            }

            // Save a revision of the current foreign post so the update can be rolled back.
            if (function_exists('wp_save_post_revision')) {
                wp_save_post_revision($target_id);
            }

            $dates = self::capture_post_dates($target_id);

            $updated = wp_update_post([
                'ID' => $target_id,
                'post_title' => wp_strip_all_tags($payload['title']),
                'post_content' => $payload['content'],
                'post_excerpt' => $payload['excerpt'] ?? '',
            ], true);

            if ($dates) {
                self::restore_post_dates($target_id, $dates);
            }

            if (is_wp_error($updated) || !$updated) {
                $result['translation_failed']++;
                $result['errors']++;
                self::log('error', 'Re-translation failed because wp_update_post failed.', $source_post_id, [
                    'target_lang' => $target_lang,
                    'translated_post_id' => $target_id,
                    'wp_error' => is_wp_error($updated) ? $updated->get_error_message() : 'Unknown update failure',
                ]);
                continue;
            }

            delete_post_meta($target_id, self::META_TRANSLATION_FAILED);
            delete_post_meta($target_id, self::META_TRANSLATION_FAILED_REASON);
            update_post_meta($target_id, self::META_AI_TRANSLATION, 1);
            update_post_meta($target_id, self::META_SOURCE_POST, $source_post_id);

            $result['translations_updated']++;
            self::log('info', 'Existing translation re-translated from updated source content.', $source_post_id, [
                'target_lang' => $target_lang,
                'translated_post_id' => $target_id,
            ]);
        }

        // Keep the Polylang translation group intact.
        pll_save_post_translations($map);

        return $result;
    }

    private static function polylang_available(): bool {
        return function_exists('pll_languages_list')
            && function_exists('pll_get_post_language')
            && function_exists('pll_set_post_language')
            && function_exists('pll_get_post')
            && function_exists('pll_save_post_translations');
    }

    private static function get_source_language_slug(): string {
        return sanitize_key(get_option(self::OPTION_SOURCE_LANG, 'en') ?: 'en');
    }

    private static function get_existing_translation_map(int $source_post_id, string $source_lang, array $languages): array {
        $map = [$source_lang => $source_post_id];

        foreach ($languages as $lang) {
            $existing = pll_get_post($source_post_id, $lang);
            if ($existing) {
                $map[$lang] = (int) $existing;
            }
        }

        return $map;
    }

    /**
     * Find posts that claim this source via the authoritative META_SOURCE_POST meta,
     * even when Polylang's own translation link is missing/broken. Returns a
     * [language_slug => post_id] map of those orphaned translations. When several
     * posts claim the same source for the same language, the lowest post ID wins
     * (the earliest-created, most likely the genuine translation).
     */
    private static function get_orphan_translations_for_source(int $source_post_id, string $source_lang): array {
        if (!$source_post_id) {
            return [];
        }

        $claimers = get_posts([
            'post_type'        => 'post',
            'post_status'      => 'any',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
            'meta_query'       => [
                [
                    'key'   => self::META_SOURCE_POST,
                    'value' => $source_post_id,
                ],
            ],
        ]);

        $orphans = [];
        foreach ($claimers as $cid) {
            $cid = (int) $cid;
            if ($cid === $source_post_id) {
                continue;
            }
            $lang = self::polylang_available() ? pll_get_post_language($cid) : '';
            if (!$lang || $lang === $source_lang) {
                continue; // No language, or mislabeled as the source language.
            }
            if (empty($orphans[$lang])) {
                $orphans[$lang] = $cid;
            }
        }

        return $orphans;
    }

    /**
     * Resolve the source-language post for an unlinked translation, preferring the
     * authoritative META_SOURCE_POST meta (which this plugin writes on every
     * translation it creates and which survives even when Polylang's links break)
     * and only falling back to fuzzy title/slug matching when no valid meta exists.
     *
     * Returns ['post_id'=>int, 'score'=>float, 'reason'=>string, 'ambiguous'=>bool, 'via'=>string]
     * or an empty array when no candidate is found.
     */
    private static function resolve_unlinked_translation_source(int $post_id, string $source_lang): array {
        // 1) Authoritative link written at translation time.
        $claimed = (int) get_post_meta($post_id, self::META_SOURCE_POST, true);
        if ($claimed > 0 && $claimed !== $post_id) {
            $claimed_post = get_post($claimed);
            $claimed_valid = $claimed_post
                && $claimed_post->post_type === 'post'
                && (!self::polylang_available() || pll_get_post_language($claimed) === $source_lang);
            if ($claimed_valid) {
                return [
                    'post_id'   => $claimed,
                    'score'     => 100.0,
                    'reason'    => 'authoritative source meta (_cq_afi_source_post_id)',
                    'ambiguous' => false,
                    'via'       => 'meta',
                ];
            }
        }

        // 2) Heuristic title/slug fallback.
        $lang = self::polylang_available() ? pll_get_post_language($post_id) : '';
        if (!$lang || $lang === $source_lang) {
            return [];
        }

        $candidates = self::find_source_post_candidates_for_unlinked_translation($post_id, $lang, $source_lang);
        if (!$candidates) {
            return [];
        }

        $best      = $candidates[0];
        $ambiguous = isset($candidates[1]) && ((float) $best['score'] - (float) $candidates[1]['score']) < 12;

        return [
            'post_id'   => (int) $best['post_id'],
            'score'     => (float) $best['score'],
            'reason'    => (string) ($best['reason'] ?? 'title/slug similarity'),
            'ambiguous' => $ambiguous,
            'via'       => 'heuristic',
        ];
    }

    public static function create_translation(int $source_post_id, string $source_lang, string $target_lang, array $translation_map): int {
        $source_post = get_post($source_post_id);

        if (!$source_post) {
            self::log('error', 'Translation failed because source post could not be loaded.', $source_post_id, [
                'target_lang' => $target_lang,
            ]);
            return 0;
        }

        $payload = self::openai_translate_post_payload([
            'title' => $source_post->post_title,
            'excerpt' => $source_post->post_excerpt,
            'content' => $source_post->post_content,
        ], $target_lang);

        if (!$payload || empty($payload['title']) || empty($payload['content'])) {
            self::log('error', 'Translation failed because OpenAI returned empty or invalid translated content.', $source_post_id, [
                'target_lang' => $target_lang,
            ]);
            return 0;
        }

        $new_post_id = wp_insert_post([
            'post_title' => wp_strip_all_tags($payload['title']),
            'post_content' => $payload['content'],
            'post_excerpt' => $payload['excerpt'] ?? '',
            'post_status' => 'draft',
            'post_type' => $source_post->post_type,
            'post_author' => $source_post->post_author,
            'post_date' => $source_post->post_date,
            'post_date_gmt' => $source_post->post_date_gmt,
            'post_modified' => $source_post->post_modified,
            'post_modified_gmt' => $source_post->post_modified_gmt,
            'comment_status' => $source_post->comment_status,
            'ping_status' => $source_post->ping_status,
            'menu_order' => $source_post->menu_order,
        ], true);

        if (is_wp_error($new_post_id) || !$new_post_id) {
            self::log('error', 'Translation failed because wp_insert_post failed.', $source_post_id, [
                'target_lang' => $target_lang,
                'wp_error' => is_wp_error($new_post_id) ? $new_post_id->get_error_message() : 'Unknown insert failure',
            ]);
            return 0;
        }

        $new_post_id = (int) $new_post_id;

        update_post_meta($new_post_id, self::META_AI_TRANSLATION, 1);
        update_post_meta($new_post_id, self::META_SOURCE_POST, $source_post_id);

        pll_set_post_language($new_post_id, $target_lang);

        self::copy_taxonomies($source_post_id, $new_post_id);
        self::copy_safe_post_meta($source_post_id, $new_post_id);

        $thumbnail_id = get_post_thumbnail_id($source_post_id);
        if ($thumbnail_id) {
            set_post_thumbnail($new_post_id, $thumbnail_id);
        }

        $translation_map[$source_lang] = $source_post_id;
        $translation_map[$target_lang] = $new_post_id;

        $source_dates_before_link = self::capture_post_dates($source_post_id);
        pll_save_post_translations($translation_map);
        if ($source_dates_before_link) {
            self::restore_post_dates($source_post_id, $source_dates_before_link);
        }

        wp_update_post([
            'ID' => $new_post_id,
            'post_status' => 'publish',
            'post_date' => $source_post->post_date,
            'post_date_gmt' => $source_post->post_date_gmt,
            'post_modified' => $source_post->post_modified,
            'post_modified_gmt' => $source_post->post_modified_gmt,
        ]);

        self::restore_post_dates($new_post_id, [
            'post_date' => $source_post->post_date,
            'post_date_gmt' => $source_post->post_date_gmt,
            'post_modified' => $source_post->post_modified,
            'post_modified_gmt' => $source_post->post_modified_gmt,
        ]);

        pll_save_post_translations($translation_map);

        self::restore_post_dates($new_post_id, [
            'post_date' => $source_post->post_date,
            'post_date_gmt' => $source_post->post_date_gmt,
            'post_modified' => $source_post->post_modified,
            'post_modified_gmt' => $source_post->post_modified_gmt,
        ]);

        return $new_post_id;
    }

    private static function copy_taxonomies(int $source_post_id, int $target_post_id): void {
        $taxonomies = get_object_taxonomies(get_post_type($source_post_id));

        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy === 'language' || $taxonomy === 'post_translations') {
                continue;
            }

            $terms = wp_get_object_terms($source_post_id, $taxonomy, ['fields' => 'ids']);
            if (!is_wp_error($terms)) {
                wp_set_object_terms($target_post_id, $terms, $taxonomy);
            }
        }
    }

    private static function copy_safe_post_meta(int $source_post_id, int $target_post_id): void {
        $skip_prefixes = ['_edit_', '_wp_', '_thumbnail_id', '_cq_afi_'];
        $skip_exact = ['_wp_old_slug'];
        $all_meta = get_post_meta($source_post_id);

        foreach ($all_meta as $key => $values) {
            if (in_array($key, $skip_exact, true)) {
                continue;
            }

            foreach ($skip_prefixes as $prefix) {
                if (strpos($key, $prefix) === 0) {
                    continue 2;
                }
            }

            foreach ($values as $value) {
                add_post_meta($target_post_id, $key, maybe_unserialize($value));
            }
        }
    }

    private static function get_openai_api_key(): string {
        return (string) get_option(self::OPTION_API_KEY, '');
    }

    private static function get_openai_model(): string {
        $use_custom = (int) get_option(self::OPTION_USE_CUSTOM_MODEL, 0);
        $custom = trim((string) get_option(self::OPTION_CUSTOM_MODEL, ''));

        if ($use_custom && $custom) {
            $model = $custom;
        } else {
            $model = (string) get_option(self::OPTION_MODEL, self::DEFAULT_MODEL);
        }

        if (!$model) {
            $model = self::DEFAULT_MODEL;
        }

        return (string) apply_filters('cq_afi_openai_model', $model);
    }

    public static function openai_translate_post_payload(array $post_payload, string $target_lang): array {
        $api_key = self::get_openai_api_key();

        if (!$api_key) {
            self::log('error', 'OpenAI translation failed because no API key is saved.');
            return [];
        }

        $input_json = wp_json_encode($post_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $request_body = [
            'model' => self::get_openai_model(),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => 'You translate WordPress posts. Preserve HTML, Gutenberg block comments, shortcodes, links, attributes, embedded markup, and JSON-like structures. Translate human-readable text only. Return valid JSON only with keys title, excerpt, and content.',
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => "Target language slug: {$target_lang}\n\nTranslate this JSON object and return only JSON:\n{$input_json}",
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_object',
                ],
            ],
            'max_output_tokens' => 12000,
        ];

        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($request_body),
        ]);

        if (is_wp_error($response)) {
            self::log('error', 'OpenAI translation request failed: ' . $response->get_error_message());
            return [];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($body)) {
            $message = 'OpenAI translation request returned HTTP ' . $code . '.';
            if (is_array($body) && !empty($body['error']['message'])) {
                $message .= ' OpenAI says: ' . sanitize_text_field($body['error']['message']);
            }
            self::log('error', $message, 0, ['model' => self::get_openai_model()]);
            return [];
        }

        $text = self::extract_responses_text($body);

        if (!$text) {
            self::log('error', 'OpenAI translation response did not contain output text.', 0, ['model' => self::get_openai_model()]);
            return [];
        }

        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            $decoded = self::try_decode_json_from_text($text);
        }

        if (!is_array($decoded)) {
            self::log('error', 'OpenAI translation response was not valid JSON.', 0, ['model' => self::get_openai_model()]);
            return [];
        }

        return [
            'title' => isset($decoded['title']) ? (string) $decoded['title'] : '',
            'excerpt' => isset($decoded['excerpt']) ? (string) $decoded['excerpt'] : '',
            'content' => isset($decoded['content']) ? (string) $decoded['content'] : '',
        ];
    }

    private static function extract_responses_text(array $body): string {
        if (isset($body['output_text']) && is_string($body['output_text'])) {
            return trim($body['output_text']);
        }

        $chunks = [];

        if (!empty($body['output']) && is_array($body['output'])) {
            foreach ($body['output'] as $output_item) {
                if (empty($output_item['content']) || !is_array($output_item['content'])) {
                    continue;
                }

                foreach ($output_item['content'] as $content_item) {
                    if (isset($content_item['text']) && is_string($content_item['text'])) {
                        $chunks[] = $content_item['text'];
                    }
                }
            }
        }

        return trim(implode('', $chunks));
    }

    private static function try_decode_json_from_text(string $text): array {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}

CQ_Auto_Featured_Image_AI_Translate::init();

register_activation_hook(__FILE__, ['CQ_Auto_Featured_Image_AI_Translate', 'activate']);
register_deactivation_hook(__FILE__, ['CQ_Auto_Featured_Image_AI_Translate', 'deactivate']);
