<?php
/**
 * Repair scrambled Polylang language tags and remove duplicate translations.
 *
 * Companion to tools/diagnose-languages.php. The diagnostic showed two
 * compounding failures on this site:
 *   - DUPLICATES: the same source post was translated into the same language
 *     many times over, inflating every non-English language's count.
 *   - MIS-TAGGED posts: a post's Polylang `language` term frequently does NOT
 *     match the actual language of its content (e.g. Russian/Arabic/Japanese
 *     content tagged "hi"), and content exists in languages beyond the intended
 *     set (Hebrew, Korean, Thai, Khmer, Bengali, Greek, Tamil, Ukrainian,
 *     Vietnamese).
 *
 * The `_cq_afi_source_post_id` meta is intact (0 orphans), so it is the trusted
 * backbone for regrouping.
 *
 * POLICY (chosen by the site owner):
 *   1. KEEP only the original 11 languages: en, pt, zh, ja, es, de, fr, ar, hi,
 *      ru, tr. Any post whose TRUE content language is outside this set is
 *      trashed.
 *   2. DEDUPE: per (source post, true language) keep the single best post and
 *      trash the redundant copies. "Best" = correctly-tagged, then published over
 *      draft, then most-complete content, then lowest ID.
 *   3. An untranslated copy whose true language is English (it was tagged as a
 *      target language but never actually translated) is re-tagged to English;
 *      because its source is already English, it then dedupes away against the
 *      source, leaving a clean gap the plugin can translate later.
 *
 * CRITICAL ORDER: re-tag to TRUE language FIRST, then dedupe. Deduping on the
 * corrupt tags would delete legitimately-different content.
 *
 * Everything is REVERSIBLE: re-tags save the original language slug, trashes use
 * the bin (wp_trash_post) and are marked so `undo` can restore them. Take a DB
 * backup anyway before `apply`.
 *
 * MULTISITE: operates on ONE site; pass the numeric site (blog) ID or omit it
 * for the interactive picker.
 *
 * MODES (run in this order):
 *
 *   # 1. DETECT true language of every post and cache it to meta. Uses Unicode
 *   #    script for non-Latin (free, certain) and OpenAI only for Latin-script
 *   #    posts that are ambiguous. Resumable; pass limit=N to do it in batches.
 *   wp eval-file .../tools/fix-languages.php detect 17 ai
 *   wp eval-file .../tools/fix-languages.php detect 17 ai limit=500   # repeat until done
 *
 *   # 2. PLAN (default, dry-run): show every re-tag and every trash, write a CSV.
 *   wp eval-file .../tools/fix-languages.php plan 17
 *
 *   # 3. APPLY: perform the re-tags and trashes (reversible).
 *   wp eval-file .../tools/fix-languages.php apply 17
 *
 *   # 4. RELINK: rebuild Polylang translation groups from the source meta.
 *   wp eval-file .../tools/fix-languages.php relink 17
 *
 *   # UNDO: restore original language tags and untrash everything this tool did.
 *   wp eval-file .../tools/fix-languages.php undo 17
 *
 * After this, run tools/reorder-post-dates.php to fix the publish-date ordering.
 *
 * @package CQ_Auto_Featured_Image_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "Run with WP-CLI: wp eval-file <this-file> [detect|plan|apply|relink|undo] [site_id] [ai] [limit=N]\n";
	return;
}

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

// ---- Parse args order-independently ----
$argv_in   = isset( $args ) && is_array( $args ) ? $args : array();
$mode      = 'plan';
$site_id   = 0;
$post_type = 'post';
$use_ai    = false;
$limit     = 0; // 0 = no limit.
$keep_arg  = '';
foreach ( $argv_in as $raw ) {
	$arg = strtolower( trim( (string) $raw ) );
	if ( '' === $arg ) {
		continue;
	}
	if ( in_array( $arg, array( 'detect', 'plan', 'apply', 'relink', 'undo' ), true ) ) {
		$mode = $arg;
	} elseif ( 'ai' === $arg ) {
		$use_ai = true;
	} elseif ( ctype_digit( $arg ) ) {
		$site_id = (int) $arg;
	} elseif ( 0 === strpos( $arg, 'limit=' ) ) {
		$limit = max( 0, (int) substr( $arg, 6 ) );
	} elseif ( 0 === strpos( $arg, 'keep=' ) ) {
		$keep_arg = substr( $arg, 5 );
	} else {
		$post_type = sanitize_key( $arg );
	}
}

// Meta keys used by this tool.
$meta_true      = '_cq_afi_true_lang';
$meta_true_via  = '_cq_afi_true_lang_via';
$meta_source    = '_cq_afi_source_post_id';
$meta_orig_lang = '_cq_afi_fixlang_orig_lang';
$meta_trashed   = '_cq_afi_fixlang_trashed';

// Languages to KEEP (base ISO codes). Everything else is an "extra" -> trashed.
$keep_bases = array( 'en', 'pt', 'zh', 'ja', 'es', 'de', 'fr', 'ar', 'hi', 'ru', 'tr' );
if ( '' !== $keep_arg ) {
	$keep_bases = array_filter( array_map( 'sanitize_key', explode( ',', $keep_arg ) ) );
}
$keep_set = array_fill_keys( $keep_bases, true );

$switched = false;

// ---- Multisite: pick + switch ----
if ( is_multisite() ) {
	if ( $site_id <= 0 ) {
		$sites = get_sites( array( 'number' => 0, 'orderby' => 'id', 'order' => 'ASC' ) );
		WP_CLI::log( 'Multisite network. Available sites:' );
		WP_CLI::log( str_repeat( '-', 70 ) );
		foreach ( $sites as $site ) {
			$bid     = (int) $site->blog_id;
			$details = get_blog_details( array( 'blog_id' => $bid ) );
			WP_CLI::log( sprintf( '  [%d]  %s  —  %s', $bid, $details ? $details->blogname : '?', $details ? $details->siteurl : get_site_url( $bid ) ) );
		}
		WP_CLI::log( str_repeat( '-', 70 ) );
		fwrite( STDOUT, 'Enter the site ID to operate on: ' );
		$entered = is_resource( STDIN ) ? fgets( STDIN ) : false;
		$site_id = ( false !== $entered ) ? (int) trim( $entered ) : 0;
	}
	if ( $site_id <= 0 || ! get_blog_details( array( 'blog_id' => $site_id ) ) ) {
		WP_CLI::error( sprintf( 'Invalid site ID (%d).', $site_id ) );
	}
	switch_to_blog( $site_id );
	$switched = true;
	WP_CLI::log( sprintf( 'Operating on site #%d (%s)', $site_id, get_site_url( $site_id ) ) );
}

WP_CLI::log( str_repeat( '=', 70 ) );
WP_CLI::log( sprintf( 'FIX LANGUAGES  |  mode=%s  post_type=%s  AI=%s  keep=%s', strtoupper( $mode ), $post_type, $use_ai ? 'on' : 'off', implode( ',', array_keys( $keep_set ) ) ) );
WP_CLI::log( str_repeat( '=', 70 ) );

// ---- Polylang language slug resolution: base code => registered slug ----
$registered = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list() : array();
$base_to_slug = array();
foreach ( $registered as $slug ) {
	$b = strtolower( explode( '_', str_replace( '-', '_', (string) $slug ) )[0] );
	if ( ! isset( $base_to_slug[ $b ] ) ) {
		$base_to_slug[ $b ] = (string) $slug;
	}
}

// ---- Current language tag of a post ----
$current_lang = function ( $pid ) {
	if ( function_exists( 'pll_get_post_language' ) ) {
		$l = pll_get_post_language( (int) $pid, 'slug' );
		if ( $l ) {
			return (string) $l;
		}
	}
	$t = get_the_terms( (int) $pid, 'language' );
	return ( $t && ! is_wp_error( $t ) ) ? (string) $t[0]->slug : '';
};

$base_of = function ( $slug ) {
	return strtolower( explode( '_', str_replace( '-', '_', (string) $slug ) )[0] );
};

$normalize_title = function ( $t ) {
	$t = wp_strip_all_tags( (string) $t );
	$t = html_entity_decode( $t, ENT_QUOTES, 'UTF-8' );
	$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t, 'UTF-8' ) : strtolower( $t );
	$t = preg_replace( '/\s*\((?:copy|copia|kopie|copie|duplicate)\)\s*$/u', '', $t );
	$t = preg_replace( '/[-_\s]+\d+$/u', '', $t );
	$t = preg_replace( '/\s+/u', ' ', $t );
	return trim( $t );
};

// ---- True-language detection by Unicode script (free, certain for non-Latin) ----
$detect_script_lang = function ( $text ) {
	$c = array(
		'han'  => preg_match_all( '/\p{Han}/u', $text ),
		'kana' => preg_match_all( '/[\p{Hiragana}\p{Katakana}]/u', $text ),
		'ko'   => preg_match_all( '/\p{Hangul}/u', $text ),
		'ar'   => preg_match_all( '/\p{Arabic}/u', $text ),
		'he'   => preg_match_all( '/\p{Hebrew}/u', $text ),
		'hi'   => preg_match_all( '/\p{Devanagari}/u', $text ),
		'bn'   => preg_match_all( '/\p{Bengali}/u', $text ),
		'th'   => preg_match_all( '/\p{Thai}/u', $text ),
		'km'   => preg_match_all( '/\p{Khmer}/u', $text ),
		'el'   => preg_match_all( '/\p{Greek}/u', $text ),
		'ta'   => preg_match_all( '/\p{Tamil}/u', $text ),
		'cyr'  => preg_match_all( '/\p{Cyrillic}/u', $text ),
		'lat'  => preg_match_all( '/\p{Latin}/u', $text ),
	);
	$total = array_sum( $c );
	if ( $total < 8 ) {
		return ''; // Too little text; defer.
	}
	// Japanese if kana present.
	if ( $c['kana'] > 0 && ( $c['kana'] + $c['han'] ) / $total > 0.15 ) {
		return 'ja';
	}
	// Pick the dominant non-Latin script.
	$nonlat = array( 'han', 'ko', 'ar', 'he', 'hi', 'bn', 'th', 'km', 'el', 'ta', 'cyr' );
	$best   = '';
	$bn     = 0;
	foreach ( $nonlat as $s ) {
		if ( $c[ $s ] > $bn ) {
			$best = $s;
			$bn   = $c[ $s ];
		}
	}
	if ( '' !== $best && $bn >= $c['lat'] && $bn / $total > 0.2 ) {
		if ( 'han' === $best ) {
			return 'zh';
		}
		if ( 'cyr' === $best ) {
			// Ukrainian-specific letters distinguish uk from ru.
			$uk = preg_match_all( '/[іїєґІЇЄҐ]/u', $text );
			$ru = preg_match_all( '/[ыэъёЫЭЪЁ]/u', $text );
			return ( $uk > 0 && $uk >= $ru ) ? 'uk' : 'ru';
		}
		return $best; // ko, ar, he, hi, bn, th, km, el, ta.
	}
	return ''; // Latin-dominant -> needs heuristic/AI.
};

// ---- Latin-script guesser (en/es/de/fr/pt/tr/vi) ----
$latin_stop = array(
	'en' => array( 'the', 'and', 'of', 'to', 'in', 'is', 'that', 'for', 'with', 'are', 'this', 'was', 'as', 'be', 'on', 'it' ),
	'es' => array( 'que', 'de', 'la', 'el', 'los', 'las', 'una', 'por', 'con', 'para', 'como', 'pero', 'más', 'está', 'su' ),
	'de' => array( 'der', 'die', 'das', 'und', 'ist', 'nicht', 'mit', 'ein', 'auch', 'sich', 'dem', 'den', 'von', 'wird', 'zu' ),
	'fr' => array( 'le', 'la', 'les', 'des', 'une', 'dans', 'pour', 'que', 'qui', 'avec', 'sur', 'est', 'pas', 'plus', 'aux' ),
	'pt' => array( 'que', 'de', 'da', 'do', 'uma', 'com', 'para', 'como', 'mas', 'mais', 'está', 'não', 'os', 'as', 'um' ),
	'tr' => array( 've', 'bir', 'bu', 'için', 'ile', 'çok', 'daha', 'olan', 'gibi', 'ama', 'olarak', 'sonra', 'kadar' ),
);
$guess_latin = function ( $text ) use ( $latin_stop ) {
	// Vietnamese: distinctive đ and Latin Extended Additional tone marks.
	if ( preg_match( '/[đĐ]/u', $text ) || preg_match_all( '/[\x{1EA0}-\x{1EFF}]/u', $text ) >= 3 ) {
		return array( 'lang' => 'vi', 'score' => 9, 'margin' => 9 );
	}
	$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	$words = preg_split( '/[^\p{L}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY );
	if ( count( $words ) < 12 ) {
		return array( 'lang' => '', 'score' => 0, 'margin' => 0 );
	}
	$set    = array_flip( $words );
	$scores = array();
	foreach ( $latin_stop as $lng => $stops ) {
		$h = 0;
		foreach ( $stops as $w ) {
			if ( isset( $set[ $w ] ) ) {
				++$h;
			}
		}
		$scores[ $lng ] = $h;
	}
	if ( preg_match( '/[ñ¿¡]/u', $text ) ) {
		$scores['es'] += 3;
	}
	if ( preg_match( '/ß/u', $text ) ) {
		$scores['de'] += 4;
	}
	if ( preg_match( '/[ãõ]/u', $text ) ) {
		$scores['pt'] += 3;
	}
	if ( preg_match( '/[ğış]/u', $text ) ) {
		$scores['tr'] += 4;
	}
	if ( preg_match( '/[àâêèœùçëï]/u', $text ) ) {
		$scores['fr'] += 2;
	}
	arsort( $scores );
	$best = key( $scores );
	$bs   = current( $scores );
	next( $scores );
	$sec = (int) current( $scores );
	return array( 'lang' => $best, 'score' => (int) $bs, 'margin' => (int) $bs - $sec );
};

// ---- OpenAI language id (reuses the plugin's saved key/model) ----
$ai_model = '';
$ai_key   = (string) get_option( 'cq_afi_openai_api_key', '' );
if ( $use_ai ) {
	$uc       = (int) get_option( 'cq_afi_use_custom_model', 0 );
	$cm       = trim( (string) get_option( 'cq_afi_openai_custom_model', '' ) );
	$ai_model = ( $uc && $cm ) ? $cm : (string) get_option( 'cq_afi_openai_model', 'gpt-5.4-nano' );
	if ( '' === $ai_model ) {
		$ai_model = 'gpt-5.4-nano';
	}
	if ( '' === $ai_key ) {
		WP_CLI::warning( 'AI requested but no OpenAI key saved; falling back to script+heuristic only.' );
		$use_ai = false;
	}
}
$ai_detect = function ( $text ) use ( $ai_key, $ai_model ) {
	$text = trim( mb_substr( $text, 0, 1500, 'UTF-8' ) );
	if ( '' === $text || '' === $ai_key ) {
		return '';
	}
	$body = array(
		'model' => $ai_model,
		'input' => array(
			array(
				'role'    => 'system',
				'content' => array( array( 'type' => 'input_text', 'text' => 'Identify the dominant natural language of the text. Respond with ONLY {"lang":"<ISO 639-1 code>"} e.g. en, es, fr, de, pt, tr, vi, zh, ja, ko, ar, he, hi, bn, th, km, el, ta, ru, uk. No prose.' ) ),
			),
			array(
				'role'    => 'user',
				'content' => array( array( 'type' => 'input_text', 'text' => $text ) ),
			),
		),
		'text'  => array( 'format' => array( 'type' => 'json_object' ) ),
		'max_output_tokens' => 2000,
	);
	$resp = wp_remote_post(
		'https://api.openai.com/v1/responses',
		array(
			'timeout' => 60,
			'headers' => array( 'Authorization' => 'Bearer ' . $ai_key, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $resp ) ) {
		return '';
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
	if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
		return '';
	}
	$out = '';
	if ( isset( $json['output_text'] ) && is_string( $json['output_text'] ) ) {
		$out = $json['output_text'];
	} elseif ( ! empty( $json['output'] ) && is_array( $json['output'] ) ) {
		foreach ( $json['output'] as $item ) {
			if ( ! empty( $item['content'] ) && is_array( $item['content'] ) ) {
				foreach ( $item['content'] as $cc ) {
					if ( isset( $cc['text'] ) && is_string( $cc['text'] ) ) {
						$out .= $cc['text'];
					}
				}
			}
		}
	}
	$d = json_decode( trim( $out ), true );
	if ( is_array( $d ) && ! empty( $d['lang'] ) ) {
		return strtolower( substr( preg_replace( '/[^a-zA-Z]/', '', (string) $d['lang'] ), 0, 5 ) );
	}
	return '';
};

// ---- Gather all real post IDs of this type ----
$all_ids = get_posts(
	array(
		'post_type'        => $post_type,
		'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
		'posts_per_page'   => -1,
		'orderby'          => 'ID',
		'order'            => 'ASC',
		'fields'           => 'ids',
		'suppress_filters' => true,
	)
);
$all_ids = array_map( 'intval', $all_ids );
WP_CLI::log( sprintf( 'Posts of type "%s": %d', $post_type, count( $all_ids ) ) );

// =====================================================================
// UNDO
// =====================================================================
if ( 'undo' === $mode ) {
	// Untrash anything this tool trashed.
	$trashed = get_posts(
		array(
			'post_type'        => $post_type,
			'post_status'      => 'trash',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'meta_key'         => $meta_trashed,
			'meta_value'       => '1',
			'suppress_filters' => true,
		)
	);
	$untrashed = 0;
	foreach ( $trashed as $pid ) {
		wp_untrash_post( (int) $pid );
		delete_post_meta( (int) $pid, $meta_trashed );
		++$untrashed;
	}
	// Restore original language tags.
	$retag = 0;
	foreach ( $all_ids as $pid ) {
		$orig = (string) get_post_meta( $pid, $meta_orig_lang, true );
		if ( '' !== $orig ) {
			if ( function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( $pid, $orig );
			}
			delete_post_meta( $pid, $meta_orig_lang );
			++$retag;
		}
	}
	WP_CLI::success( sprintf( 'UNDO complete: untrashed %d post(s), restored %d language tag(s).', $untrashed, $retag ) );
	if ( $switched ) {
		restore_current_blog();
	}
	return;
}

// =====================================================================
// DETECT — resolve and cache true language per post
// =====================================================================
if ( 'detect' === $mode ) {
	$done    = 0;
	$ai_used = 0;
	$skipped = 0;
	$dist    = array();
	foreach ( $all_ids as $pid ) {
		if ( $limit > 0 && $done >= $limit ) {
			break;
		}
		// Skip if already cached (resumable).
		$cached = (string) get_post_meta( $pid, $meta_true, true );
		if ( '' !== $cached ) {
			++$skipped;
			$dist[ $cached ] = isset( $dist[ $cached ] ) ? $dist[ $cached ] + 1 : 1;
			continue;
		}
		$post = get_post( $pid );
		if ( ! $post ) {
			continue;
		}
		$sample = wp_strip_all_tags( $post->post_title . ' ' . $post->post_content );
		$sample = trim( preg_replace( '/\s+/u', ' ', $sample ) );

		$true = $detect_script_lang( $sample );
		$via  = 'script';
		if ( '' === $true ) {
			// Latin or undetermined: heuristic first.
			$g = $guess_latin( $sample );
			if ( '' !== $g['lang'] && $g['score'] >= 3 && $g['margin'] >= 2 ) {
				$true = $g['lang'];
				$via  = 'heuristic';
			}
			// Use AI when enabled and (no/weak heuristic).
			if ( $use_ai && ( '' === $true || $g['margin'] < 3 ) ) {
				$ai = $ai_detect( $sample );
				if ( '' !== $ai ) {
					$true = $ai;
					$via  = 'ai';
					++$ai_used;
				}
			}
		}
		if ( '' === $true ) {
			$true = 'und'; // Undetermined; plan will leave these alone.
			$via  = 'none';
		}
		update_post_meta( $pid, $meta_true, $true );
		update_post_meta( $pid, $meta_true_via, $via );
		$dist[ $true ] = isset( $dist[ $true ] ) ? $dist[ $true ] + 1 : 1;
		++$done;
		if ( 0 === $done % 250 ) {
			WP_CLI::log( sprintf( '  ...detected %d (AI calls: %d)', $done, $ai_used ) );
		}
	}
	arsort( $dist );
	WP_CLI::log( str_repeat( '-', 70 ) );
	WP_CLI::log( sprintf( 'Detected this run: %d  | already cached: %d | AI calls: %d', $done, $skipped, $ai_used ) );
	WP_CLI::log( 'True-language distribution (cached so far):' );
	foreach ( $dist as $lng => $n ) {
		$mark = isset( $keep_set[ $base_of( $lng ) ] ) ? '' : '  <- EXTRA (will be trashed)';
		WP_CLI::log( sprintf( '   %-6s %d%s', $lng, $n, $mark ) );
	}
	$remaining = count( $all_ids ) - ( $done + $skipped );
	if ( $remaining > 0 && $limit > 0 ) {
		WP_CLI::warning( sprintf( 'Approx %d post(s) not yet detected. Re-run "detect" to continue.', $remaining ) );
	} else {
		WP_CLI::success( 'Detection complete. Next: run "plan" to preview the fix.' );
	}
	if ( $switched ) {
		restore_current_blog();
	}
	return;
}

// =====================================================================
// Shared: build the action plan (used by plan + apply)
// =====================================================================
// Load cached detection + source + current tag for every post.
$true_of   = array();
$src_of    = array();
$tag_of    = array();
$title_of  = array();
$status_of = array();
$len_of    = array();
$missing_detect = 0;
foreach ( $all_ids as $pid ) {
	$t = (string) get_post_meta( $pid, $meta_true, true );
	if ( '' === $t ) {
		++$missing_detect;
		$t = 'und';
	}
	$true_of[ $pid ]   = $t;
	$src_of[ $pid ]    = (int) get_post_meta( $pid, $meta_source, true );
	$tag_of[ $pid ]    = $current_lang( $pid );
	$p                 = get_post( $pid );
	$title_of[ $pid ]  = $p ? $p->post_title : '';
	$status_of[ $pid ] = $p ? $p->post_status : '';
	$len_of[ $pid ]    = $p ? strlen( (string) $p->post_content ) : 0;
}
if ( $missing_detect > 0 ) {
	WP_CLI::warning( sprintf( '%d post(s) have no cached true-language. Run "detect" first for an accurate plan; they will be left untouched.', $missing_detect ) );
}

// A post's "root" source id: the source it translates, or itself if it is a source.
$root_of = function ( $pid ) use ( $src_of ) {
	$s = isset( $src_of[ $pid ] ) ? (int) $src_of[ $pid ] : 0;
	return ( $s > 0 && $s !== $pid ) ? $s : $pid;
};

// Decide per-post action: 'trash_extra', 'retag', 'keep', or 'skip'.
// Then dedupe survivors per (root source, true language).
$plan_trash_extra = array(); // pid => true_lang
$plan_retag       = array(); // pid => [from,to]
$survivors        = array(); // pid => true_base

foreach ( $all_ids as $pid ) {
	$true = $true_of[ $pid ];
	if ( 'und' === $true ) {
		continue; // Leave undetermined posts alone.
	}
	$tb = $base_of( $true );
	if ( ! isset( $keep_set[ $tb ] ) ) {
		$plan_trash_extra[ $pid ] = $true; // Extra language -> trash.
		continue;
	}
	$survivors[ $pid ] = $tb;
	$cur_base = $base_of( $tag_of[ $pid ] );
	if ( $cur_base !== $tb ) {
		// Re-tag to the registered slug for this base (if available).
		$to = isset( $base_to_slug[ $tb ] ) ? $base_to_slug[ $tb ] : $tb;
		$plan_retag[ $pid ] = array( $tag_of[ $pid ], $to );
	}
}

// Dedupe: group survivors by (root source, true base). Keep best, trash rest.
// "Best" score: correctly-tagged + published + content length + low ID.
$score_of = function ( $pid ) use ( $survivors, $tag_of, $base_of, $status_of, $len_of ) {
	$s = 0;
	if ( $base_of( $tag_of[ $pid ] ) === $survivors[ $pid ] ) {
		$s += 1000000;
	}
	if ( 'publish' === $status_of[ $pid ] ) {
		$s += 500000;
	}
	$s += min( 400000, (int) $len_of[ $pid ] ); // Prefer more content.
	return $s;
};

$groups = array(); // key "root|base" => [pids]
foreach ( $survivors as $pid => $tb ) {
	$root = $root_of( $pid );
	$key  = $root . '|' . $tb;
	$groups[ $key ][] = $pid;
}

$plan_trash_dupe = array(); // pid => reason
foreach ( $groups as $key => $pids ) {
	if ( count( $pids ) < 2 ) {
		continue;
	}
	// Choose keeper: highest score, then lowest ID.
	usort(
		$pids,
		function ( $a, $b ) use ( $score_of ) {
			$sa = $score_of( $a );
			$sb = $score_of( $b );
			if ( $sa === $sb ) {
				return $a - $b;
			}
			return $sb - $sa;
		}
	);
	$keeper = array_shift( $pids );
	foreach ( $pids as $loser ) {
		$plan_trash_dupe[ $loser ] = 'dupe of #' . $keeper . ' (' . $key . ')';
	}
}

// Secondary dedupe among remaining survivors by (true base, normalized title) —
// catches cross-source and root-vs-root duplicates.
$by_title = array();
foreach ( $survivors as $pid => $tb ) {
	if ( isset( $plan_trash_dupe[ $pid ] ) ) {
		continue;
	}
	$norm = $normalize_title( $title_of[ $pid ] );
	if ( '' === $norm ) {
		continue;
	}
	$by_title[ $tb . '|' . $norm ][] = $pid;
}
foreach ( $by_title as $key => $pids ) {
	if ( count( $pids ) < 2 ) {
		continue;
	}
	usort(
		$pids,
		function ( $a, $b ) use ( $score_of ) {
			$sa = $score_of( $a );
			$sb = $score_of( $b );
			if ( $sa === $sb ) {
				return $a - $b;
			}
			return $sb - $sa;
		}
	);
	$keeper = array_shift( $pids );
	foreach ( $pids as $loser ) {
		$plan_trash_dupe[ $loser ] = 'title-dupe of #' . $keeper;
	}
}

// Don't bother re-tagging a post that is going to be trashed anyway.
foreach ( array_keys( $plan_trash_dupe ) as $pid ) {
	unset( $plan_retag[ $pid ] );
}

$total_trash = count( $plan_trash_extra ) + count( $plan_trash_dupe );

WP_CLI::log( '' );
WP_CLI::log( '## PLAN' );
WP_CLI::log( str_repeat( '-', 70 ) );
WP_CLI::log( sprintf( '  Re-tag to true language:        %d', count( $plan_retag ) ) );
WP_CLI::log( sprintf( '  Trash (extra language):         %d', count( $plan_trash_extra ) ) );
WP_CLI::log( sprintf( '  Trash (duplicate):              %d', count( $plan_trash_dupe ) ) );
WP_CLI::log( sprintf( '  Total trashed:                  %d', $total_trash ) );
WP_CLI::log( sprintf( '  Survivors kept:                 %d', count( $survivors ) - count( $plan_trash_dupe ) ) );

// Projected per-language survivor counts.
$proj = array();
foreach ( $survivors as $pid => $tb ) {
	if ( isset( $plan_trash_dupe[ $pid ] ) ) {
		continue;
	}
	$proj[ $tb ] = isset( $proj[ $tb ] ) ? $proj[ $tb ] + 1 : 1;
}
arsort( $proj );
WP_CLI::log( '  Projected counts after fix (by language):' );
foreach ( $proj as $tb => $n ) {
	WP_CLI::log( sprintf( '     %-6s %d', $tb, $n ) );
}

// =====================================================================
// PLAN (dry-run): list samples + CSV, then stop.
// =====================================================================
if ( 'plan' === $mode ) {
	$show = 0;
	WP_CLI::log( '' );
	WP_CLI::log( '  Sample re-tags (first 20):' );
	foreach ( $plan_retag as $pid => $ft ) {
		if ( $show >= 20 ) {
			break;
		}
		WP_CLI::log( sprintf( '     #%d  %s -> %s  | %s', $pid, $ft[0] ? $ft[0] : '(none)', $ft[1], mb_substr( $title_of[ $pid ], 0, 50 ) ) );
		++$show;
	}
	$show = 0;
	WP_CLI::log( '  Sample trashes (first 20):' );
	foreach ( $plan_trash_extra + $plan_trash_dupe as $pid => $why ) {
		if ( $show >= 20 ) {
			break;
		}
		$reason = isset( $plan_trash_extra[ $pid ] ) ? ( 'extra:' . $plan_trash_extra[ $pid ] ) : $plan_trash_dupe[ $pid ];
		WP_CLI::log( sprintf( '     #%d  [%s]  %s', $pid, $true_of[ $pid ], $reason ) );
		++$show;
	}

	// CSV of the full plan.
	$upload = wp_upload_dir();
	if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
		$file = trailingslashit( $upload['basedir'] ) . sprintf( 'cq-afi-fixlang-plan-site%d-%s.csv', $site_id, gmdate( 'Ymd-His' ) );
		$fh   = fopen( $file, 'w' );
		if ( $fh ) {
			fputcsv( $fh, array( 'action', 'post_id', 'true_lang', 'from_tag', 'to_or_reason', 'title' ) );
			foreach ( $plan_retag as $pid => $ft ) {
				fputcsv( $fh, array( 'retag', $pid, $true_of[ $pid ], $ft[0], $ft[1], $title_of[ $pid ] ) );
			}
			foreach ( $plan_trash_extra as $pid => $tl ) {
				fputcsv( $fh, array( 'trash_extra', $pid, $tl, $tag_of[ $pid ], 'extra-language', $title_of[ $pid ] ) );
			}
			foreach ( $plan_trash_dupe as $pid => $why ) {
				fputcsv( $fh, array( 'trash_dupe', $pid, $true_of[ $pid ], $tag_of[ $pid ], $why, $title_of[ $pid ] ) );
			}
			fclose( $fh );
			WP_CLI::success( 'Plan CSV written: ' . $file );
		}
	}
	WP_CLI::log( str_repeat( '-', 70 ) );
	WP_CLI::success( 'DRY RUN only — nothing changed. Re-run with "apply" to execute.' );
	if ( $switched ) {
		restore_current_blog();
	}
	return;
}

// =====================================================================
// APPLY — execute re-tags then trashes (reversible)
// =====================================================================
if ( 'apply' === $mode ) {
	$retagged = 0;
	foreach ( $plan_retag as $pid => $ft ) {
		if ( '' === (string) get_post_meta( $pid, $meta_orig_lang, true ) ) {
			update_post_meta( $pid, $meta_orig_lang, $ft[0] );
		}
		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $pid, $ft[1] );
		}
		++$retagged;
	}
	$trashed = 0;
	foreach ( array_merge( array_keys( $plan_trash_extra ), array_keys( $plan_trash_dupe ) ) as $pid ) {
		update_post_meta( $pid, $meta_trashed, '1' );
		wp_trash_post( (int) $pid );
		++$trashed;
	}
	WP_CLI::log( str_repeat( '-', 70 ) );
	WP_CLI::success( sprintf( 'APPLIED: re-tagged %d, trashed %d. Run "relink" next, then reorder-post-dates. Use "undo" to revert.', $retagged, $trashed ) );
	if ( $switched ) {
		restore_current_blog();
	}
	return;
}

// =====================================================================
// RELINK — rebuild Polylang translation groups from source meta
// =====================================================================
if ( 'relink' === $mode ) {
	if ( ! function_exists( 'pll_save_post_translations' ) ) {
		WP_CLI::error( 'Polylang functions unavailable; cannot relink.' );
	}
	// Re-read survivors (exclude trashed).
	$live = get_posts(
		array(
			'post_type'        => $post_type,
			'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	);
	$live      = array_map( 'intval', $live );
	$live_set  = array_fill_keys( $live, true );
	$src_live  = array();
	foreach ( $live as $pid ) {
		$src_live[ $pid ] = (int) get_post_meta( $pid, $meta_source, true );
	}
	// Build group: root => [ base => pid ] using current (now-corrected) language.
	$grp = array();
	foreach ( $live as $pid ) {
		$s    = $src_live[ $pid ];
		$root = ( $s > 0 && $s !== $pid && isset( $live_set[ $s ] ) ) ? $s : $pid;
		$lang = $current_lang( $pid );
		$b    = $base_of( $lang );
		if ( '' === $b ) {
			continue;
		}
		// First writer wins per language (keeper should already be unique post-dedupe).
		if ( ! isset( $grp[ $root ][ $b ] ) ) {
			$grp[ $root ][ $b ] = array( 'pid' => $pid, 'slug' => $lang );
		}
	}
	$linked = 0;
	foreach ( $grp as $root => $members ) {
		if ( count( $members ) < 1 ) {
			continue;
		}
		$assoc = array();
		foreach ( $members as $b => $info ) {
			$assoc[ $info['slug'] ] = (int) $info['pid'];
		}
		pll_save_post_translations( $assoc );
		++$linked;
	}
	WP_CLI::log( str_repeat( '-', 70 ) );
	WP_CLI::success( sprintf( 'RELINK complete: rebuilt %d translation group(s). Next: run tools/reorder-post-dates.php.', $linked ) );
	if ( $switched ) {
		restore_current_blog();
	}
	return;
}

WP_CLI::error( 'Unknown mode. Use: detect | plan | apply | relink | undo.' );
