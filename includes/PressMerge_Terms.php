<?php

class PressMerge_Terms {

	const LAST_SHARED_ID = 967;
	const ID_INCREMENT = 1000;

	/**
	 *
	 */
	function build_term_changes() {

		$production_hash = $this->get_terms_hash( PressMerge::LIVE_PREFIX );
		$staging_hash = $this->get_terms_hash( PressMerge::STAGE_PREFIX );

		$stats = array(
			'statistics' => array(
				'in_existing_db' => count( $production_hash ),
				'in_new_db'      => count( $staging_hash ),
			)
		);

		/*
		 * Create a index for staging terms
		 */
		foreach( $staging_hash as $index => $title ) {
			$term_id = $this->get_term_id_from_index( $index );
			$staging_index[ $term_id ] = $index;
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

		$changes= $this->find_changed_terms( $staging_hash, PressMerge::STAGE_PREFIX, PressMerge::LIVE_PREFIX );

		$changes[ 'omitted' ] = $this->find_omitted_terms( $staging_hash, PressMerge::STAGE_PREFIX, PressMerge::LIVE_PREFIX );

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
	function find_omitted_terms( $hash, $prefix_new, $prefix_existing ) {
		$missing = array();
		foreach( $hash as $index => $title ) {
			$term_id = $this->get_term_id_from_index( $index );
			$term = $this->get_term( $prefix_existing, $term_id );
			if ( ! is_null( $term ) ) {
				continue;
			}
			$term = $this->get_term( $prefix_new, $term_id );
			$missing[ $term_id ] = $term->name;
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
	function find_changed_terms( $hash, $prefix_new, $prefix_existing ) {
		$different = array();
		foreach( array_keys( $hash ) as $index ) {

			$updated = $this->find_updated_term( $index, $prefix_new, $prefix_existing );

			if ( ! is_null( $updated ) ) {
				$term_id = $this->get_term_id_from_index( $index );
				$term = $this->get_term( $prefix_new, $term_id );

				$index = $this->get_term_index( $term );

				$cohort = self::LAST_SHARED_ID >= $term_id
					? 'updated'
					: 'added';

				$different[ $cohort ][ $index ] = $updated;
			}

		}
		return $different;
	}

	/**
	 * @param object $term
	 *
	 * @return string In the form "{$term_id}:{$term_name}:
	 */
	function get_term_index( $term ) {
		$term = (object) $term;
		return "{$term->term_id}:{$term->name}";
	}

	/**
	 * @param string $index In the form "{$term_id}:{$term_name}:
	 *
	 * @return array Two elements: 0=$term_id and 1=$term_name
	 */
	function parse_term_index( $index ) {
		return explode( ':', "{$index}:" );
	}

	/**
	 * @param string $index In the form "{$term_id}:{$term_name}:
	 *
	 * @return int
	 */
	function get_term_id_from_index( $index ) {
		list( $term_id ) = $this->parse_term_index( $index );
		return $term_id;
	}

	/**
	 * @param string $index
	 * @param string $prefix_new
	 * @param string $prefix_existing
	 *
	 * @return array|string
	 */
	function find_updated_term( $index, $prefix_new, $prefix_existing ) {
		$term_id = $this->get_term_id_from_index( $index );
		$existing_term = $this->get_term( $prefix_existing, $term_id );
		if ( is_null( $existing_term ) ) {
			$existing_term = (object)array();
		}
		$new_term = $this->get_term( $prefix_new, $term_id );
		$compare = $this->compare_terms( $new_term, $existing_term, $prefix_new, $prefix_existing );
		$updated   = count( $compare )
			? $compare
			: null;
		return $updated;
	}

	/**
	 * @param object $new_term
	 * @param object $existing_term
	 * @param string $prefix_new
	 * @param string $prefix_existing
	 *
	 * @return array|string
	 */
	function compare_terms( $new_term, $existing_term, $prefix_new, $prefix_existing ) {

		$new = array();
		foreach ( get_object_vars( $new_term ) as $name => $new_value ) {

			if ( ! isset( $existing_term->$name ) ) {
				$new[ $name ] = $new_value;
				continue;
			}

			/**
			 * Strip all non-printable characters
			 */
			$new_value      = PressMerge()->strip_nonreadable_chars( $new_value );
			$existing_value = PressMerge()->strip_nonreadable_chars( $existing_term->$name );

			if ( is_scalar( $new_value ) ) {

				if ( $new_value === $existing_value ) {
					continue;
				}

				$new[ $name ] = $new_value;

			} else if ( 'taxonomy' === $name ) {

				$new[ $name ] = $new_value;

			} else if ( 'relationships' === $name ) {

				$new[ $name ] = $new_value;

			} else if ( 'meta_fields' === $name ) {

				$new[ $name ] = $new_value;

			}

		}

		$meta = PressMerge()->compare_meta(
			'term',
			$new_term->term_id,
			isset( $existing_term->term_id ) ? $existing_term->term_id : 0,
			$prefix_new,
			$prefix_existing
		);

		if ( isset( $meta['delete_meta:_post_restored_from'] ) ) {
			unset( $meta['delete_meta:_post_restored_from'] );
		}

		$new = array_merge( $new, $meta );

		return $new;
	}

	/**
	 * @param string $prefix
	 *
	 * @return string[]
	 */
	function get_terms_hash( $prefix ) {
		$key = "terms_hash:{$prefix}";
		$terms_hash = PressMerge()->cache_hashes()
			? get_transient( "terms_hash:{$prefix}" )
			: null;
		if ( ! $terms_hash ) {
			$rows = $this->get_terms( $prefix );
			$terms_hash = array();
			foreach( $rows as $row ) {
				$term                                         = $this->get_term( $prefix, $row->term_id );
				$terms_hash[ $this->get_term_index( $term ) ] = $term;
			}
			set_transient( $key, $terms_hash,  60*60*24 );
		}
		return $terms_hash;

	}

	/**
	 * @param string $prefix
	 * @return array
	 */
	function get_terms( $prefix ) {
		global $wpdb;
		$sql = "SELECT * FROM {$prefix}_terms";
		return $wpdb->get_results( $sql );
	}

	/**
	 * @param string $prefix
	 * @param int $term_id
	 * @return object
	 */
	function get_term( $prefix, $term_id ) {
		/**
		 * @var wpdb
		 */
		global $wpdb;
		$sql = "SELECT * FROM {$prefix}_terms WHERE term_id=%d";
		$term = $wpdb->get_row( $wpdb->prepare( $sql, $term_id ) );
		if ( ! is_null( $term ) ) {
			$term->meta_fields   = $this->get_term_meta( $prefix, $term->term_id );
			$term->taxonomy      = $this->get_term_taxonomy( $prefix, $term->term_id );
			$tt_ids              = wp_list_pluck( $term->taxonomy, 'term_taxonomy_id' );
			$term->relationships = $this->get_term_relationships( $prefix, $tt_ids );
		}
		return $term;

	}


	/**
	 * @param string $prefix
	 * @param int $term_id
	 * @return array
	 */
	function get_term_taxonomy( $prefix, $term_id ) {
		global $wpdb;
		$sql = "SELECT * FROM {$prefix}_term_taxonomy WHERE term_id=%d";
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $term_id ) );

		foreach( $results as $index => $term_taxonomy ) {
			unset( $term_taxonomy->count );
			$results[ $index ] = $term_taxonomy;
		}

		return $results;
	}

	/**
	 * @param string $prefix
	 * @param int[] $tt_ids
	 * @return array
	 */
	function get_term_relationships( $prefix, $tt_ids ) {
		global $wpdb;
		$tt_ids = implode( ',', array_map( 'intval', $tt_ids ) );
		$sql = "SELECT * FROM {$prefix}_term_relationships WHERE term_taxonomy_id IN ({$tt_ids})";
		return $wpdb->get_results( $sql );
	}

	/**
	 * @param string $prefix
	 * @param int $term_id
	 * @return array
	 */
	function get_term_meta( $prefix, $term_id ) {
		$meta = PressMerge()->get_meta( 'term', $prefix, $term_id );
		return $meta;
	}

	/**
	 * @param array $added_terms
	 * @return array
	 */
	function fixup_added_terms( $added_terms ) {

		foreach( $added_terms as $added_index => $added_term ) {

			list( $term_id, $title ) = explode( ';', "{$added_index};" );

			$term_id += PressMerge_Terms::ID_INCREMENT;

			$taxonomies = isset( $added_term[ 'taxonomy' ] )
				? $added_term[ 'taxonomy' ]
				: array();

			$old_tt_ids = array();

			foreach( $taxonomies as $tax_index => $term_taxonomy ) {
				/**
				 * We will add it back at the end of the loop.
				 */
				unset( $added_terms[ 'taxonomy' ][ $tax_index ] );

				$fields = array( 'term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent' );

				foreach( $fields as $field_name ) {
					/**
					 * Fixup the term_taxonomies->$field_name if newly added
					 */
					if ( ! isset( $term_taxonomy->term_id ) ) {
						trigger_error( sprintf( __( "Unexpected: \$term->taxonomy does not contain an ->\${$field_name}", 'pressmerge' ) ) );
						continue;
					}

				}

				/**
				 * Fixup the term_taxonomies->term_id if newly added
				 */
				if ( intval( $term_taxonomy->term_id ) > self::LAST_SHARED_ID ) {

					$old_tt_ids[ $term_taxonomy->term_taxonomy_id ] = true;

					$old_term = get_term_by( 'name', $title, $term_taxonomy->taxonomy );

					if ( ! $old_term  ) {
						/**
						 * No old term was found so we'll need to add it with a new ID
						 */
						$term_taxonomy->term_id += PressMerge_Terms::ID_INCREMENT;
						$term_taxonomy->term_taxonomy_id += PressMerge_Terms::ID_INCREMENT;
					} else {
						/**
						 * An old term was found so let's use it
						 */
						$term_taxonomy->term_id          = $old_term->term_id;
						$term_taxonomy->term_taxonomy_id = $old_term->term_taxonomy_id;
						$term_taxonomy->description      = $old_term->description;
						$term_taxonomy->parent           = $old_term->parent;
					}
				}

				$added_terms[ 'taxonomy' ][ $tax_index ] = $term_taxonomy;

			}
			$added_terms[ 'taxonomy' ] = $taxonomies;


			$relationships = isset( $added_term[ 'relationships' ] )
				? $added_term[ 'relationships' ]
				: array();

			foreach( $relationships as $rel_index => $relationship ) {
				/**
				 * We will add it back at the end of the loop.
				 */
				unset( $added_terms[ 'relationships' ][ $rel_index ] );

				if ( ! isset( $relationship->object_id ) ) {
					trigger_error( sprintf( __( 'Unexpected: \$term->relationships does not contain an ->\$object_id', 'pressmerge' ) ) );
					continue;
				}

				if ( ! isset( $relationship->term_taxonomy_id ) ) {
					trigger_error( sprintf( __( 'Unexpected: \$term->relationships does not contain a ->\$term_taxonomy_id', 'pressmerge' ) ) );
					continue;
				}

				/**
				 * Fixup the term_relationships->object_id if newly added
				 */
				if ( intval( $relationship->object_id ) > PressMerge_Posts::LAST_SHARED_ID ) {
					$relationship->object_id += PressMerge_Posts::ID_INCREMENT;
				}

				/**
				 * Already found in term_taxonomy, let's increment it the same.
				 */
				if ( isset( $old_tt_ids[ $relationship->term_taxonomy_id ] ) ) {
					$relationship->term_taxonomy_id += PressMerge_Terms::ID_INCREMENT;
				}

				$added_terms[ 'relationships' ][ $rel_index ] = $relationship;

			}

			$added_terms[ 'relationships' ] = $relationships;

			$added_terms[ "{$term_id}:{$title}" ] = $added_term;



		}
		$terms[ 'added' ] = $added_terms;

		return $terms;
	}



}

