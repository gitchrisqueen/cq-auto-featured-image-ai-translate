<?php
/**
 * Reorder posts so they display in post-ID order by rewriting their publish
 * dates. An earlier version of this plugin rewrote post dates and left the blog
 * showing posts in the wrong (reversed) order. WordPress orders the feed by
 * `post_date` (newest first), so this script assigns dates that line up with the
 * post IDs:
 *
 *   - The highest post ID (the newest post) gets TODAY's date.
 *   - Each older post (next lower ID) gets one day earlier, and so on.
 *
 * Result: the front-end list, ordered by date descending, shows posts in
 * descending post-ID order (newest ID first) — i.e. true creation order.
 *
 * The original time-of-day on the group's anchor post is preserved; only the
 * calendar date is changed. Because consecutive groups are spaced a full day
 * apart, the ordering is unambiguous regardless of the time component.
 *
 * TRANSLATION GROUPS: posts that are translations of one another are treated as
 * a single unit and given the SAME timestamp, so every language version shares
 * one publish date. Tied posts are detected with three signals, unioned together
 * so even partial or transitive links merge:
 *   1. `_cq_afi_source_post_id` post meta — the authoritative link this plugin
 *      writes on each translation (survives even if Polylang's own links break).
 *   2. The Polylang API (`pll_get_post`).
 *   3. The Polylang `post_translations` taxonomy.
 * The group is positioned by its highest-ID member and consumes one day-slot.
 *
 * MULTISITE: on a multisite network this operates on ONE site at a time. Pass
 * the numeric site (blog) ID as an argument, or omit it to get an interactive
 * list of sites to choose from. On a single-site install the site argument is
 * ignored.
 *
 * Run with WP-CLI (e.g. over SSH on SiteGround), from the WordPress root.
 * Arguments are order-independent: a keyword (diagnose/apply/undo) sets the mode,
 * a number selects the site, anything else is treated as the post type.
 *
 *   # DIAGNOSE — read-only: shows, per post, which translation links survive
 *   # (source meta / Polylang API / taxonomy) so you can confirm grouping. On
 *   # multisite, pass the site ID (or omit it to get the site picker):
 *   wp eval-file wp-content/plugins/cq-auto-featured-image-ai-translate/tools/reorder-post-dates.php diagnose 2
 *
 *   # DRY RUN (default) — shows what would change, writes nothing:
 *   wp eval-file wp-content/plugins/cq-auto-featured-image-ai-translate/tools/reorder-post-dates.php 2
 *
 *   # APPLY on site 2 (a rollback copy is saved first):
 *   wp eval-file wp-content/plugins/cq-auto-featured-image-ai-translate/tools/reorder-post-dates.php apply 2
 *
 *   # UNDO on site 2 (revert the last apply from the rollback copy):
 *   wp eval-file wp-content/plugins/cq-auto-featured-image-ai-translate/tools/reorder-post-dates.php undo 2
 *
 * Optionally pass a post type other than the default 'post', e.g. `... apply 2 page`.
 *
 * IMPORTANT: take a database backup before running with `apply`. Every targeted
 * post's current date is saved to post meta first, so `undo` can restore it, but
 * a database backup is the authoritative safety net.
 *
 * @package CQ_Auto_Featured_Image_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "This script must be run with WP-CLI: wp eval-file <path-to-this-file> [diagnose|apply|undo] [site_id] [post_type]\n";
	return;
}

// This is a WP-CLI `eval-file` script that runs in the global scope by design,
// so its working variables are top-level locals rather than function-scoped.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

// Parse arguments order-independently: keyword => mode, number => site, else => post type.
$argv_in   = isset( $args ) && is_array( $args ) ? $args : array();
$mode      = 'dry';
$site_id   = 0;
$post_type = 'post';
foreach ( $argv_in as $raw ) {
	$arg = strtolower( trim( (string) $raw ) );
	if ( '' === $arg ) {
		continue;
	}
	if ( in_array( $arg, array( 'diagnose', 'apply', 'undo', 'dry' ), true ) ) {
		$mode = $arg;
	} elseif ( ctype_digit( $arg ) ) {
		$site_id = (int) $arg;
	} else {
		$post_type = sanitize_key( $arg );
	}
}

$apply    = ( 'apply' === $mode );
$undo     = ( 'undo' === $mode );
$diagnose = ( 'diagnose' === $mode );

$meta_backup     = '_cq_afi_reorder_backup_post_date';
$meta_backup_gmt = '_cq_afi_reorder_backup_post_date_gmt';
$meta_source     = '_cq_afi_source_post_id';

$switched = false;

// On multisite, resolve which site to operate on, then switch into it.
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

		// Prompt for the site ID (interactive STDIN).
		fwrite( STDOUT, 'Enter the site ID to operate on: ' );
		$entered = is_resource( STDIN ) ? fgets( STDIN ) : false;
		$site_id = ( false !== $entered ) ? (int) trim( $entered ) : 0;
	}

	if ( $site_id <= 0 || ! get_blog_details( array( 'blog_id' => $site_id ) ) ) {
		WP_CLI::error( sprintf( 'Invalid or missing site ID (%d). Re-run and pass the numeric site ID, e.g. "... %s 2".', $site_id, $mode ) );
	}

	switch_to_blog( $site_id );
	$switched = true;
	WP_CLI::log( sprintf( 'Operating on site #%d (%s)', $site_id, get_site_url( $site_id ) ) );
}

global $wpdb;

// Ordered by ID DESCENDING: highest ID (newest) first so it receives today's date.
$ids = get_posts(
	array(
		'post_type'        => $post_type,
		'post_status'      => $undo ? 'any' : 'publish',
		'posts_per_page'   => -1,
		'orderby'          => 'ID',
		'order'            => 'DESC',
		'fields'           => 'ids',
		'suppress_filters' => true,
	)
);

WP_CLI::log( sprintf( 'Mode: %s | Post type: %s | Posts found: %d', strtoupper( $mode ), $post_type, count( $ids ) ) );
WP_CLI::log( str_repeat( '-', 70 ) );

// UNDO: restore each post's saved date and drop the backup meta. (No grouping needed.)
if ( $undo ) {
	$changed = 0;
	foreach ( $ids as $id ) {
		$id      = (int) $id;
		$bak     = (string) get_post_meta( $id, $meta_backup, true );
		$bak_gmt = (string) get_post_meta( $id, $meta_backup_gmt, true );
		if ( '' === $bak ) {
			continue;
		}
		$post = get_post( $id );
		WP_CLI::log( sprintf( '#%d  %s  restore  %s  ->  %s', $id, get_the_title( $id ), $post ? $post->post_date : '?', $bak ) );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => $bak,
				'post_date_gmt' => '' !== $bak_gmt ? $bak_gmt : get_gmt_from_date( $bak ),
			),
			array( 'ID' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		delete_post_meta( $id, $meta_backup );
		delete_post_meta( $id, $meta_backup_gmt );
		clean_post_cache( $id );
		++$changed;
	}
	WP_CLI::log( str_repeat( '-', 70 ) );
	WP_CLI::success( sprintf( 'Reverted %d post(s) from the rollback copy.', $changed ) );
	if ( $switched ) {
		restore_current_blog();
	}
	return;
}

// ---- Detect translation signals ----
$has_pll_fn   = function_exists( 'pll_get_post' );
$has_pll_tax  = taxonomy_exists( 'post_translations' );
$has_lang_tax = taxonomy_exists( 'language' );
$source_lang  = function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '';
if ( '' === $source_lang ) {
	$source_lang = 'en';
}

// Best-effort language label for output.
$lang_label = function ( $pid ) use ( $has_lang_tax ) {
	if ( ! $has_lang_tax ) {
		return '';
	}
	$lt = get_the_terms( (int) $pid, 'language' );
	if ( $lt && ! is_wp_error( $lt ) ) {
		return (string) $lt[0]->slug;
	}
	return '';
};

// Same-post-type guard so a stray link can never pull in a page/attachment.
$same_type = function ( $pid ) use ( $post_type ) {
	$p = get_post( (int) $pid );
	return $p && $p->post_type === $post_type;
};

// ---- Union-find over post IDs to merge translation links ----
$parent = array();
$find   = function ( $x ) use ( &$parent ) {
	$x = (int) $x;
	if ( ! isset( $parent[ $x ] ) ) {
		$parent[ $x ] = $x;
		return $x;
	}
	while ( $parent[ $x ] !== $x ) {
		$parent[ $x ] = $parent[ $parent[ $x ] ]; // Path halving.
		$x            = $parent[ $x ];
	}
	return $x;
};
$union = function ( $a, $b ) use ( &$parent, $find ) {
	$ra = $find( $a );
	$rb = $find( $b );
	if ( $ra !== $rb ) {
		$parent[ $ra ] = $rb;
	}
};

foreach ( $ids as $id ) {
	$id = (int) $id;
	$find( $id ); // Ensure every post is a node, even with no links.

	// Signal 1: authoritative source-post meta written by the plugin.
	$claimed = (int) get_post_meta( $id, $meta_source, true );
	if ( $claimed > 0 && $same_type( $claimed ) ) {
		$union( $id, $claimed );
	}

	// Signal 2: Polylang API source for this post.
	if ( $has_pll_fn ) {
		$pll_src = (int) pll_get_post( $id, $source_lang );
		if ( $pll_src > 0 && $same_type( $pll_src ) ) {
			$union( $id, $pll_src );
		}
	}

	// Signal 3: Polylang post_translations taxonomy group.
	if ( $has_pll_tax ) {
		$terms = get_the_terms( $id, 'post_translations' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$data = maybe_unserialize( $terms[0]->description );
			if ( is_array( $data ) ) {
				foreach ( $data as $mid ) {
					$mid = (int) $mid;
					if ( $mid > 0 && $same_type( $mid ) ) {
						$union( $id, $mid );
					}
				}
			}
		}
	}
}

// ---- DIAGNOSE: dump the raw signals per post, no writes ----
if ( $diagnose ) {
	$meta_cnt = 0;
	$pll_cnt  = 0;
	$tax_cnt  = 0;
	foreach ( $ids as $id ) {
		$id  = (int) $id;
		$sm  = (int) get_post_meta( $id, $meta_source, true );
		$pll = $has_pll_fn ? (int) pll_get_post( $id, $source_lang ) : 0;
		$tax = array();
		if ( $has_pll_tax ) {
			$terms = get_the_terms( $id, 'post_translations' );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$data = maybe_unserialize( $terms[0]->description );
				if ( is_array( $data ) ) {
					$tax = array_map( 'intval', array_values( $data ) );
				}
			}
		}
		if ( $sm > 0 ) {
			++$meta_cnt;
		}
		if ( $pll > 0 ) {
			++$pll_cnt;
		}
		if ( $tax ) {
			++$tax_cnt;
		}
		$lab = $lang_label( $id );
		WP_CLI::log(
			sprintf(
				'#%d %s group=%d  src_meta=%d  pll_src=%d  tax=[%s]  %s',
				$id,
				$lab ? '[' . $lab . ']' : '[?]',
				$find( $id ),
				$sm,
				$pll,
				implode( ',', $tax ),
				get_the_title( $id )
			)
		);
	}

	// How many groups have more than one member among the scanned posts?
	$sizes = array();
	foreach ( $ids as $id ) {
		$r           = $find( (int) $id );
		$sizes[ $r ] = isset( $sizes[ $r ] ) ? $sizes[ $r ] + 1 : 1;
	}
	$multi = 0;
	foreach ( $sizes as $n ) {
		if ( $n > 1 ) {
			++$multi;
		}
	}

	WP_CLI::log( str_repeat( '-', 70 ) );
	WP_CLI::log( sprintf( 'Posts scanned: %d', count( $ids ) ) );
	WP_CLI::log( sprintf( 'With source-post meta (_cq_afi_source_post_id): %d', $meta_cnt ) );
	WP_CLI::log( sprintf( 'With a Polylang API source: %d', $pll_cnt ) );
	WP_CLI::log( sprintf( 'With a post_translations taxonomy group: %d', $tax_cnt ) );
	WP_CLI::log( sprintf( 'Resulting multi-post groups: %d', $multi ) );
	WP_CLI::success( 'Diagnosis complete (no changes written).' );
	if ( $switched ) {
		restore_current_blog();
	}
	return;
}

// ---- Build groups (connected components), ordered by highest-ID member ----
$components = array();
foreach ( array_keys( $parent ) as $node ) {
	$node = (int) $node;
	if ( ! $same_type( $node ) ) {
		continue;
	}
	$r                  = $find( $node );
	$components[ $r ][] = $node;
}

$ordered = array();
foreach ( $components as $set ) {
	$member_ids = array_values( array_unique( array_map( 'intval', $set ) ) );
	rsort( $member_ids ); // Highest ID first; index 0 is the anchor.
	$ordered[] = $member_ids;
}
// Sort groups by anchor (highest member ID) descending.
usort(
	$ordered,
	function ( $a, $b ) {
		return $b[0] - $a[0];
	}
);

// ---- DRY-RUN / APPLY: assign today, today-1, today-2, ... per group ----
$base_date    = current_time( 'Y-m-d' );
$index        = 0;
$candidates   = 0;
$changed      = 0;
$groups_total = count( $ordered );

foreach ( $ordered as $members ) {
	$anchor = (int) $members[0];
	$apost  = get_post( $anchor );
	if ( ! $apost ) {
		continue;
	}

	// Preserve the anchor's time-of-day; only the calendar date moves.
	$time_part = substr( (string) $apost->post_date, 11, 8 );
	if ( '' === trim( $time_part ) || '00:00:00' === $time_part ) {
		$time_part = '12:00:00';
	}
	$target_date   = gmdate( 'Y-m-d', strtotime( $base_date . ' -' . $index . ' days' ) );
	$new_post_date = $target_date . ' ' . $time_part;
	$new_post_gmt  = get_gmt_from_date( $new_post_date );
	++$index;

	$tied = count( $members ) > 1;
	if ( $tied ) {
		WP_CLI::log( sprintf( 'Tied translation group (%d posts)  ->  %s', count( $members ), $new_post_date ) );
	}

	foreach ( $members as $gid ) {
		$gid   = (int) $gid;
		$gpost = ( $gid === $anchor ) ? $apost : get_post( $gid );
		if ( ! $gpost ) {
			continue;
		}
		if ( $gpost->post_date === $new_post_date ) {
			continue; // Already correct.
		}
		$lab = $lang_label( $gid );
		$lab = $lab ? '[' . $lab . '] ' : '';
		++$candidates;
		WP_CLI::log( sprintf( '%s#%d  %s%s  current=%s  ->  new=%s', $tied ? '  ' : '', $gid, $lab, get_the_title( $gid ), $gpost->post_date, $new_post_date ) );

		if ( $apply ) {
			// Save a one-time rollback copy of the current date before overwriting.
			if ( '' === (string) get_post_meta( $gid, $meta_backup, true ) ) {
				update_post_meta( $gid, $meta_backup, $gpost->post_date );
				update_post_meta( $gid, $meta_backup_gmt, $gpost->post_date_gmt );
			}
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_date'     => $new_post_date,
					'post_date_gmt' => $new_post_gmt,
				),
				array( 'ID' => $gid ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			clean_post_cache( $gid );
			++$changed;
		}
	}
}

WP_CLI::log( str_repeat( '-', 70 ) );

if ( $apply ) {
	WP_CLI::success( sprintf( 'Reordered %d post date(s) across %d group(s). Re-run with "undo" to revert.', $changed, $groups_total ) );
} else {
	WP_CLI::success( sprintf( 'DRY RUN: %d post(s) across %d group(s) would be reordered. Re-run with "apply" to write the changes.', $candidates, $groups_total ) );
}

if ( $switched ) {
	restore_current_blog();
}
