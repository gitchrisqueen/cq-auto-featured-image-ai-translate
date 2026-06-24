<?php
/**
 * Restore original post publish dates that an earlier version of this plugin
 * scrambled, using the `_cq_afi_original_post_date` meta captured before the
 * post was first modified.
 *
 * Run with WP-CLI (e.g. over SSH on SiteGround), from the WordPress root:
 *
 *   # 1. DRY RUN — shows what would change, writes nothing (default):
 *   wp eval-file wp-content/plugins/cq-auto-featured-image-ai-translate/tools/restore-post-dates.php
 *
 *   # 2. APPLY — restore the dates (a rollback copy is saved first):
 *   wp eval-file wp-content/plugins/cq-auto-featured-image-ai-translate/tools/restore-post-dates.php apply
 *
 *   # 3. UNDO — revert the last apply from the rollback copy:
 *   wp eval-file wp-content/plugins/cq-auto-featured-image-ai-translate/tools/restore-post-dates.php undo
 *
 * Optional: restrict to a post type other than the default 'post' by passing it
 * as a second argument, e.g. `... apply page`.
 *
 * IMPORTANT: take a database backup before running with `apply`. This only ever
 * changes posts where the captured original date DIFFERS from the current date,
 * so it will not "restore" a value equal to the corruption — but a backup is the
 * authoritative safety net and, if taken before the incident, the most accurate fix.
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

$meta_orig      = '_cq_afi_original_post_date';
$meta_orig_gmt  = '_cq_afi_original_post_date_gmt';
$meta_backup     = '_cq_afi_predaterestore_post_date';
$meta_backup_gmt = '_cq_afi_predaterestore_post_date_gmt';

global $wpdb;

$ids = get_posts(
	array(
		'post_type'        => $post_type,
		'post_status'      => 'any',
		'posts_per_page'   => -1,
		'fields'           => 'ids',
		'suppress_filters' => true,
	)
);

WP_CLI::log( sprintf( 'Mode: %s | Post type: %s | Posts found: %d', strtoupper( $undo ? 'undo' : ( $apply ? 'apply' : 'dry-run' ) ), $post_type, count( $ids ) ) );
WP_CLI::log( str_repeat( '-', 60 ) );

$scanned     = 0;
$with_source = 0;
$invalid     = 0;
$candidates  = 0;
$changed     = 0;

foreach ( $ids as $id ) {
	$id   = (int) $id;
	$post = get_post( $id );
	if ( ! $post ) {
		continue;
	}
	++$scanned;

	if ( $undo ) {
		$bak     = get_post_meta( $id, $meta_backup, true );
		$bak_gmt = get_post_meta( $id, $meta_backup_gmt, true );
		if ( ! $bak ) {
			continue;
		}
		++$candidates;
		WP_CLI::log( sprintf( '#%d  %s  restore-from-backup  %s  ->  %s', $id, get_the_title( $id ), $post->post_date, $bak ) );
		$wpdb->update(
			$wpdb->posts,
			array( 'post_date' => $bak, 'post_date_gmt' => $bak_gmt ?: get_gmt_from_date( $bak ) ),
			array( 'ID' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		delete_post_meta( $id, $meta_backup );
		delete_post_meta( $id, $meta_backup_gmt );
		clean_post_cache( $id );
		++$changed;
		continue;
	}

	$orig     = (string) get_post_meta( $id, $meta_orig, true );
	$orig_gmt = (string) get_post_meta( $id, $meta_orig_gmt, true );
	if ( '' === $orig ) {
		continue; // No captured original — not recoverable from meta for this post.
	}
	++$with_source;

	$ts = strtotime( $orig );
	if ( ! $ts || $ts < strtotime( '2000-01-01' ) || $ts > ( time() + DAY_IN_SECONDS ) ) {
		++$invalid;
		continue; // Implausible captured value; skip.
	}

	if ( $post->post_date === $orig ) {
		continue; // Already correct.
	}

	++$candidates;
	WP_CLI::log( sprintf( '#%d  %s  current=%s  ->  original=%s', $id, get_the_title( $id ), $post->post_date, $orig ) );

	if ( $apply ) {
		if ( '' === (string) get_post_meta( $id, $meta_backup, true ) ) {
			update_post_meta( $id, $meta_backup, $post->post_date );
			update_post_meta( $id, $meta_backup_gmt, $post->post_date_gmt );
		}
		if ( '' === $orig_gmt || 0 === strpos( $orig_gmt, '0000' ) ) {
			$orig_gmt = get_gmt_from_date( $orig );
		}
		$wpdb->update(
			$wpdb->posts,
			array( 'post_date' => $orig, 'post_date_gmt' => $orig_gmt ),
			array( 'ID' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		clean_post_cache( $id );
		++$changed;
	}
}

WP_CLI::log( str_repeat( '-', 60 ) );
WP_CLI::log( sprintf( 'Posts scanned: %d', $scanned ) );

if ( $undo ) {
	WP_CLI::success( sprintf( 'Reverted %d post(s) from the rollback copy.', $changed ) );
	return;
}

WP_CLI::log( sprintf( 'With a captured original date: %d', $with_source ) );
WP_CLI::log( sprintf( 'Without one (NOT recoverable from meta): %d', $scanned - $with_source ) );
WP_CLI::log( sprintf( 'Captured date implausible (skipped): %d', $invalid ) );

if ( $apply ) {
	WP_CLI::success( sprintf( 'Restored %d post date(s). Re-run with "undo" to revert.', $changed ) );
} else {
	WP_CLI::success( sprintf( 'DRY RUN: %d post(s) would be restored. Re-run with "apply" to write the changes.', $candidates ) );
}
