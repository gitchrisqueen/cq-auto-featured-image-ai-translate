# Changelog

## 1.11.0
- Added re-translation of EXISTING translations when a source-language post changes. Previously the plugin only ever filled in *missing* translations and never refreshed existing ones.
- New public integration hook: other plugins can fire `do_action('cq_afi_retranslate_post', $source_post_id)` to refresh that post's translations. Used by the CQC Yoast Optimizer when it updates an English post live.
- New public method `retranslate_existing_translations($source_post_id)`: updates each existing foreign translation in place (same ID/URL), saving a revision first for rollback and preserving original publish dates.
- Work is deferred to a single WP-Cron event with an immediate non-blocking cron spawn (same reliability pattern as manual runs), so the calling request never blocks; falls back to inline if scheduling fails.

## 1.10.3
- Audit worker now logs when it enters.
- Audit worker catches and logs Throwable errors.
- Audit batch size reduced to avoid host timeout.
- Audit progress checkpoints every 2 posts.
- Added Emergency Non-AJAX Audit Batch fallback form.
