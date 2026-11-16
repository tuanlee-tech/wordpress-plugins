<?php
/**
 * Module F: Database Optimizer, FSE Revision Limiter & Autoload Audit.
 *
 * Cleans FSE/Gutenberg/Greenshift revisions, auto-drafts, trashed posts, expired transients,
 * Action Scheduler logs, orphaned meta, and runs OPTIMIZE TABLE while tracking Before/After DB size.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSE_Opt_Database {

	/**
	 * Plugin settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array $settings Plugin settings.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register runtime hooks (revision cap).
	 */
	public function register_hooks() {
		add_filter( 'wp_revisions_to_keep', array( $this, 'limit_revisions' ), 999, 2 );
	}

	/**
	 * Cap post/template/global-styles revisions to prevent FSE bloat.
	 *
	 * @param int     $num  Current revision limit.
	 * @param WP_Post $post Post object.
	 * @return int
	 */
	public function limit_revisions( $num, $post ) {
		if ( isset( $this->settings['max_revisions'] ) && is_numeric( $this->settings['max_revisions'] ) ) {
			return max( 0, min( 50, (int) $this->settings['max_revisions'] ) );
		}
		return 5;
	}

	/**
	 * Inspect current database size, revision bloat, transients, Action Scheduler logs, and autoloaded options.
	 *
	 * @return array<string, mixed>
	 */
	public function get_database_report() {
		global $wpdb;

		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$status = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A );

		$total_bytes    = 0;
		$overhead_bytes = 0;
		$posts_bytes    = 0;

		if ( is_array( $status ) ) {
			foreach ( $status as $row ) {
				$t_size          = (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 );
				$total_bytes    += $t_size;
				$overhead_bytes += (int) ( $row['Data_free'] ?? 0 );
				if ( $wpdb->posts === ( $row['Name'] ?? '' ) ) {
					$posts_bytes = $t_size;
				}
			}
		}

		$rev_row = $wpdb->get_row(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(post_content)), 0) AS bytes
			 FROM {$wpdb->posts}
			 WHERE post_type = 'revision'",
			ARRAY_A
		);

		$autodraft_cnt = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"
		);

		$trash_cnt = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"
		);

		$orphan_postmeta_cnt = (int) $wpdb->get_var(
			"SELECT COUNT(pm.meta_id) FROM {$wpdb->postmeta} pm
			 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.ID IS NULL"
		);

		$transients_cnt = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options}
			 WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%'"
		);

		$as_actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$as_logs_table    = $wpdb->prefix . 'actionscheduler_logs';
		$as_done_cnt      = 0;
		$as_logs_cnt      = 0;

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_actions_table ) ) === $as_actions_table ) {
			$as_done_cnt = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM `{$as_actions_table}` WHERE status IN ('complete', 'canceled', 'failed')"
			);
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_logs_table ) ) === $as_logs_table ) {
			$as_logs_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$as_logs_table}`" );
		}

		// Autoload audit (compatible with WP < 6.6 'yes' and WP 6.6+ 'on'/'auto-on').
		$autoload_bytes = (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options}
			 WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')"
		);

		$top_autoload = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) AS bytes, autoload
			 FROM {$wpdb->options}
			 WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')
			 ORDER BY bytes DESC
			 LIMIT 10",
			ARRAY_A
		);

		return array(
			'total_bytes'         => $total_bytes,
			'posts_table_bytes'   => $posts_bytes,
			'overhead_bytes'      => $overhead_bytes,
			'revisions_count'     => (int) ( $rev_row['cnt'] ?? 0 ),
			'revisions_bytes'     => (int) ( $rev_row['bytes'] ?? 0 ),
			'autodraft_count'     => $autodraft_cnt,
			'trash_count'         => $trash_cnt,
			'orphan_postmeta'     => $orphan_postmeta_cnt,
			'transients_count'    => $transients_cnt,
			'as_done_count'       => $as_done_cnt,
			'as_logs_count'       => $as_logs_cnt,
			'autoload_bytes'      => $autoload_bytes,
			'top_autoload'        => is_array( $top_autoload ) ? $top_autoload : array(),
			'has_redis'           => class_exists( 'Redis' ),
			'has_apcu'            => function_exists( 'apcu_fetch' ),
			'has_object_cache'    => wp_using_ext_object_cache(),
		);
	}

	/**
	 * Execute safe database cleanup and table optimization, recording Before/After telemetry.
	 *
	 * @return array<string, mixed>
	 */
	public function run_cleanup() {
		global $wpdb;

		$before = $this->get_database_report();

		// Preserve original initial baseline if already captured.
		$saved_stats = get_option( 'fse_opt_db_stats', array() );
		$initial_before_bytes = ! empty( $saved_stats['before_bytes'] )
			? (int) $saved_stats['before_bytes']
			: (int) $before['total_bytes'];
		$initial_rev_bytes    = isset( $saved_stats['before_revisions_bytes'] )
			? (int) $saved_stats['before_revisions_bytes']
			: (int) $before['revisions_bytes'];
		$initial_rev_count    = isset( $saved_stats['before_revisions_count'] )
			? (int) $saved_stats['before_revisions_count']
			: (int) $before['revisions_count'];

		// 1. Delete all revisions.
		$deleted_revs = (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'" );

		// 2. Delete auto-drafts and trashed posts.
		$deleted_drafts = (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status IN ('auto-draft', 'trash')" );

		// 3. Delete orphaned postmeta & term relationships for deleted revisions/posts.
		$deleted_meta = (int) $wpdb->query(
			"DELETE pm FROM {$wpdb->postmeta} pm
			 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.ID IS NULL"
		);

		// 4. Delete transients.
		$deleted_transients = (int) $wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%'"
		);

		// 5. Clean completed/canceled Action Scheduler actions & orphan logs.
		$as_actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$as_logs_table    = $wpdb->prefix . 'actionscheduler_logs';
		$deleted_as       = 0;

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_actions_table ) ) === $as_actions_table ) {
			$deleted_as += (int) $wpdb->query(
				"DELETE FROM `{$as_actions_table}` WHERE status IN ('complete', 'canceled', 'failed')"
			);
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_logs_table ) ) === $as_logs_table ) {
			$deleted_as += (int) $wpdb->query( "TRUNCATE TABLE `{$as_logs_table}`" );
		}

		// 6. Run OPTIMIZE TABLE on all prefixed tables to reclaim disk space.
		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		if ( is_array( $tables ) ) {
			foreach ( $tables as $t ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "OPTIMIZE TABLE `{$t}`" );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ANALYZE TABLE `{$t}`" );
			}
		}

		$after = $this->get_database_report();

		$result = array(
			'before_bytes'           => $initial_before_bytes,
			'after_bytes'            => (int) $after['total_bytes'],
			'before_revisions_bytes' => $initial_rev_bytes,
			'before_revisions_count' => $initial_rev_count,
			'after_revisions_count'  => (int) $after['revisions_count'],
			'deleted_revisions'      => $deleted_revs,
			'deleted_drafts'         => $deleted_drafts,
			'deleted_meta'           => $deleted_meta,
			'deleted_transients'     => $deleted_transients,
			'deleted_as'             => $deleted_as,
			'autoload_bytes'         => (int) $after['autoload_bytes'],
			'timestamp'              => time(),
		);

		update_option( 'fse_opt_db_stats', $result, false );
		return $result;
	}
}
