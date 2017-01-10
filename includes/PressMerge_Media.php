<?php

class PressMerge_Media {

	const LAST_SHARED_ID = 17156;
	const ID_INCREMENT = 10000;

	function __construct() {

	}


	/**
	 *
	 */
	function build_media_changes() {

		$production_hash = $this->get_medias_hash( PressMerge::LIVE_PREFIX );
		$staging_hash = $this->get_medias_hash( PressMerge::STAGE_PREFIX );

		$stats = array(
			'statistics' => array(
				'in_existing_db' => count( $production_hash ),
				'in_new_db'      => count( $staging_hash ),
			)
		);

		/*
		 * Create a index for staging medias
		 */
		foreach( $staging_hash as $index => $title ) {
			$staging_index[ $this->get_media_id_from_index( $index ) ] = $index;
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


		$changes= $this->find_changed_medias( $staging_hash, PressMerge::STAGE_PREFIX, PressMerge::LIVE_PREFIX );

		$changes[ 'not_in_new' ] = $this->find_missing_medias( $staging_hash, PressMerge::STAGE_PREFIX, PressMerge::LIVE_PREFIX );

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
	function find_missing_medias( $hash, $prefix_new, $prefix_existing ) {
		$missing = array();
		foreach( $hash as $index => $title ) {
			$media_id = $this->get_media_id_from_index( $index );
			$media = $this->get_media( $prefix_existing, $media_id );
			if ( ! is_null( $media ) ) {
				continue;
			}
			$media = $this->get_media( $prefix_new, $media_id );
			if ( 'revision' === $media->media_type ) {
				continue;
			}
			$missing[ $media_id ] = $media->media_title;
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
	function find_changed_medias( $hash, $prefix_new, $prefix_existing ) {
		$different = array();
		foreach( $hash as $index => $md5 ) {

			$updated = $this->find_updated_media( $index, $prefix_new, $prefix_existing );
			$media_id = $this->get_media_id_from_index( $index );
			$media = $this->get_media( $prefix_new, $media_id );

			if ( ! is_null( $updated ) ) {
				$index = $this->get_media_index( $media );

//				/**
//				 * Assume if we have only 3 fields or less that are different
//				 * then the user updated it. Otherwise the user duplicated an
//				 * media ID because of the fork.
//				 */
//				$cohort = 7 >= count( $updated ) ? 'updated' : 'dup_id';
				$cohort = self::LAST_SHARED_ID >= $media_id
					? 'updated'
					: 'added';

				$different[ $cohort ][ $index ] = $updated;
			}

		}
		return $different;
	}

	/**
	 * @param object $media
	 *
	 * @return string In the form "{$media_id}:{$media_title}:
	 */
	function get_media_index( $media ) {
		$media = (object) $media;
		return "{$media->ID}:{$media->media_title}";
	}

	/**
	 * @param string $index In the form "{$media_id}:{$media_title}:
	 *
	 * @return array Two elements: 0=$media_id and 1=$media_title
	 */
	function parse_media_index( $index ) {
		return explode( ':', "{$index}:" );
	}

	/**
	 * @param string $index In the form "{$media_id}:{$media_title}:
	 *
	 * @return int
	 */
	function get_media_id_from_index( $index ) {
		list( $media_id ) = $this->parse_media_index( $index );
		return $media_id;
	}

	/**
	 * @param string $index
	 * @param string $prefix_new
	 * @param string $prefix_existing
	 *
	 * @return array|string
	 */
	function find_updated_media( $index, $prefix_new, $prefix_existing ) {
		$media_id = $this->get_media_id_from_index( $index );
		$existing_media = $this->get_media( $prefix_existing, $media_id );

		if ( is_null( $existing_media ) ) {
			$existing_media = (object)array();
		}
		$new_media = $this->get_media( $prefix_new, $media_id );
		$compare = $this->compare_medias( $new_media, $existing_media, $prefix_new, $prefix_existing );
		$updated   = count( $compare )
			? $compare
			: null;

		return $updated;

	}

	/**
	 * @param object $new_media
	 * @param object $existing_media
	 * @param string $prefix_new
	 * @param string $prefix_existing
	 *
	 * @return array|string
	 */
	function compare_medias( $new_media, $existing_media, $prefix_new, $prefix_existing ) {

		$new = array();
		foreach ( get_object_vars( $new_media ) as $name => $new_value ) {
			if ( ! isset( $existing_media->$name ) )  {
				$new[ $name ] = $new_value;
				continue;
			}
			if ( $new_value === $existing_media->$name ) {
				continue;
			}
			if ( preg_match( '#^(comment_count|media_modified(_gmt)?)$#', $name ) ) {
				continue;
			}

			/**
			 * Strip all non-printable characters
			 */
			$new_value = PressMerge()->strip_nonreadable_chars( $new_value );
			$existing_value = PressMerge()->strip_nonreadable_chars(  $existing_media->$name );

			if ( $new_value === $existing_value ) {
				continue;
			}

			$new[ $name ] = $new_value;

		}


		$meta = PressMerge()->compare_meta(
			'media',
			$new_media->ID,
			isset( $existing_media->ID ) ? $existing_media->ID : 0,
			$prefix_new,
			$prefix_existing
		);

		$meta = PressMerge()->remove_meta_keys( $meta, array(
			'delete_meta:_media_restored_from',
			'avada_media_views_count',
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
	function get_medias_hash( $prefix ) {
		$key = "medias_hash:{$prefix}";

		$medias_hash = PressMerge::cache_hashes()
			? get_transient( "medias_hash:{$prefix}" )
			: null;

		if ( ! $medias_hash ) {
			/**
			 * @var wpdb
			 */
			global $wpdb;
			$rows = $this->get_medias( $prefix );
			$medias_hash = array();
			foreach( $rows as $row ) {
				$media = $this->get_media( $prefix, $row->ID );
				$media->media_modified = null;
				$media->media_modified_gmt = null;

				$media->meta_fields = $this->get_media_meta( $prefix, $row->ID );
				$md5 = md5( serialize( $media ) );
				$medias_hash[ "{$row->ID}:{$md5}" ] = $media->media_title;
			}
			set_transient( $key, $medias_hash, 60*60*24 );
		}
		return $medias_hash;

	}

	/**
	 * @param string $prefix
	 * @return array
	 */
	function get_medias( $prefix ) {
		global $wpdb;
		$sql =<<<SQL
SELECT 
	ID, guid 
FROM 
	{$prefix}_medias
WHERE 1=1
	AND media_status='publish' 
	AND media_type<>'revision'
SQL;
		return $wpdb->get_results( $sql );
	}

	/**
	 * @param string $prefix
	 * @param int $media_id
	 * @return object
	 */
	function get_media( $prefix, $media_id ) {
		/**
		 * @var wpdb
		 */
		global $wpdb;
		$sql = "SELECT * FROM {$prefix}_medias WHERE ID=%d";
		return $wpdb->get_row( $wpdb->prepare( $sql, $media_id ) );
	}

	/**
	 * @param string $prefix
	 * @param int $media_id
	 * @return array
	 */
	function get_media_meta( $prefix, $media_id ) {
		$meta = PressMerge()->get_meta( 'media', $prefix, $media_id );
		unset( $meta[ 'avada_media_views_count' ] );
		unset( $meta[ 'avada_media_views_count' ] );
		unset( $meta[ 'avada_media_views_count' ] );
		return $meta;
	}
	/**
	 * Return array of media_ids also indexed by media_ids
	 * 
	 * @param array[] $medias Array of media objects where the index starts with "{$media_id}:..." 
	 *
	 * @return int[]
	 */
	function get_indexed_medias_ids( $medias ) {
		$media_ids = array();
		foreach( array_keys( $medias ) as $index ) {
			$media_id = $this->get_media_id_from_index( $index );
			$media_ids[ $media_id ] = intval( $media_id ); 
		}
		return $media_ids;
	}

	/**
	 * @param array $medias
	 * @return array
	 */
	function fixup_added_media( $medias ) {

		foreach( $medias as $index => $media ) {

			list( $media_id ) = $this->get_media_id_from_index( $index );

			$original_media_id = $media_id = intval( $media_id );

			$media_id += PressMerge_Medias::ID_INCREMENT;
			$media[ 'ID' ] = $media_id;

			$guid_regex = '#^.+\?p=' . intval( $original_media_id ) . '$#';

			if ( isset( $media[ 'guid' ] ) && preg_match( $guid_regex, $media[ 'guid' ], $match ) ) {
				$media['guid'] = "{$match[ 0 ]}{$media_id}";
			}

			if ( isset( $media[ 'media_parent' ] ) && intval( $media[ 'media_parent' ] ) > self::LAST_SHARED_ID ) {
				$media[ 'media_parent' ] += PressMerge_Medias::ID_INCREMENT;
			}

			$this->fixup_meta_media_id( $media, '_thumbnail_id' );
			$this->fixup_meta_media_id( $media, '_menu_item_object_id' );

			$medias[ $this->get_media_index( $media )] = $media;

		}
		$medias[ 'added' ] = $medias;

		return $medias;
	}

	/**
	 * If a meta fields contains a $media_id then this increments their value if needed.
	 *
	 * @param object $media Passed BY REFERENCE
	 * @param string $meta_key
	 */
	function fixup_meta_media_id( &$media, $meta_key ) {
		if ( false === strpos( $meta_key, ':' ) ) {
			$meta_key = "add_meta:{$meta_key}";
		}

		$media_id = isset( $media[ $meta_key ] ) ? intval( $media[ $meta_key ] ) : null;

		if ( $media_id && $media_id > self::LAST_SHARED_ID ) {
			$media[ $meta_key ] += PressMerge_Medias::ID_INCREMENT;
		}
	}

}
