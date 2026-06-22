# Changelog

## 1.11.2
- Source-post candidate selection (`get_source_post_ids`) now requires an exact source-language match; posts with no language assigned are no longer included. Image-repair candidate selection is unchanged and still spans all languages by design.

## 1.11.1
- Fixed Polylang relink choosing the wrong source post. The repair now trusts the authoritative source ID stored in `_cq_afi_source_post_id` (set when the plugin creates a translation) before falling back to cross-language title/slug similarity, which could mislink a foreign post to an unrelated same-author/same-category English post.
- If the stored source exists but cannot be linked (e.g. the source already has a different post for that language), the relink now stops instead of guessing.

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
