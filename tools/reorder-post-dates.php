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
 * The original time-of-day on each post is preserved; only the calendar date is
 * changed. Because consecutive posts are spaced a full day apart, the ordering
 * is unambiguous regardless of the time component.
 *
 * POLYLANG: posts that are translations of one another (tied via Polylang) are
 * treated as a single unit and given the SAME timestamp, so every language
 * version of a post shares one publish date. The group is positioned in the
 * ordering by its highest-ID member and consumes a single day-slot. This works
 * whether or not Polylang is active; without it, every post is its own group.
 *
 * MULTISITE: on a multisite network this operates on ONE site at a time. Pass
 * the numeric site (blog) ID as an argument, or omit it to get an interactive
 * list of sites to choose from. On a single-site install the site argument is
 * ignored.
 *
 * Run with WP-CLI (e.g. over SSH on SiteGround), from the WordPress root.
 * Arguments are order-independent: a keyword (apply/undo) sets the mode, a number
 * selects the site, anything else is treated as the post type.
 *
 *   # 1. DRY RUN — shows what would change, writes nothing (default).
 *   #    On multisite with no site number, it lists the sites and prompts:
 *   wp eval-file wp-content/plugins/cq-auto-featured-image-ai-translate/tools/reorder-post-dates.php
 *
 *   # 2. DRY RUN on a specific site (e.g. site ID 2):
 *   wp eval-file wp-content/plugins/cq-auto-featured-image-ai-translate/tools/reorder-post-dates.php 2
 *
 *   # 3. APPLY on site 2 (a rollback copy is saved first):
 *   wp eval-file wp-content/plugins/cq-auto-featured-image-ai-translate/tools/reorder-post-dates.php apply 2
 *
 *   # 4. UNDO on site 2 (revert the last apply from the rollback copy):
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
	echo "This script must be run with WP-CLI: wp eval-file <path-to-this-file> [apply|undo] [site_id] [post_type]\n";
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
	if ( in_array( $arg, array( 'apply', 'undo', 'dry' ), true ) ) {
		$mode = $arg;
	} elseif ( ctype_digit( $arg ) ) {
		$site_id = (int) $arg;
	} else {
		$post_type = sanitize_key( $arg );
	}
}

$apply = ( 'apply' === $mode );
$undo  = ( 'undo' === $mode );

$meta_backup     = '_cq_afi_reorder_backup_post_date';
$meta_backup_gmt = '_cq_afi_reorder_backup_post_date_gmt';

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
		WP_CLI::error( sprintf( 'Invalid or missing site ID (%d). Re-run and pass the numeric site ID, e.g. "... %s 2".', $site_id, $apply ? 'apply' : ( $undo ? 'undo' : 'dry' ) ) );
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

WP_CLI::log( sprintf( 'Mode: %s | Post type: %s | Posts found: %d', strtoupper( $undo ? 'undo' : ( $apply ? 'apply' : 'dry-run' ) ), $post_type, count( $ids ) ) );
WP_CLI::log( str_repeat( '-', 70 ) );

$changed = 0;

// UNDO: restore each post's saved date and drop the backup meta.
if ( $undo ) {
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

// Detect Polylang so translations of a post can be tied to one shared date.
// Prefer the 'post_translations' taxonomy (reads the current site's term tables
// directly, which is reliable after switch_to_blog); fall back to the PLL API.
$has_pll_tax = taxonomy_exists( 'post_translations' );
$has_pll_fn  = function_exists( 'pll_get_post_translations' );
$has_lang_tax = taxonomy_exists( 'language' );
if ( $has_pll_tax || $has_pll_fn ) {
	WP_CLI::log( 'Polylang detected: translation groups will share one date.' );
	WP_CLI::log( str_repeat( '-', 70 ) );
}

// DRY-RUN / APPLY: walk newest-ID-first. Each translation group is handled once,
// at the slot of its highest-ID member, and every member gets the same timestamp.
$base_date  = current_time( 'Y-m-d' );
$index      = 0;
$candidates = 0;
$processed  = array();

foreach ( $ids as $id ) {
	$id = (int) $id;
	if ( isset( $processed[ $id ] ) ) {
		continue; // Already handled as part of an earlier post's translation group.
	}
	$post = get_post( $id );
	if ( ! $post ) {
		$processed[ $id ] = true;
		continue;
	}

	// Resolve the translation group (all tied IDs), always including this post.
	$group = array( $id );
	if ( $has_pll_tax ) {
		$terms = get_the_terms( $id, 'post_translations' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$data = maybe_unserialize( $terms[0]->description );
			if ( is_array( $data ) ) {
				$group = array_map( 'intval', array_values( $data ) );
			}
		}
	} elseif ( $has_pll_fn ) {
		$tr = pll_get_post_translations( $id );
		if ( is_array( $tr ) && $tr ) {
			$group = array_map( 'intval', array_values( $tr ) );
		}
	}
	$group[] = $id;
	$group   = array_filter( array_unique( $group ) ); // de-dup, drop 0/invalid.

	// Shared target datetime for the whole group, based on the representative
	// (this post = the highest-ID member that anchored the slot).
	$time_part = substr( (string) $post->post_date, 11, 8 );
	if ( '' === trim( $time_part ) || '00:00:00' === $time_part ) {
		$time_part = '12:00:00';
	}
	$target_date   = gmdate( 'Y-m-d', strtotime( $base_date . ' -' . $index . ' days' ) );
	$new_post_date = $target_date . ' ' . $time_part;
	$new_post_gmt  = get_gmt_from_date( $new_post_date );
	++$index;

	$tied = count( $group ) > 1;
	if ( $tied ) {
		WP_CLI::log( sprintf( 'Tied translation group (%d posts)  ->  %s', count( $group ), $new_post_date ) );
	}

	foreach ( $group as $gid ) {
		$gid                = (int) $gid;
		$processed[ $gid ]  = true;
		$gpost              = ( $gid === $id ) ? $post : get_post( $gid );
		if ( ! $gpost ) {
			continue;
		}
		if ( $gpost->post_date === $new_post_date ) {
			continue; // Already correct.
		}

		// Best-effort language label for the dry-run output.
		$lang = '';
		if ( $has_lang_tax ) {
			$lt = get_the_terms( $gid, 'language' );
			if ( $lt && ! is_wp_error( $lt ) ) {
				$lang = '[' . $lt[0]->slug . '] ';
			}
		}

		++$candidates;
		WP_CLI::log( sprintf( '  #%d  %s%s  current=%s  ->  new=%s', $gid, $lang, get_the_title( $gid ), $gpost->post_date, $new_post_date ) );

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
	WP_CLI::success( sprintf( 'Reordered %d post date(s) across %d group(s). Re-run with "undo" to revert.', $changed, $index ) );
} else {
	WP_CLI::success( sprintf( 'DRY RUN: %d post(s) across %d group(s) would be reordered. Re-run with "apply" to write the changes.', $candidates, $index ) );
}

if ( $switched ) {
	restore_current_blog();
}
