<?php
/**
 * READ-ONLY language diagnostics for the Polylang translation set.
 *
 * Symptom this exists to explain: on the Posts admin screen the per-language
 * counts are nonsensical — English (the source language every post is written
 * in) shows the LOWEST count while every translated language shows MORE posts
 * than English. That is impossible for a healthy set: translations are derived
 * from English, so no language can legitimately exceed the English count. The
 * excess must be (a) DUPLICATE translations and/or (b) MIS-LABELLED posts whose
 * real content language differs from the Polylang language they are tagged with.
 *
 * This script WRITES NOTHING. It only reports. Run it, read the output (and the
 * optional CSV it can dump), and then a separate fix script is written against
 * the specific problems it surfaces.
 *
 * What it checks:
 *   1. Counts per language broken down by post_status (publish/draft/etc.), plus
 *      how many posts have NO language assigned. Confirms/explains the admin menu.
 *   2. Source-vs-translation structure: how many posts are originals, how many are
 *      translations, how many distinct source posts are referenced, and the
 *      distribution of translations-per-source.
 *   3. DUPLICATE translations: more than one post sharing the same
 *      (_cq_afi_source_post_id, language). This is the most likely inflation cause.
 *   4. DUPLICATE by normalized title within a language (catches dupes lacking the
 *      source meta).
 *   5. ORPHANS: translations whose source post is missing/trashed; and English
 *      originals that have zero translations.
 *   6. SCRIPT mismatch (pure code, free, reliable): detects the dominant Unicode
 *      script of each post's content (Han/Kana/Arabic/Devanagari/Cyrillic/Latin)
 *      and flags posts whose script is incompatible with the language they are
 *      tagged as — e.g. Chinese content tagged "French", or Latin content tagged
 *      "Chinese". CJK/Arabic/Hindi/Russian mislabels are caught with certainty.
 *   7. LATIN guess (pure code, low confidence): a stopword/diacritic heuristic to
 *      flag Latin-script posts whose apparent language differs from the tag
 *      (English-tagged-as-French, etc.). Heuristic only — AI confirms.
 *   8. Optional AI pass (`ai` keyword): uses the plugin's saved OpenAI key/model to
 *      identify the real language of flagged/sampled posts. Capped by `limit=N`.
 *
 * MULTISITE: operates on ONE site. Pass the numeric site (blog) ID, or omit it to
 * get an interactive picker. On single-site the site argument is ignored.
 *
 * USAGE (run from the WordPress root with WP-CLI):
 *
 *   # Full pure-code report on site 2 (no OpenAI calls, free):
 *   wp eval-file wp-content/plugins/cq-auto-featured-image-ai-translate/tools/diagnose-languages.php 2
 *
 *   # Same, plus write a CSV of every flagged post to wp-content/uploads/:
 *   wp eval-file .../tools/diagnose-languages.php 2 csv
 *
 *   # Add an OpenAI confirmation pass on up to 80 flagged posts:
 *   wp eval-file .../tools/diagnose-languages.php 2 ai limit=80
 *
 *   # Restrict the AI/heuristic focus to one language slug:
 *   wp eval-file .../tools/diagnose-languages.php 2 ai lang=fr limit=100
 *
 * Optional final positional arg sets a post type other than 'post'.
 *
 * @package CQ_Auto_Featured_Image_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "This script must be run with WP-CLI: wp eval-file <path-to-this-file> [site_id] [ai] [csv] [limit=N] [lang=slug] [post_type]\n";
	return;
}

// WP-CLI `eval-file` runs in the global scope by design.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

// ---- Parse arguments order-independently ----
$argv_in   = isset( $args ) && is_array( $args ) ? $args : array();
$site_id   = 0;
$post_type = 'post';
$use_ai    = false;
$want_csv  = false;
$ai_limit  = 60;
$focus_lang = '';
foreach ( $argv_in as $raw ) {
	$arg = strtolower( trim( (string) $raw ) );
	if ( '' === $arg ) {
		continue;
	}
	if ( 'ai' === $arg ) {
		$use_ai = true;
	} elseif ( 'csv' === $arg ) {
		$want_csv = true;
	} elseif ( ctype_digit( $arg ) ) {
		$site_id = (int) $arg;
	} elseif ( 0 === strpos( $arg, 'limit=' ) ) {
		$ai_limit = max( 1, (int) substr( $arg, 6 ) );
	} elseif ( 0 === strpos( $arg, 'lang=' ) ) {
		$focus_lang = sanitize_key( substr( $arg, 5 ) );
	} else {
		$post_type = sanitize_key( $arg );
	}
}

$meta_source = '_cq_afi_source_post_id';
$switched    = false;

// ---- Multisite: resolve and switch into the target site ----
if ( is_multisite() ) {
	if ( $site_id <= 0 ) {
		$sites = get_sites(
			array(
				'number'  => 0,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
		WP_CLI::log( 'This is a multisite network. Available sites:' );
		WP_CLI::log( str_repeat( '-', 70 ) );
		foreach ( $sites as $site ) {
			$bid     = (int) $site->blog_id;
			$details = get_blog_details( array( 'blog_id' => $bid ) );
			$name    = $details ? $details->blogname : '(unknown)';
			$url     = $details ? $details->siteurl : get_site_url( $bid );
			WP_CLI::log( sprintf( '  [%d]  %s  —  %s', $bid, $name, $url ) );
		}
		WP_CLI::log( str_repeat( '-', 70 ) );
		fwrite( STDOUT, 'Enter the site ID to operate on: ' );
		$entered = is_resource( STDIN ) ? fgets( STDIN ) : false;
		$site_id = ( false !== $entered ) ? (int) trim( $entered ) : 0;
	}

	if ( $site_id <= 0 || ! get_blog_details( array( 'blog_id' => $site_id ) ) ) {
		WP_CLI::error( sprintf( 'Invalid or missing site ID (%d). Re-run and pass the numeric site ID, e.g. "... 2".', $site_id ) );
	}

	switch_to_blog( $site_id );
	$switched = true;
	WP_CLI::log( sprintf( 'Operating on site #%d (%s)', $site_id, get_site_url( $site_id ) ) );
}

global $wpdb;

WP_CLI::log( str_repeat( '=', 70 ) );
WP_CLI::log( sprintf( 'LANGUAGE DIAGNOSTICS  |  post_type=%s  |  AI=%s', $post_type, $use_ai ? 'on' : 'off' ) );
WP_CLI::log( str_repeat( '=', 70 ) );

// Statuses we consider "real" content (exclude revisions/auto-drafts/inherit).
// The five "real" content statuses. The SQL below uses five literal %s
// placeholders to match, bound via $wpdb->prepare(), so keep these in sync.
$real_statuses = array( 'publish', 'future', 'draft', 'pending', 'private' );

// ---- Prefetch: language slug per post id (one query) ----
$lang_of  = array();
$lang_rows = $wpdb->get_results(
	"SELECT tr.object_id AS pid, t.slug AS slug
	 FROM {$wpdb->term_relationships} tr
	 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'language'
	 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id"
);
if ( $lang_rows ) {
	foreach ( $lang_rows as $r ) {
		$lang_of[ (int) $r->pid ] = (string) $r->slug;
	}
}

// ---- Prefetch: source-post meta per post id (one query) ----
$source_of = array();
$src_rows  = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
		$meta_source
	)
);
if ( $src_rows ) {
	foreach ( $src_rows as $r ) {
		$src = (int) $r->meta_value;
		if ( $src > 0 ) {
			$source_of[ (int) $r->post_id ] = $src;
		}
	}
}

// =====================================================================
// SECTION 1 — Counts per language × status, and posts with no language
// =====================================================================
WP_CLI::log( '' );
WP_CLI::log( '## 1. Counts per language (by post_status)' );
WP_CLI::log( str_repeat( '-', 70 ) );

$count_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT t.slug AS slug, t.name AS name, p.post_status AS st, COUNT(*) AS n
		 FROM {$wpdb->posts} p
		 JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
		 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'language'
		 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
		 WHERE p.post_type = %s AND p.post_status IN ( %s, %s, %s, %s, %s )
		 GROUP BY t.slug, t.name, p.post_status",
		array_merge( array( $post_type ), $real_statuses )
	)
);

$by_lang = array(); // slug => [name, total, status=>n]
if ( $count_rows ) {
	foreach ( $count_rows as $r ) {
		$slug = (string) $r->slug;
		if ( ! isset( $by_lang[ $slug ] ) ) {
			$by_lang[ $slug ] = array(
				'name'  => (string) $r->name,
				'total' => 0,
				'st'    => array(),
			);
		}
		$by_lang[ $slug ]['st'][ (string) $r->st ] = (int) $r->n;
		$by_lang[ $slug ]['total']                += (int) $r->n;
	}
}

// Sort languages by total DESC so the anomaly (English low) is obvious.
uasort(
	$by_lang,
	function ( $a, $b ) {
		return $b['total'] - $a['total'];
	}
);

$grand_total = 0;
foreach ( $by_lang as $slug => $info ) {
	$grand_total += $info['total'];
	$parts        = array();
	foreach ( $real_statuses as $st ) {
		if ( ! empty( $info['st'][ $st ] ) ) {
			$parts[] = $st . '=' . $info['st'][ $st ];
		}
	}
	WP_CLI::log(
		sprintf(
			'  %-8s %-16s total=%-6d  (%s)',
			$slug,
			'[' . $info['name'] . ']',
			$info['total'],
			implode( ', ', $parts )
		)
	);
}

// Posts of this type with NO language term at all.
$total_real = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		 WHERE p.post_type = %s AND p.post_status IN ( %s, %s, %s, %s, %s )",
		array_merge( array( $post_type ), $real_statuses )
	)
);
$with_lang = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
		 JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
		 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'language'
		 WHERE p.post_type = %s AND p.post_status IN ( %s, %s, %s, %s, %s )",
		array_merge( array( $post_type ), $real_statuses )
	)
);
$no_lang = $total_real - $with_lang;

WP_CLI::log( str_repeat( '-', 70 ) );
WP_CLI::log( sprintf( '  Total %s posts (real statuses): %d', $post_type, $total_real ) );
WP_CLI::log( sprintf( '  With a language assigned:       %d', $with_lang ) );
WP_CLI::log( sprintf( '  With NO language assigned:      %d', $no_lang ) );
WP_CLI::log( '' );
WP_CLI::log( '  NOTE: every language above English in this list is suspect — English' );
WP_CLI::log( '  is the source language, so no language should exceed it. The gap is' );
WP_CLI::log( '  duplicates and/or mislabeled posts (Sections 3-7).' );

// =====================================================================
// SECTION 2 — Source-vs-translation structure
// =====================================================================
WP_CLI::log( '' );
WP_CLI::log( '## 2. Source-vs-translation structure (via _cq_afi_source_post_id)' );
WP_CLI::log( str_repeat( '-', 70 ) );

// All real post IDs of this type.
$all_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p
		 WHERE p.post_type = %s AND p.post_status IN ( %s, %s, %s, %s, %s )",
		array_merge( array( $post_type ), $real_statuses )
	)
);
$all_ids   = array_map( 'intval', $all_ids );
$id_exists = array_fill_keys( $all_ids, true );

$originals       = 0; // No source meta (or points to self).
$translations    = 0; // Has source meta pointing elsewhere.
$src_to_children = array(); // source_id => [child ids]
foreach ( $all_ids as $pid ) {
	$src = isset( $source_of[ $pid ] ) ? (int) $source_of[ $pid ] : 0;
	if ( $src > 0 && $src !== $pid ) {
		++$translations;
		$src_to_children[ $src ][] = $pid;
	} else {
		++$originals;
	}
}

$distinct_sources = count( $src_to_children );

// Distribution of translations-per-source.
$dist = array();
foreach ( $src_to_children as $src => $kids ) {
	$n            = count( $kids );
	$dist[ $n ]   = isset( $dist[ $n ] ) ? $dist[ $n ] + 1 : 1;
}
ksort( $dist );

WP_CLI::log( sprintf( '  Originals (no source meta):        %d', $originals ) );
WP_CLI::log( sprintf( '  Translations (have source meta):   %d', $translations ) );
WP_CLI::log( sprintf( '  Distinct source posts referenced:  %d', $distinct_sources ) );
WP_CLI::log( '  Translations-per-source distribution (children => how many sources):' );
foreach ( $dist as $children => $sources ) {
	WP_CLI::log( sprintf( '     %2d translation(s): %d source post(s)', $children, $sources ) );
}
WP_CLI::log( '  (With 11 target languages, a healthy source has <= 11 translations.' );
WP_CLI::log( '   Anything above 11 means duplicate translations exist — see Section 3.)' );

// =====================================================================
// SECTION 3 — Duplicate translations: same (source, language)
// =====================================================================
WP_CLI::log( '' );
WP_CLI::log( '## 3. DUPLICATE translations — same source + same language' );
WP_CLI::log( str_repeat( '-', 70 ) );

$dupe_sets     = 0;
$dupe_excess   = 0; // Total redundant posts (count beyond the first per set).
$dupe_by_lang  = array(); // lang => excess count
$dupe_examples = array();
foreach ( $src_to_children as $src => $kids ) {
	$per_lang = array();
	foreach ( $kids as $kid ) {
		$kl              = isset( $lang_of[ $kid ] ) ? $lang_of[ $kid ] : '(none)';
		$per_lang[ $kl ][] = $kid;
	}
	foreach ( $per_lang as $kl => $members ) {
		if ( count( $members ) > 1 ) {
			++$dupe_sets;
			$excess                 = count( $members ) - 1;
			$dupe_excess           += $excess;
			$dupe_by_lang[ $kl ]    = isset( $dupe_by_lang[ $kl ] ) ? $dupe_by_lang[ $kl ] + $excess : $excess;
			if ( count( $dupe_examples ) < 60 ) {
				sort( $members );
				$dupe_examples[] = sprintf( 'source #%d  [%s]  dupes: %s', $src, $kl, implode( ', ', $members ) );
			}
		}
	}
}

WP_CLI::log( sprintf( '  Duplicate sets (a source with >1 post in one language): %d', $dupe_sets ) );
WP_CLI::log( sprintf( '  Total redundant posts (could be removed/merged):        %d', $dupe_excess ) );
if ( $dupe_by_lang ) {
	arsort( $dupe_by_lang );
	WP_CLI::log( '  Redundant posts by language:' );
	foreach ( $dupe_by_lang as $kl => $n ) {
		WP_CLI::log( sprintf( '     %-8s %d', $kl, $n ) );
	}
}
if ( $dupe_examples ) {
	WP_CLI::log( '  Examples (first 60):' );
	foreach ( $dupe_examples as $line ) {
		WP_CLI::log( '     ' . $line );
	}
}

// =====================================================================
// SECTION 4 — Duplicate by normalized title within a language
// =====================================================================
WP_CLI::log( '' );
WP_CLI::log( '## 4. DUPLICATE by normalized title within the same language' );
WP_CLI::log( str_repeat( '-', 70 ) );

$normalize_title = function ( $t ) {
	$t = wp_strip_all_tags( (string) $t );
	$t = html_entity_decode( $t, ENT_QUOTES, 'UTF-8' );
	$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t, 'UTF-8' ) : strtolower( $t );
	// Drop common duplicate markers and trailing numeric suffixes.
	$t = preg_replace( '/\s*\((?:copy|copia|kopie|copie|duplicate)\)\s*$/u', '', $t );
	$t = preg_replace( '/[-_\s]+\d+$/u', '', $t );
	$t = preg_replace( '/\s+/u', ' ', $t );
	return trim( $t );
};

// Pull titles in chunks to build (lang|title) buckets.
$title_buckets = array(); // key "lang\x1ftitle" => [ids]
$chunks        = array_chunk( $all_ids, 500 );
foreach ( $chunks as $chunk ) {
	$ph = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ph is %d placeholders only; integer IDs bound via prepare().
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ( {$ph} )", $chunk ) );
	if ( ! $rows ) {
		continue;
	}
	foreach ( $rows as $row ) {
		$pid   = (int) $row->ID;
		$norm  = $normalize_title( $row->post_title );
		if ( '' === $norm ) {
			continue;
		}
		$lk            = isset( $lang_of[ $pid ] ) ? $lang_of[ $pid ] : '(none)';
		$key           = $lk . "\x1f" . $norm;
		$title_buckets[ $key ][] = $pid;
	}
}

$title_dupe_sets  = 0;
$title_dupe_posts = 0;
$title_examples   = array();
foreach ( $title_buckets as $key => $ids2 ) {
	if ( count( $ids2 ) > 1 ) {
		++$title_dupe_sets;
		$title_dupe_posts += count( $ids2 ) - 1;
		if ( count( $title_examples ) < 50 ) {
			list( $lk, $norm ) = explode( "\x1f", $key, 2 );
			sort( $ids2 );
			$title_examples[] = sprintf( '[%s] "%s"  ->  %s', $lk, mb_substr( $norm, 0, 60 ), implode( ', ', $ids2 ) );
		}
	}
}
WP_CLI::log( sprintf( '  Same-language same-title sets: %d  (redundant posts: %d)', $title_dupe_sets, $title_dupe_posts ) );
if ( $title_examples ) {
	WP_CLI::log( '  Examples (first 50):' );
	foreach ( $title_examples as $line ) {
		WP_CLI::log( '     ' . $line );
	}
}

// =====================================================================
// SECTION 5 — Orphans
// =====================================================================
WP_CLI::log( '' );
WP_CLI::log( '## 5. ORPHANS' );
WP_CLI::log( str_repeat( '-', 70 ) );

$orphan_missing_src = array(); // translation -> missing source id
foreach ( $all_ids as $pid ) {
	$src = isset( $source_of[ $pid ] ) ? (int) $source_of[ $pid ] : 0;
	if ( $src > 0 && $src !== $pid && empty( $id_exists[ $src ] ) ) {
		$orphan_missing_src[ $pid ] = $src;
	}
}

// Originals (English, in practice) with zero recorded translations.
$lonely_originals = 0;
foreach ( $all_ids as $pid ) {
	$src = isset( $source_of[ $pid ] ) ? (int) $source_of[ $pid ] : 0;
	$is_original = ( $src <= 0 || $src === $pid );
	if ( $is_original && empty( $src_to_children[ $pid ] ) ) {
		++$lonely_originals;
	}
}

WP_CLI::log( sprintf( '  Translations whose source post is missing/trashed: %d', count( $orphan_missing_src ) ) );
$shown = 0;
foreach ( $orphan_missing_src as $pid => $src ) {
	if ( $shown >= 40 ) {
		WP_CLI::log( '     ... (truncated)' );
		break;
	}
	$lk = isset( $lang_of[ $pid ] ) ? $lang_of[ $pid ] : '(none)';
	WP_CLI::log( sprintf( '     #%d [%s] -> missing source #%d', $pid, $lk, $src ) );
	++$shown;
}
WP_CLI::log( sprintf( '  Originals with zero translations recorded: %d', $lonely_originals ) );

// =====================================================================
// SECTION 6/7 — Per-post content scan: script + Latin heuristic
// =====================================================================
WP_CLI::log( '' );
WP_CLI::log( '## 6. SCRIPT mismatch (pure code) + ## 7. Latin guess (heuristic)' );
WP_CLI::log( str_repeat( '-', 70 ) );

// Expected script bucket per language base code.
$lang_base = function ( $slug ) {
	$slug = strtolower( (string) $slug );
	$slug = str_replace( '-', '_', $slug );
	$base = explode( '_', $slug );
	return $base[0];
};
$expected_script = function ( $base ) {
	switch ( $base ) {
		case 'zh':
			return 'han';
		case 'ja':
			return 'jpn'; // han + kana
		case 'ar':
			return 'arabic';
		case 'hi':
			return 'devanagari';
		case 'ru':
		case 'uk':
		case 'bg':
			return 'cyrillic';
		default:
			return 'latin'; // en, es, de, fr, pt, tr, it, nl, ...
	}
};

// Detect the dominant script bucket of a text sample.
$detect_script = function ( $text ) {
	$counts = array(
		'han'        => preg_match_all( '/\p{Han}/u', $text ),
		'kana'       => preg_match_all( '/[\p{Hiragana}\p{Katakana}]/u', $text ),
		'arabic'     => preg_match_all( '/\p{Arabic}/u', $text ),
		'devanagari' => preg_match_all( '/\p{Devanagari}/u', $text ),
		'cyrillic'   => preg_match_all( '/\p{Cyrillic}/u', $text ),
		'latin'      => preg_match_all( '/\p{Latin}/u', $text ),
	);
	$total = array_sum( $counts );
	if ( $total < 10 ) {
		return array( 'script' => 'unknown', 'conf' => 0.0, 'counts' => $counts );
	}
	if ( $counts['kana'] > 0 && ( $counts['kana'] + $counts['han'] ) / max( 1, $total ) > 0.2 ) {
		return array( 'script' => 'jpn', 'conf' => ( $counts['kana'] + $counts['han'] ) / $total, 'counts' => $counts );
	}
	$best = 'latin';
	$bn   = $counts['latin'];
	foreach ( array( 'han', 'arabic', 'devanagari', 'cyrillic' ) as $s ) {
		if ( $counts[ $s ] > $bn ) {
			$best = $s;
			$bn   = $counts[ $s ];
		}
	}
	return array( 'script' => $best, 'conf' => $bn / $total, 'counts' => $counts );
};

// Is a detected script compatible with the expected one?
$script_compatible = function ( $detected, $expected ) {
	if ( 'unknown' === $detected ) {
		return true; // Too little text to judge.
	}
	if ( 'jpn' === $expected ) {
		return ( 'jpn' === $detected || 'han' === $detected );
	}
	if ( 'han' === $expected ) {
		return ( 'han' === $detected || 'jpn' === $detected );
	}
	return $detected === $expected;
};

// Lightweight Latin-language guesser (stopwords + diacritics). Low confidence.
$latin_stop = array(
	'en' => array( 'the', 'and', 'of', 'to', 'in', 'is', 'that', 'for', 'with', 'are', 'this', 'was', 'as', 'be', 'on', 'it' ),
	'es' => array( 'que', 'de', 'la', 'el', 'los', 'las', 'una', 'por', 'con', 'para', 'como', 'pero', 'más', 'está', 'su' ),
	'de' => array( 'der', 'die', 'das', 'und', 'ist', 'nicht', 'mit', 'ein', 'auch', 'sich', 'dem', 'den', 'von', 'wird', 'zu' ),
	'fr' => array( 'le', 'la', 'les', 'des', 'une', 'dans', 'pour', 'que', 'qui', 'avec', 'sur', 'est', 'pas', 'plus', 'aux' ),
	'pt' => array( 'que', 'de', 'da', 'do', 'uma', 'com', 'para', 'como', 'mas', 'mais', 'está', 'não', 'os', 'as', 'um' ),
	'tr' => array( 've', 'bir', 'bu', 'için', 'ile', 'çok', 'daha', 'olan', 'gibi', 'ama', 'olarak', 'sonra', 'kadar' ),
);
$guess_latin = function ( $text ) use ( $latin_stop ) {
	$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	$words = preg_split( '/[^\p{L}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY );
	$total = count( $words );
	if ( $total < 12 ) {
		return array( 'lang' => '', 'score' => 0.0 );
	}
	$set    = array_flip( $words );
	$scores = array();
	foreach ( $latin_stop as $lng => $stops ) {
		$hits = 0;
		foreach ( $stops as $w ) {
			if ( isset( $set[ $w ] ) ) {
				++$hits;
			}
		}
		$scores[ $lng ] = $hits;
	}
	// Diacritic nudges.
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
	$best   = key( $scores );
	$bestsc = current( $scores );
	next( $scores );
	$second = current( $scores );
	$margin = $bestsc - (int) $second;
	if ( $bestsc < 2 || $margin < 1 ) {
		return array( 'lang' => '', 'score' => 0.0 ); // Too ambiguous.
	}
	return array( 'lang' => $best, 'score' => $bestsc );
};

// Scan posts in chunks; collect flags.
$script_mismatches = array(); // [pid, lang, detected, conf]
$latin_mismatches  = array(); // [pid, lang, guess, score]
$scanned           = 0;
foreach ( $chunks as $chunk ) {
	$ph = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ph is %d placeholders only; integer IDs bound via prepare().
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE ID IN ( {$ph} )", $chunk ) );
	if ( ! $rows ) {
		continue;
	}
	foreach ( $rows as $row ) {
		$pid  = (int) $row->ID;
		$lk   = isset( $lang_of[ $pid ] ) ? $lang_of[ $pid ] : '';
		if ( '' === $lk ) {
			continue;
		}
		if ( '' !== $focus_lang && $lk !== $focus_lang ) {
			continue;
		}
		++$scanned;
		$sample = wp_strip_all_tags( $row->post_title . ' ' . $row->post_content );
		$sample = preg_replace( '/\s+/u', ' ', $sample );
		$sample = trim( mb_substr( $sample, 0, 2000, 'UTF-8' ) );
		if ( '' === $sample ) {
			continue;
		}
		$base = $lang_base( $lk );
		$exp  = $expected_script( $base );
		$det  = $detect_script( $sample );

		if ( ! $script_compatible( $det['script'], $exp ) ) {
			$script_mismatches[] = array(
				'pid'      => $pid,
				'lang'     => $lk,
				'expected' => $exp,
				'detected' => $det['script'],
				'conf'     => round( $det['conf'], 2 ),
				'sample'   => mb_substr( $sample, 0, 60 ),
			);
		} elseif ( 'latin' === $exp ) {
			// Only worth a Latin guess when the script itself is fine (Latin).
			$g = $guess_latin( $sample );
			if ( '' !== $g['lang'] && $g['lang'] !== $base ) {
				$latin_mismatches[] = array(
					'pid'    => $pid,
					'lang'   => $lk,
					'guess'  => $g['lang'],
					'score'  => $g['score'],
					'sample' => mb_substr( $sample, 0, 60 ),
				);
			}
		}
	}
}

WP_CLI::log( sprintf( '  Posts scanned: %d', $scanned ) );
WP_CLI::log( '' );
WP_CLI::log( sprintf( '  6. SCRIPT mismatches (HIGH confidence — wrong character set): %d', count( $script_mismatches ) ) );
$shown = 0;
foreach ( $script_mismatches as $m ) {
	if ( $shown >= 100 ) {
		WP_CLI::log( '     ... (truncated; use csv for the full list)' );
		break;
	}
	WP_CLI::log( sprintf( '     #%d tagged [%s] expected=%s but content is %s (conf %.2f) "%s"', $m['pid'], $m['lang'], $m['expected'], $m['detected'], $m['conf'], $m['sample'] ) );
	++$shown;
}

// Tally script mismatches by tagged language.
if ( $script_mismatches ) {
	$smb = array();
	foreach ( $script_mismatches as $m ) {
		$smb[ $m['lang'] ] = isset( $smb[ $m['lang'] ] ) ? $smb[ $m['lang'] ] + 1 : 1;
	}
	arsort( $smb );
	WP_CLI::log( '     Script mismatches by tagged language:' );
	foreach ( $smb as $lng => $n ) {
		WP_CLI::log( sprintf( '        %-8s %d', $lng, $n ) );
	}
}

WP_CLI::log( '' );
WP_CLI::log( sprintf( '  7. LATIN guess mismatches (LOW confidence — needs AI to confirm): %d', count( $latin_mismatches ) ) );
$shown = 0;
foreach ( $latin_mismatches as $m ) {
	if ( $shown >= 60 ) {
		WP_CLI::log( '     ... (truncated; use csv for the full list)' );
		break;
	}
	WP_CLI::log( sprintf( '     #%d tagged [%s] looks like %s (score %d) "%s"', $m['pid'], $m['lang'], $m['guess'], $m['score'], $m['sample'] ) );
	++$shown;
}

// =====================================================================
// SECTION 8 — Optional AI confirmation pass
// =====================================================================
$ai_results = array();
if ( $use_ai ) {
	WP_CLI::log( '' );
	WP_CLI::log( '## 8. AI language confirmation (OpenAI)' );
	WP_CLI::log( str_repeat( '-', 70 ) );

	$api_key = (string) get_option( 'cq_afi_openai_api_key', '' );
	if ( '' === $api_key ) {
		WP_CLI::warning( 'No OpenAI API key saved (cq_afi_openai_api_key). Skipping AI pass.' );
	} else {
		$use_custom = (int) get_option( 'cq_afi_use_custom_model', 0 );
		$custom     = trim( (string) get_option( 'cq_afi_openai_custom_model', '' ) );
		$model      = ( $use_custom && $custom ) ? $custom : (string) get_option( 'cq_afi_openai_model', 'gpt-5.4-nano' );
		if ( '' === $model ) {
			$model = 'gpt-5.4-nano';
		}

		// Build the candidate list: script mismatches first (certain), then latin guesses.
		$candidates = array();
		foreach ( $script_mismatches as $m ) {
			$candidates[ $m['pid'] ] = $m['lang'];
		}
		foreach ( $latin_mismatches as $m ) {
			$candidates[ $m['pid'] ] = $m['lang'];
		}
		$candidates = array_slice( $candidates, 0, $ai_limit, true );

		WP_CLI::log( sprintf( '  Model: %s | Checking %d flagged post(s) (limit=%d)', $model, count( $candidates ), $ai_limit ) );

		$ai_detect = function ( $text ) use ( $api_key, $model ) {
			$text = trim( mb_substr( $text, 0, 1500, 'UTF-8' ) );
			if ( '' === $text ) {
				return '';
			}
			$body = array(
				'model' => $model,
				'input' => array(
					array(
						'role'    => 'system',
						'content' => array(
							array(
								'type' => 'input_text',
								'text' => 'You identify the dominant natural language of text. Respond with ONLY a JSON object {"lang":"<ISO 639-1 code>"} using codes such as en, es, fr, de, pt, zh, ja, ar, hi, ru, tr. No prose.',
							),
						),
					),
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'input_text',
								'text' => $text,
							),
						),
					),
				),
				'text'  => array( 'format' => array( 'type' => 'json_object' ) ),
				'max_output_tokens' => 2000,
			);
			$resp = wp_remote_post(
				'https://api.openai.com/v1/responses',
				array(
					'timeout' => 60,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
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
						foreach ( $item['content'] as $c ) {
							if ( isset( $c['text'] ) && is_string( $c['text'] ) ) {
								$out .= $c['text'];
							}
						}
					}
				}
			}
			$decoded = json_decode( trim( $out ), true );
			if ( is_array( $decoded ) && ! empty( $decoded['lang'] ) ) {
				return strtolower( substr( preg_replace( '/[^a-zA-Z]/', '', (string) $decoded['lang'] ), 0, 5 ) );
			}
			return '';
		};

		$confirmed = 0;
		foreach ( $candidates as $pid => $tagged ) {
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}
			$sample = wp_strip_all_tags( $post->post_title . ' ' . $post->post_content );
			$sample = trim( preg_replace( '/\s+/u', ' ', $sample ) );
			$real   = $ai_detect( $sample );
			$base   = $lang_base( $tagged );
			$mismatch = ( '' !== $real && $real !== $base );
			if ( $mismatch ) {
				++$confirmed;
			}
			$ai_results[] = array(
				'pid'    => $pid,
				'tagged' => $tagged,
				'ai'     => $real ? $real : '(unknown)',
				'flag'   => $mismatch ? 'MISLABELED' : 'ok',
			);
			WP_CLI::log( sprintf( '     #%d tagged [%s] -> AI says [%s]  %s', $pid, $tagged, $real ? $real : '?', $mismatch ? '*** MISLABELED ***' : '(ok)' ) );
		}
		WP_CLI::log( str_repeat( '-', 70 ) );
		WP_CLI::log( sprintf( '  AI confirmed %d of %d checked posts are mislabeled.', $confirmed, count( $candidates ) ) );
	}
}

// =====================================================================
// Optional CSV export of every flagged post
// =====================================================================
if ( $want_csv ) {
	$upload = wp_upload_dir();
	if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
		$stamp = gmdate( 'Ymd-His' );
		$file  = trailingslashit( $upload['basedir'] ) . sprintf( 'cq-afi-lang-diagnostics-site%d-%s.csv', $site_id, $stamp );
		$fh    = fopen( $file, 'w' );
		if ( $fh ) {
			fputcsv( $fh, array( 'category', 'post_id', 'tagged_lang', 'detected_or_dup', 'detail' ) );
			foreach ( $dupe_examples as $line ) {
				fputcsv( $fh, array( 'dupe_source_lang', '', '', '', $line ) );
			}
			foreach ( $title_examples as $line ) {
				fputcsv( $fh, array( 'dupe_title', '', '', '', $line ) );
			}
			foreach ( $orphan_missing_src as $pid => $src ) {
				fputcsv( $fh, array( 'orphan_missing_source', $pid, isset( $lang_of[ $pid ] ) ? $lang_of[ $pid ] : '', $src, '' ) );
			}
			foreach ( $script_mismatches as $m ) {
				fputcsv( $fh, array( 'script_mismatch', $m['pid'], $m['lang'], $m['detected'], $m['sample'] ) );
			}
			foreach ( $latin_mismatches as $m ) {
				fputcsv( $fh, array( 'latin_guess', $m['pid'], $m['lang'], $m['guess'], $m['sample'] ) );
			}
			foreach ( $ai_results as $m ) {
				fputcsv( $fh, array( 'ai_check', $m['pid'], $m['tagged'], $m['ai'], $m['flag'] ) );
			}
			fclose( $fh );
			WP_CLI::log( '' );
			WP_CLI::success( 'CSV written: ' . $file );
		} else {
			WP_CLI::warning( 'Could not open CSV file for writing: ' . $file );
		}
	} else {
		WP_CLI::warning( 'Upload directory unavailable; skipping CSV.' );
	}
}

// =====================================================================
// Summary
// =====================================================================
WP_CLI::log( '' );
WP_CLI::log( str_repeat( '=', 70 ) );
WP_CLI::log( 'SUMMARY' );
WP_CLI::log( str_repeat( '=', 70 ) );
WP_CLI::log( sprintf( '  Languages with more posts than English is the core anomaly.' ) );
WP_CLI::log( sprintf( '  Redundant duplicate translations (by source+lang): %d', $dupe_excess ) );
WP_CLI::log( sprintf( '  Redundant duplicate posts (by title):              %d', $title_dupe_posts ) );
WP_CLI::log( sprintf( '  Orphan translations (missing source):              %d', count( $orphan_missing_src ) ) );
WP_CLI::log( sprintf( '  Script mismatches (certain mislabels):             %d', count( $script_mismatches ) ) );
WP_CLI::log( sprintf( '  Latin-script suspected mislabels (need AI):        %d', count( $latin_mismatches ) ) );
WP_CLI::log( '' );
WP_CLI::log( '  Next: review these numbers. The fix script will (a) merge/remove' );
WP_CLI::log( '  duplicate translations keeping the best per source+language, (b)' );
WP_CLI::log( '  re-tag or re-translate mislabeled posts, and (c) re-link orphans.' );
WP_CLI::success( 'Language diagnosis complete (no changes written).' );

if ( $switched ) {
	restore_current_blog();
}
