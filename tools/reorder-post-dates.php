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
 * Run with WP-CLI (e.g. over SSH on SiteGround), from the WordPress root:
 *
 *   # 1. DRY RUN — shows what would change, writes nothing (default):
 *   wp eval-file wp-content/plugins/cq-auto-featured-image-ai-translate/tools/reorder-post-dates.php
 *
 *   # 2. APPLY — rewrite the dates (a rollback copy is saved first):
 *   wp eval-file wp-content/plugins/cq-auto-featured-image-ai-translate/tools/reorder-post-dates.php apply
 *
 *   # 3. UNDO — revert the last apply from the rollback copy:
 *   wp eval-file wp-content/plugins/cq-auto-featured-image-ai-translate/tools/reorder-post-dates.php undo
 *
 * Optional second argument restricts to a post type other than the default
 * 'post', e.g. `... apply page`.
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
	echo "This script must be run with WP-CLI: wp eval-file <path-to-this-file> [apply|undo] [post_type]\n";
	return;
}

// This is a WP-CLI `eval-file` script that runs in the global scope by design,
// so its working variables are top-level locals rather than function-scoped.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
$argv_in   = isset( $args ) && is_array( $args ) ? $args : array();
$mode      = isset( $argv_in[0] ) ? strtolower( (string) $argv_in[0] ) : 'dry';
$post_type = isset( $argv_in[1] ) ? sanitize_key( (string) $argv_in[1] ) : 'post';

$apply = ( 'apply' === $mode );
$undo  = ( 'undo' === $mode );

$meta_backup     = '_cq_afi_reorder_backup_post_date';
$meta_backup_gmt = '_cq_afi_reorder_backup_post_date_gmt';

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
	return;
}

// DRY-RUN / APPLY: walk newest-ID-first, assigning today, today-1, today-2, ...
// The base date is "today" in the site's local timezone.
$base_date  = current_time( 'Y-m-d' );
$index      = 0;
$candidates = 0;

foreach ( $ids as $id ) {
	$id   = (int) $id;
	$post = get_post( $id );
	if ( ! $post ) {
		continue;
	}

	// Preserve the original time-of-day; only the calendar date moves.
	$time_part = substr( (string) $post->post_date, 11, 8 );
	if ( '' === trim( $time_part ) || '00:00:00' === $time_part ) {
		$time_part = '12:00:00';
	}

	$target_date     = gmdate( 'Y-m-d', strtotime( $base_date . ' -' . $index . ' days' ) );
	$new_post_date   = $target_date . ' ' . $time_part;
	$new_post_gmt    = get_gmt_from_date( $new_post_date );

	++$index;

	if ( $post->post_date === $new_post_date ) {
		continue; // Already correct.
	}

	++$candidates;
	WP_CLI::log( sprintf( '#%d  %s  current=%s  ->  new=%s', $id, get_the_title( $id ), $post->post_date, $new_post_date ) );

	if ( $apply ) {
		// Save a one-time rollback copy of the current date before overwriting.
		if ( '' === (string) get_post_meta( $id, $meta_backup, true ) ) {
			update_post_meta( $id, $meta_backup, $post->post_date );
			update_post_meta( $id, $meta_backup_gmt, $post->post_date_gmt );
		}
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => $new_post_date,
				'post_date_gmt' => $new_post_gmt,
			),
			array( 'ID' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		clean_post_cache( $id );
		++$changed;
	}
}

WP_CLI::log( str_repeat( '-', 70 ) );

if ( $apply ) {
	WP_CLI::success( sprintf( 'Reordered %d post date(s). Re-run with "undo" to revert.', $changed ) );
} else {
	WP_CLI::success( sprintf( 'DRY RUN: %d post(s) would be reordered. Re-run with "apply" to write the changes.', $candidates ) );
}
