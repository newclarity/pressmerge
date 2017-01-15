<?php

class PressMerge_Posts {

	const LAST_SHARED_ID = 17156;
	const ID_INCREMENT = 10000;

	function __construct() {

	}


	/**
	 *
	 */
	function build_post_changes() {

		$production_hash = $this->get_posts_hash( PressMerge::LIVE_PREFIX );
		$staging_hash = $this->get_posts_hash( PressMerge::STAGE_PREFIX );

		$stats = array(
			'statistics' => array(
				'in_existing_db' => count( $production_hash ),
				'in_new_db'      => count( $staging_hash ),
			)
		);

		/*
		 * Create a index for staging posts
		 */
		foreach( $staging_hash as $index => $title ) {
			$staging_index[ $this->get_post_id_from_index( $index ) ] = $index;
		}

		/*
		 * Remove dups
		 */
		foreach( $production_hash as $index => $title ) {
			if ( isset( $staging_hash[ $index ] ) ) {
				unset( $production_hash[ $index ] );
				unset( $staging_hash[ $index ] );
			}
		}

		$stats['statistics']['non_matching_existing'] = count( $production_hash );
		$stats['statistics']['non_matching_new']      = count( $staging_hash );


		$changes= $this->find_changed_posts( $staging_hash, PressMerge::STAGE_PREFIX, PressMerge::LIVE_PREFIX );

		$changes[ 'omitted' ] = $this->find_omitted_posts( $staging_hash, PressMerge::STAGE_PREFIX, PressMerge::LIVE_PREFIX );

		$changes = array_merge( $stats, $changes );

		return $changes;

	}

	/**
	 * @param array $hash
	 * @param string $prefix_new
	 * @param string $prefix_existing
	 *
	 * @return array|string
	 */
	function find_omitted_posts( $hash, $prefix_new, $prefix_existing ) {
		$missing = array();
		foreach( $hash as $index => $title ) {
			$post_id = $this->get_post_id_from_index( $index );
			$post = $this->get_post( $prefix_existing, $post_id );
			if ( ! is_null( $post ) ) {
				continue;
			}
			$post = $this->get_post( $prefix_new, $post_id );
			if ( 'revision' === $post->post_type ) {
				continue;
			}
			$missing[ $post_id ] = $post->post_title;
		}
		return $missing;
	}

	/**
	 * @param array $hash
	 * @param string $prefix_new
	 * @param string $prefix_existing
	 *
	 * @return array|string
	 */
	function find_changed_posts( $hash, $prefix_new, $prefix_existing ) {
		$different = array();
		foreach( $hash as $index => $md5 ) {

			$updated = $this->find_updated_post( $index, $prefix_new, $prefix_existing );
			$post_id = $this->get_post_id_from_index( $index );
			$post = $this->get_post( $prefix_new, $post_id );

			if ( ! is_null( $updated ) ) {
				$index = $this->get_post_index( $post );

//				/**
//				 * Assume if we have only 3 fields or less that are different
//				 * then the user updated it. Otherwise the user duplicated an
//				 * post ID because of the fork.
//				 */
//				$cohort = 7 >= count( $updated ) ? 'updated' : 'dup_id';
				$cohort = self::LAST_SHARED_ID >= $post_id
					? 'updated'
					: 'added';

				$different[ $cohort ][ $index ] = $updated;
			}

		}
		return $different;
	}

	/**
	 * @param object $post
	 *
	 * @return string In the form "{$post_id}:{$post_title}:
	 */
	function get_post_index( $post ) {
		$post = (object) $post;
		return "{$post->ID}:{$post->post_title}";
	}

	/**
	 * @param string $index In the form "{$post_id}:{$post_title}:
	 *
	 * @return array Two elements: 0=$post_id and 1=$post_title
	 */
	function parse_post_index( $index ) {
		return explode( ':', "{$index}:" );
	}

	/**
	 * @param string $index In the form "{$post_id}:{$post_title}:
	 *
	 * @return int
	 */
	function get_post_id_from_index( $index ) {
		list( $post_id ) = $this->parse_post_index( $index );
		return $post_id;
	}

	/**
	 * @param string $index
	 * @param string $prefix_new
	 * @param string $prefix_existing
	 *
	 * @return array|string
	 */
	function find_updated_post( $index, $prefix_new, $prefix_existing ) {
		$post_id = $this->get_post_id_from_index( $index );
		$existing_post = $this->get_post( $prefix_existing, $post_id );

		if ( is_null( $existing_post ) ) {
			$existing_post = (object)array();
		}
		$new_post = $this->get_post( $prefix_new, $post_id );
		$compare = $this->compare_posts( $new_post, $existing_post, $prefix_new, $prefix_existing );
		$updated   = count( $compare )
			? $compare
			: null;

		return $updated;

	}

	/**
	 * @param object $new_post
	 * @param object $existing_post
	 * @param string $prefix_new
	 * @param string $prefix_existing
	 *
	 * @return array|string
	 */
	function compare_posts( $new_post, $existing_post, $prefix_new, $prefix_existing ) {

		$new = array();
		foreach ( get_object_vars( $new_post ) as $name => $new_value ) {
			if ( ! isset( $existing_post->$name ) )  {
				$new[ $name ] = $new_value;
				continue;
			}
			if ( $new_value === $existing_post->$name ) {
				continue;
			}
			if ( preg_match( '#^(comment_count|post_modified(_gmt)?)$#', $name ) ) {
				continue;
			}

			/**
			 * Strip all non-printable characters
			 */
			$new_value = PressMerge()->strip_nonreadable_chars( $new_value );
			$existing_value = PressMerge()->strip_nonreadable_chars(  $existing_post->$name );

			if ( $new_value === $existing_value ) {
				continue;
			}

			$new[ $name ] = $new_value;

		}


		$meta = PressMerge()->compare_meta(
			'post',
			$new_post->ID,
			isset( $existing_post->ID ) ? $existing_post->ID : 0,
			$prefix_new,
			$prefix_existing
		);

		$meta = PressMerge()->remove_meta_keys( $meta, array(
			'delete_meta:_post_restored_from',
			'avada_post_views_count',
			'_yoast_wpseo_linkdex',
			'_yoast_wpseo_content_score',
			'_edit_lock',
		));

		$new = array_merge( $new, $meta );

		return $new;

	}

	/**
	 * @param string $prefix
	 *
	 * @return string[]
	 */
	function get_posts_hash( $prefix ) {
		$key = "posts_hash:{$prefix}";
		$posts_hash = PressMerge()->cache_hashes()
			? get_transient( "posts_hash:{$prefix}" )
			: null;
		if ( ! $posts_hash ) {
			$rows = $this->get_posts( $prefix );
			$posts_hash = array();
			foreach( $rows as $row ) {
				$post = $this->get_post( $prefix, $row->ID );
				$post->post_modified = null;
				$post->post_modified_gmt = null;

				$post->meta_fields = $this->get_post_meta( $prefix, $row->ID );
				$md5 = md5( serialize( $post ) );
				$posts_hash[ "{$row->ID}:{$md5}" ] = $post->post_title;
			}
			set_transient( $key, $posts_hash, 60*60*24 );
		}
		return $posts_hash;

	}

	/**
	 * @param string $prefix
	 * @return array
	 */
	function get_posts( $prefix ) {
		global $wpdb;
		$sql =<<<SQL
SELECT 
	ID, guid 
FROM 
	{$prefix}_posts
WHERE 1=1
	AND post_status='publish' 
	AND post_type<>'revision'
SQL;
		return $wpdb->get_results( $sql );
	}

	/**
	 * @param string $prefix
	 * @param int $post_id
	 * @return object
	 */
	function get_post( $prefix, $post_id ) {
		/**
		 * @var wpdb
		 */
		global $wpdb;
		$sql = "SELECT * FROM {$prefix}_posts WHERE ID=%d";
		return $wpdb->get_row( $wpdb->prepare( $sql, $post_id ) );
	}

	/**
	 * @param string $prefix
	 * @param int $post_id
	 * @return array
	 */
	function get_post_meta( $prefix, $post_id ) {
		$meta = PressMerge()->get_meta( 'post', $prefix, $post_id );
		unset( $meta[ 'avada_post_views_count' ] );
		unset( $meta[ 'avada_post_views_count' ] );
		unset( $meta[ 'avada_post_views_count' ] );
		return $meta;
	}
	/**
	 * Return array of post_ids also indexed by post_ids
	 * 
	 * @param array[] $posts Array of post objects where the index starts with "{$post_id}:..." 
	 *
	 * @return int[]
	 */
	function get_indexed_posts_ids( $posts ) {
		$post_ids = array();
		foreach( array_keys( $posts ) as $index ) {
			$post_id = $this->get_post_id_from_index( $index );
			$post_ids[ $post_id ] = intval( $post_id ); 
		}
		return $post_ids;
	}

	/**
	 * @param array $posts
	 * @return array
	 */
	function fixup_added_posts( $posts ) {

		foreach( $posts as $index => $post ) {

			list( $post_id ) = $this->get_post_id_from_index( $index );

			$original_post_id = $post_id = intval( $post_id );

			$post_id += PressMerge_Posts::ID_INCREMENT;
			$post[ 'ID' ] = $post_id;

			$guid_regex = '#^.+\?p=' . intval( $original_post_id ) . '$#';

			if ( isset( $post[ 'guid' ] ) && preg_match( $guid_regex, $post[ 'guid' ], $match ) ) {
				$post['guid'] = "{$match[ 0 ]}{$post_id}";
			}

			if ( isset( $post[ 'post_parent' ] ) && intval( $post[ 'post_parent' ] ) > self::LAST_SHARED_ID ) {
				$post[ 'post_parent' ] += PressMerge_Posts::ID_INCREMENT;
			}

			$this->fixup_meta_post_id( $post, '_thumbnail_id' );
			$this->fixup_meta_post_id( $post, '_menu_item_object_id' );

			$posts[ $this->get_post_index( $post )] = $post;

		}
		$posts[ 'added' ] = $posts;

		return $posts;
	}

	/**
	 * If a meta fields contains a $post_id then this increments their value if needed.
	 *
	 * @param object $post Passed BY REFERENCE
	 * @param string $meta_key
	 */
	function fixup_meta_post_id( &$post, $meta_key ) {
		if ( false === strpos( $meta_key, ':' ) ) {
			$meta_key = "add_meta:{$meta_key}";
		}

		$post_id = isset( $post[ $meta_key ] ) ? intval( $post[ $meta_key ] ) : null;

		if ( $post_id && $post_id > self::LAST_SHARED_ID ) {
			$post[ $meta_key ] += PressMerge_Posts::ID_INCREMENT;
		}
	}

}
