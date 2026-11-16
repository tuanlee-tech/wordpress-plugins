<?php
/**
 * Module G: FSE Domain Migration Engine (Integrated into FSE Performance Optimizer).
 *
 * Handles standard URLs, JSON-escaped URLs (https:\/\/...), URL-encoded URLs,
 * and PHP-serialized structures used by WordPress FSE, Gutenberg, and Greenshift.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSE_Opt_Migrator {

	/**
	 * Batch size for table scanning.
	 */
	const BATCH_SIZE = 500;

	/**
	 * Build ordered search => replace pairs covering normal, JSON-escaped, and URL-encoded forms.
	 *
	 * @param string $old_url               Source URL (e.g. https://example.com).
	 * @param string $new_url               Target URL (e.g. http://localhost:10013).
	 * @param bool   $include_both_schemes  Also match http:// if https:// was given (and vice versa).
	 * @return array<string, string>
	 */
	public function build_replacement_pairs( $old_url, $new_url, $include_both_schemes = true ) {
		$old_url = untrailingslashit( trim( $old_url ) );
		$new_url = untrailingslashit( trim( $new_url ) );

		if ( '' === $old_url || '' === $new_url || $old_url === $new_url ) {
			return array();
		}

		$old_variants = array( $old_url );

		if ( $include_both_schemes ) {
			if ( 0 === stripos( $old_url, 'https://' ) ) {
				$old_variants[] = 'http://' . substr( $old_url, 8 );
			} elseif ( 0 === stripos( $old_url, 'http://' ) ) {
				$old_variants[] = 'https://' . substr( $old_url, 7 );
			}
		}

		$pairs = array();
		foreach ( array_unique( $old_variants ) as $src ) {
			// 1. Normal URL
			$pairs[ $src ] = $new_url;

			// 2. JSON-escaped URL (e.g. https:\/\/example.com)
			$src_escaped           = str_replace( '/', '\/', $src );
			$new_escaped           = str_replace( '/', '\/', $new_url );
			$pairs[ $src_escaped ] = $new_escaped;

			// 3. Double-escaped JSON URL (e.g. https:\\/\\/example.com)
			$src_double_escaped           = str_replace( '/', '\\/', $src );
			$new_double_escaped           = str_replace( '/', '\\/', $new_url );
			$pairs[ $src_double_escaped ] = $new_double_escaped;

			// 4. URL-encoded (e.g. https%3A%2F%2Fexample.com)
			$src_encoded           = rawurlencode( $src );
			$new_encoded           = rawurlencode( $new_url );
			$pairs[ $src_encoded ] = $new_encoded;
		}

		// Sort longest keys first to prevent partial prefix replacements.
		uksort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return $pairs;
	}

	/**
	 * Detect foreign root domains serving WordPress paths (/wp-content/ or /wp-includes/).
	 *
	 * @return array<string, int> Domain URL => approximate count.
	 */
	public function detect_foreign_domains() {
		global $wpdb;

		$current_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$current_port = wp_parse_url( home_url(), PHP_URL_PORT );
		$current_auth = $current_host . ( $current_port ? ':' . $current_port : '' );

		$samples = array();

		// Check FSE posts & templates + published posts (excluding revisions).
		$post_contents = $wpdb->get_col(
			"SELECT post_content FROM {$wpdb->posts}
			 WHERE post_type != 'revision'
			   AND (post_content LIKE '%wp-content%' OR post_content LIKE '%wp-includes%')
			 LIMIT 500"
		);
		if ( is_array( $post_contents ) ) {
			$samples = array_merge( $samples, $post_contents );
		}

		// Check Greenshift & theme options.
		$option_values = $wpdb->get_col(
			"SELECT option_value FROM {$wpdb->options}
			 WHERE option_name NOT LIKE '\_transient\_%'
			   AND (option_value LIKE '%wp-content%' OR option_name IN ('siteurl', 'home', 'gspb_global_settings'))
			 LIMIT 200"
		);
		if ( is_array( $option_values ) ) {
			$samples = array_merge( $samples, $option_values );
		}

		$found   = array();
		$pattern = '#https?(?:://|:\\\\/\\\\/)([a-zA-Z0-9.-]+(?::\d+)?)(?=(?:\\\\)?/wp-(?:content|includes|json)(?:\\\\)?/)#i';

		$ignored_hosts = array(
			'wooblockpatterns.wpcomstaging.com',
			'woocommerce.com',
			'gutenbergtimes.com',
			's.w.org',
			'wordpress.org',
			'www.w3.org',
		);

		foreach ( $samples as $text ) {
			if ( ! is_string( $text ) || '' === $text ) {
				continue;
			}
			if ( preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $m ) {
					$full_match = str_replace( '\/', '/', $m[0] );
					$host_port  = strtolower( $m[1] );
					$host_only  = strtolower( strtok( $host_port, ':' ) );

					if ( $host_port === strtolower( $current_auth ) || in_array( $host_only, $ignored_hosts, true ) ) {
						continue;
					}
					if ( ! isset( $found[ $full_match ] ) ) {
						$found[ $full_match ] = 0;
					}
					++$found[ $full_match ];
				}
			}
		}

		arsort( $found );
		return array_slice( $found, 0, 10, true );
	}

	/**
	 * Run a dry-run scan or live migration across WordPress database tables.
	 *
	 * @param string $old_url Source URL.
	 * @param string $new_url Target URL.
	 * @param array  $options Migration options.
	 * @return array<string, mixed>
	 */
	public function run( $old_url, $new_url, $options = array() ) {
		$dry_run              = isset( $options['dry_run'] ) ? (bool) $options['dry_run'] : true;
		$include_both_schemes = isset( $options['include_both_schemes'] ) ? (bool) $options['include_both_schemes'] : true;
		$include_guid         = isset( $options['include_guid'] ) ? (bool) $options['include_guid'] : false;
		$cleanup_caches       = isset( $options['cleanup_caches'] ) ? (bool) $options['cleanup_caches'] : true;

		$pairs = $this->build_replacement_pairs( $old_url, $new_url, $include_both_schemes );
		if ( empty( $pairs ) ) {
			return array(
				'error' => 'URL cũ và URL mới không hợp lệ hoặc giống nhau.',
			);
		}

		$search_keys = array_keys( $pairs );
		$tables      = $this->get_prefixed_tables();

		$report = array(
			'dry_run'       => $dry_run,
			'pairs'         => $pairs,
			'tables'        => array(),
			'total_rows'    => 0,
			'total_updated' => 0,
			'caches'        => array(),
		);

		foreach ( $tables as $table ) {
			$table_stats = $this->process_table( $table, $pairs, $search_keys, $dry_run, $include_guid );
			$report['tables'][ $table ] = $table_stats;
			$report['total_rows']      += $table_stats['matched_rows'];
			$report['total_updated']   += $table_stats['updated_rows'];
		}

		if ( ! $dry_run && $cleanup_caches ) {
			$report['caches'] = $this->cleanup_caches();
		}

		return $report;
	}

	/**
	 * Get all tables sharing the current WordPress prefix.
	 *
	 * @return string[]
	 */
	private function get_prefixed_tables() {
		global $wpdb;

		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return is_array( $tables ) ? $tables : array();
	}

	/**
	 * Scan and optionally update a single database table in batches.
	 */
	private function process_table( $table, $pairs, $search_keys, $dry_run, $include_guid ) {
		global $wpdb;

		$stats = array(
			'matched_rows'    => 0,
			'updated_rows'    => 0,
			'normal_hits'     => 0,
			'escaped_hits'    => 0,
			'serialized_hits' => 0,
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns_info = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A );
		if ( empty( $columns_info ) ) {
			return $stats;
		}

		$primary_keys = array();
		$text_columns = array();

		foreach ( $columns_info as $col ) {
			$field = $col['Field'];
			$type  = strtolower( $col['Type'] );

			if ( 'PRI' === $col['Key'] ) {
				$primary_keys[] = $field;
			}

			if ( ! $include_guid && $wpdb->posts === $table && 'guid' === $field ) {
				continue;
			}

			if ( preg_match( '/^(varchar|text|mediumtext|longtext|tinytext|char)/', $type ) ) {
				$text_columns[] = $field;
			}
		}

		if ( empty( $primary_keys ) || empty( $text_columns ) ) {
			return $stats;
		}

		$where_parts = array();
		foreach ( $text_columns as $col ) {
			foreach ( $search_keys as $key ) {
				$where_parts[] = $wpdb->prepare( "`{$col}` LIKE %s", '%' . $wpdb->esc_like( $key ) . '%' );
			}
		}

		$where_sql   = implode( ' OR ', $where_parts );
		$select_cols = array_unique( array_merge( $primary_keys, $text_columns ) );
		$select_sql  = '`' . implode( '`, `', $select_cols ) . '`';
		$order_sql   = '`' . implode( '`, `', $primary_keys ) . '`';

		$offset = 0;
		while ( true ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$select_sql} FROM `{$table}` WHERE {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d",
					self::BATCH_SIZE,
					$offset
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$update_data  = array();
				$where_clause = array();

				foreach ( $primary_keys as $pk ) {
					$where_clause[ $pk ] = $row[ $pk ];
				}

				foreach ( $text_columns as $col ) {
					$original = $row[ $col ];
					if ( ! is_string( $original ) || '' === $original ) {
						continue;
					}

					$col_stats = array(
						'normal'     => 0,
						'escaped'    => 0,
						'serialized' => 0,
					);

					$replaced = $this->replace_value( $original, $pairs, $col_stats );

					if ( $replaced !== $original ) {
						$update_data[ $col ]       = $replaced;
						$stats['normal_hits']     += $col_stats['normal'];
						$stats['escaped_hits']    += $col_stats['escaped'];
						$stats['serialized_hits'] += $col_stats['serialized'];
					}
				}

				if ( ! empty( $update_data ) ) {
					++$stats['matched_rows'];
					if ( ! $dry_run ) {
						$updated = $wpdb->update( $table, $update_data, $where_clause );
						if ( false !== $updated ) {
							++$stats['updated_rows'];
						}
					}
				}
			}

			if ( $dry_run ) {
				$offset += self::BATCH_SIZE;
			}
			if ( count( $rows ) < self::BATCH_SIZE ) {
				break;
			}
		}

		return $stats;
	}

	/**
	 * Replace URLs in a string, handling PHP serialization and JSON escaping safely.
	 */
	private function replace_value( $value, $pairs, &$col_stats ) {
		if ( is_serialized( $value ) ) {
			if ( preg_match( '/(^|;|{|})O:\d+:"/', $value ) ) {
				return $this->replace_serialized_strings_safely( $value, $pairs, $col_stats );
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			$unserialized = @unserialize( $value, array( 'allowed_classes' => false ) );
			if ( false !== $unserialized || 'b:0;' === $value ) {
				$changed   = false;
				$processed = $this->recursive_replace( $unserialized, $pairs, $col_stats, $changed );
				if ( $changed ) {
					++$col_stats['serialized'];
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					return serialize( $processed );
				}
				return $value;
			}
		}

		return $this->replace_string( $value, $pairs, $col_stats );
	}

	/**
	 * Recursively walk arrays and replace strings.
	 */
	private function recursive_replace( $data, $pairs, &$col_stats, &$changed ) {
		if ( is_string( $data ) ) {
			$new_str = $this->replace_string( $data, $pairs, $col_stats );
			if ( $new_str !== $data ) {
				$changed = true;
			}
			return $new_str;
		}

		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $val ) {
				$new_key = is_string( $key ) ? $this->replace_string( $key, $pairs, $col_stats ) : $key;
				if ( $new_key !== $key ) {
					$changed = true;
				}
				$out[ $new_key ] = $this->recursive_replace( $val, $pairs, $col_stats, $changed );
			}
			return $out;
		}

		return $data;
	}

	/**
	 * Fallback for serialized payloads containing PHP objects.
	 */
	private function replace_serialized_strings_safely( $serialized, $pairs, &$col_stats ) {
		$result = preg_replace_callback(
			'/s:(\d+):"(.*?)";/s',
			function ( $matches ) use ( $pairs, &$col_stats ) {
				$expected_len = (int) $matches[1];
				$inner        = $matches[2];
				if ( strlen( $inner ) !== $expected_len ) {
					return $matches[0];
				}
				$replaced = $this->replace_string( $inner, $pairs, $col_stats );
				if ( $replaced !== $inner ) {
					++$col_stats['serialized'];
					return 's:' . strlen( $replaced ) . ':"' . $replaced . '";';
				}
				return $matches[0];
			},
			$serialized
		);

		return is_string( $result ) ? $result : $serialized;
	}

	/**
	 * Perform string replacement and count normal vs JSON-escaped hits.
	 */
	private function replace_string( $text, $pairs, &$col_stats ) {
		foreach ( $pairs as $search => $replace ) {
			if ( false !== strpos( $text, $search ) ) {
				$count = substr_count( $text, $search );
				if ( false !== strpos( $search, '\/' ) ) {
					$col_stats['escaped'] += $count;
				} else {
					$col_stats['normal'] += $count;
				}
				$text = str_replace( $search, $replace, $text );
			}
		}
		return $text;
	}

	/**
	 * Clear WordPress transients, object cache, rewrite rules, and FSE Page Cache.
	 */
	public function cleanup_caches() {
		global $wpdb;

		$actions = array();

		$deleted = $wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '\_transient\_%'
			    OR option_name LIKE '\_site\_transient\_%'"
		);
		if ( false !== $deleted ) {
			$actions[] = sprintf( 'Đã xóa %d transient trong wp_options.', (int) $deleted );
		}

		if ( isset( $GLOBALS['fse_performance_optimizer']->cache ) ) {
			$cnt       = $GLOBALS['fse_performance_optimizer']->cache->clear_all_cache();
			$actions[] = sprintf( 'Đã xóa Page Cache (%d file).', (int) $cnt );
		}

		wp_cache_flush();
		flush_rewrite_rules();
		$actions[] = 'Đã flush WP Object Cache & Rewrite Rules.';

		return $actions;
	}
}
