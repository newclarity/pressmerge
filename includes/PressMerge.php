<?php

class PressMerge {
	const LIVE_PREFIX = 'wp';
	const STAGE_PREFIX = '_staging';
	const LIVE_UPLOADS = 'wp-content/uploads';
	const STAGE_UPLOADS = '../../schiffpodcast/www/wp-content/uploads';
	const MERGE_FILENAME = 'pressmerge.txt';
//	const LIVE_PREFIX =  '_staging';
//	const STAGE_PREFIX = 'wp';

	/**
	 * @var self
	 */
	private static $_instance;

	/**
	 * @var bool
	 */
	private static $_cache_hashes = true;

	/**
	 *
	 */
	static function on_load() {
		self::$_instance = new self;
	}

	/**
	 * PressMerge constructor.
	 * Private to enforce singleton
	 */
	private function __construct() {
		add_action( 'wp_loaded', array( $this, '_wp_loaded' ) );
	}

	/**
	 * @return self
	 */
	static function instance() {
		return self::$_instance;
	}

	/**
	 *
	 */
	function _wp_loaded() {

		$url_path = trim( $_SERVER[ 'REQUEST_URI' ], '/' ) . '/';
		list( $marker, $task ) = explode( '/', $url_path );

		if ( 'pressmerge' === $marker ) {

			header( "Content-type:text/plain" );
			switch ( $task ) {
				case 'build':
					self::$_instance->build_changes();
					break;
				case 'merge':
					self::$_instance->merge();
					break;
				default:
					$task = sanitize_key( $task );
					echo "Invalid PressMerge task: [{$task}]";
			}
			exit;

		}

	}

	/**
	 * @param array $changes BY REFERENCES
	 */
	function fixup_changes( &$changes ) {
		$p = new PressMerge_Posts();
		$t = new PressMerge_Terms();
		$m = new PressMerge_Media();

		$changes[ 'terms' ] = $t->fixup_added_terms( $changes[ 'terms' ][ 'added' ] );
		$changes[ 'posts' ] = $p->fixup_added_posts( $changes[ 'posts' ][ 'added' ] );
		//$changes[ 'media' ] = $m->fixup_added_media( $changes[ 'media' ][ 'added' ] );

	}

	/**
	 *
	 */
	function build_changes() {

		$merge_filename = $this->merge_filename();

		$terms__merge = new PressMerge_Terms();
		$posts_merge  = new PressMerge_Posts();
		$media_merge  = new PressMerge_Media();
		$changes      = array(
			//'media' => $media_merge->build_media_changes(),
			'terms' => $terms__merge->build_term_changes(),
			'posts' => $posts_merge->build_post_changes(),
		);

		$this->fixup_changes( $changes );

		file_put_contents( $merge_filename, serialize( $changes ) );

		echo "\nBuild complete\n";

		$pad_width = 8;

		echo "\nRESULTS:\n=======\n";

		foreach( $changes as $type => $type_changes ) {
			$uctype = ucwords( $type );
			echo "\n   {$uctype}:\n";

			foreach( $type_changes as $type_key => $type_values ) {
				$label = str_repeat( ' ', $pad_width ) . $type_key;
				echo "\n{$label}:\n\n";
				foreach ( $type_values as $change_key => $value ) {
					list( $id ) = explode( ':', $change_key );
					if ( is_scalar( $value ) ) {
						$value = $this->lpad( $value, $pad_width );
						echo "{$value} : {$change_key}\n";
					} else {
						$value = (object) $value;
						if ( isset( $value->name ) ) {
							$id = str_repeat( ' ', $pad_width + 3 ) . $this->lpad( $id , 5 );
							echo "{$id} : {$value->name}\n";
						} else {
							print_r( $value );
						}

					}
				}
			}

		}

	}

	/**
	 * @param string $string
	 * @param int $width
	 *
	 * @return string
	 */
	function lpad( $string, $width ) {
		return str_pad( $string, $width, ' ', STR_PAD_LEFT );
	}

	/**
	 * @param string $string
	 * @param int $width
	 *
	 * @return string
	 */
	function rpad( $string, $width ) {
		return str_pad( $string, $width, ' ', STR_PAD_RIGHT );
	}

	/**
	 * @return string
	 */
	function merge_filename() {
		$upload_dir = wp_upload_dir();
		return "{$upload_dir[ 'basedir' ]}/" . self::MERGE_FILENAME;
	}

	/**
	 * Strips non-readable characters
	 *
	 * @param string|object|array $value
	 *
	 * @return string
	 */
	function strip_nonreadable_chars( $value ) {
		if ( is_scalar( $value ) ) {
			$result = trim( preg_replace( '#([^\x20-\x7E])#', '', $value ) );
		} else if ( is_object( $value ) ) {
			foreach( get_object_vars( $value ) as $property_name => $property_value ) {
				$value->$property_name = $this->strip_nonreadable_chars( $property_value );
			}
			$result = $value;
		} else if ( is_array( $value ) ) {
			foreach( $value as $element_name => $element_value ) {
				$value[ $element_name ] = $this->strip_nonreadable_chars( $element_value );
			}
			$result = $value;
		} else {
			trigger_error(
				sprintf(
					__( 'Unexpected data type: %s','pressmerge' ),
					gettype( $value )
				)
			);
			$result = null;
		}

		return $result;
	}

	/**
	 * @param string $meta_type
	 * @param string $prefix
	 * @param int $post_id
	 * @return array
	 */
	function get_meta( $meta_type, $prefix, $post_id ) {
		do {
			$meta = array();

			if ( ! $this->meta_table_exists( $prefix, $meta_type ) ) {
				break;
			}
			$table_name = $this->meta_table_name( $prefix, $meta_type );

			/**
			 * @var wpdb
			 */
			global $wpdb;
			$sql = "SELECT * FROM {$table_name} WHERE {$meta_type}_id=%d";
			$results = $wpdb->get_results( $wpdb->prepare( $sql, $post_id ) );
			foreach( $results as $result ) {
				$meta[ $result->meta_key ] = $result->meta_value;
			}

		} while ( false );
		return $meta;
	}

	/**
	 * @param string $prefix
	 * @param string $meta_type
	 *
	 * @return string
	 */
	function meta_table_name( $prefix, $meta_type ) {
		return sanitize_key( "{$prefix}_{$meta_type}meta" );
	}

	/**
	 * @param $prefix
	 * @param $meta_type
	 *
	 * @return bool
	 */
	function meta_table_exists( $prefix, $meta_type ) {
		static $exists;

		$table_name = $this->meta_table_name( $prefix, $meta_type );

		if ( ! isset( $exists[ $table_name ] ) ) {

			global $wpdb;
			$tables = $wpdb->get_results( "SHOW TABLES" );
			$exists[ $table_name ] = false;
			foreach( $tables as $table ) {
				$table = (array) $table;
				$name = reset( $table );
				if ( $table_name === $name ) {
					$exists[ $table_name ] = true;
					break;
				}
			}

		}
		return  $exists[ $table_name ];
	}

	/**
	 * @param string $meta_type
	 * @param int $new_id
	 * @param int $existing_id
	 * @param $prefix_new
	 * @param $prefix_existing
	 *
	 * @return array
	 */
	function compare_meta( $meta_type, $new_id, $existing_id, $prefix_new, $prefix_existing ) {

		$new = array();

		$new_meta = self::get_meta( $meta_type, $prefix_new, $new_id );
		if ( ! $existing_id ) {
			foreach( $new_meta as $new_key => $new_value ) {
				$new[ "add_meta:{$new_key}" ] = $new_value;
			}

		} else {
			$existing_meta = self::get_meta( $meta_type, $prefix_existing, $existing_id );
			foreach( $new_meta as $new_key => $new_value ) {
				$new_value = PressMerge()->strip_nonreadable_chars( $new_value );
				if ( ! isset( $existing_meta[ $new_key ] ) ) {
					/*
					 * If the key did not already exist then add it
					 */
					if ( is_null( $new_value ) || '' === $new_value ) {
						/*
						 * Unless the new value is blank then ignore it and go to the next.
						 */
						continue;
					}
					$new[ "add_meta:{$new_key}" ] = $new_value;
					/*
					 * We are done with this key, move to the next
					 */
					continue;
				}
				/*
				 * Clean the key of crappy characters
				 */
				$existing_value = PressMerge()->strip_nonreadable_chars( $existing_meta[ $new_key ] );

				if ( $existing_value !== $new_value ) {
					/*
					 * If new and existing values are different value then it is updated, so add it.
					 */
					$new[ "add_meta:{$new_key}" ] = $new_value;
				}

				/*
				 * Get rid of $existing_meta[ $new_key ] since we've already processed it.
				 */
				unset( $existing_meta[ $new_key ] );

			}

			/*
			 * Now loop through the existing meta that we did not already process above
			 */
			foreach( array_keys( (array) $existing_meta ) as $existing_key ) {

				/*
				 * If there is an existing key but the key is not in the new meta then remove.
				 */
				$new[ "delete_meta:{$existing_key}" ] = 1;
			}

		}
		return $new;
	}

	/**
	 * @param array $meta
	 * @param array $remove_keys
	 *
	 * @return array
	 */
	function remove_meta_keys( $meta, $remove_keys ) {

		$meta_keys = implode( '|', array_keys( $meta ) );
		foreach( $remove_keys as $key ) {
			$prefix = false === strpos( $key, ':' ) ? '((add|delete)_meta):' : '';
			if ( ! preg_match( "#{$prefix}" . preg_quote( $key ) . '#', $meta_keys, $match ) ) {
				continue;
			}
			unset( $meta[ $match[ 0 ] ] );
		}

		return $meta;
	}

	/**
	 * Get/set use of cache settings for hashes
	 *
	 * @param bool|null $do_cache
	 *
	 * @return bool
	 */
	static function cache_hashes( $do_cache = null ) {

		$cache_hashes = self::$_cache_hashes;

		if ( ! is_null( $do_cache ) ) {
			self::$_cache_hashes = $do_cache;
		}

		return $cache_hashes;

	}

}

/**
 * @return PressMerge
 */
function PressMerge() {
	return PressMerge::instance();
}