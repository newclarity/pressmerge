<?php

class PressMerge_Files {

	/**
	 *
	 */
	function build_file_changes() {

		$production_hash = $this->get_file_hashes( PressMerge::LIVE_PREFIX, ABSPATH . PressMerge::LIVE_UPLOADS );
		$staging_hash = $this->get_file_hashes( PressMerge::STAGE_PREFIX, ABSPATH . PressMerge::STAGE_UPLOADS );

		$production_files = $production_hash[ 'files' ];
		$staging_files = $staging_hash[ 'files' ];

		$stats = array(
			'statistics' => array(
				'in_existing_db' => count( $production_files ),
				'in_new_db'      => count( $staging_files ),
			)
		);

		list( $production_files, $staging_files ) = array_values(
			$this->remove_matching_files( $production_files, $staging_files )
		);

		$stats['statistics']['non_matching_existing'] = count( $production_files );
		$stats['statistics']['non_matching_new']      = count( $staging_files );

		$production_hash[ 'files' ] = $production_files;
		$staging_hash[ 'files' ] = $staging_files;

		$changes = $this->find_changed_files( $staging_hash, $production_files );

		$changes = array_merge( $stats, $changes );

		return $changes;

	}

	/**
	 * @param array $production_files
	 * @param array $staging_files
	 *
	 * @return array|string
	 */
	function remove_matching_files( $production_files, $staging_files ) {

		foreach( $production_files as $index => $file_hash ) {
			if ( ! isset( $staging_files[ $index ] ) ) {
				continue;
			}
			if ( $file_hash !== $staging_files[ $index ]) {
				continue;
			}
			unset( $production_files[ $index ] );
			unset( $staging_files[ $index ] );
		}

		return array(
			'production_files' => $production_files,
			'staging_files'    => $staging_files,
		);
	}

	/**
	 * @param array  $staging_hash
	 * @param array  $production_files
	 *
	 * @return array
	 */
	function find_changed_files( $staging_hash, $production_files ) {

		$omitted = array();
		foreach( $production_files as $relative_path => $hash ) {
			if ( isset( $staging_hash[ $relative_path ] ) ) {
				continue;
			}
			$omitted[] = $relative_path;
		}

		$changed = array(
			'updated' => array(),
			'added'   => array(),
			'omitted' => $omitted,
		);
		foreach( $staging_hash[ 'files' ] as $relative_path => $hash ) {

			$cohort = isset( $production_files[ $relative_path ] )
				? 'updated'
				: 'added';

			$changed[ $cohort ][] = $relative_path;

		}
		return $changed;
	}

	/**
	 * @param string $prefix
	 * @param string $basedir
	 *
	 * @return string[]
	 */
	function get_file_hashes( $prefix, $basedir ) {
		$cache_key = "file_hashes:{$prefix}";

		$files_hash = PressMerge()->cache_hashes()
			? get_transient( $cache_key )
			: null;

		if ( ! $files_hash ) {
			$files_hash = array();

			$files = $this->get_files( $prefix, $basedir );

			foreach( $files as $filepath => $file_hash ) {
				$files_hash[ $file_hash ][] = $filepath;
			}

			$files_hash = array(
				'files' => $files,
				'hashes' => $files_hash,
			);

			set_transient( $cache_key, $files_hash, 60*60*24 );
		}
		return $files_hash;

	}

	/**
	 * @param string $prefix
	 * @param string $basedir
	 * @return array
	 */
	function get_files( $prefix, $basedir ) {

		global $wpdb;
		$sql =<<<SQL
SELECT
	guid
FROM
	{$prefix}_posts
WHERE
	post_type = 'attachment'
ORDER BY
	guid
SQL;
		$attachments = $wpdb->get_col( $sql );

		foreach( $attachments as $index => $attachment ) {

			$attachments[ $index ] = PressMerge()->get_relative_path( $attachment );

		}
		sort( $attachments );
		$attachments = array_flip( $attachments );

		$exclusions = array(
			'backupbuddy_temp',
			'backupbuddy_backups',
			'pb_backupbuddy',
			'profiles',
			'wc-logs',
			'woocommerce_uploads',
			'GeoIP.dat',
			'GeoIPv6.dat',
			'revslider',
			'layerslider',
			'avada-styles',
			'pressmerge.txt',
		);

		$attachments = PressMerge()->filter_exclude_paths( $attachments, $exclusions );

		$files = $this->get_file_listing_hashes( $basedir );
		ksort( $files );

		$files = PressMerge()->filter_original_files( $files );

		$files = PressMerge()->filter_exclude_paths( $files, $exclusions );

		$orphans = array();
		foreach( array_keys( $attachments ) as $relative_path ) {
			if ( isset( $files[ $relative_path ] ) ) {
				continue;
			}
			$filepath = "{$basedir}/{$relative_path}";
			$orphans[ $relative_path ] = md5_file( $filepath );
		}

		unset( $attachments );

		$files = array_merge( $orphans, $files );

		return $files;

	}


	/**
	 * @param string $directory
	 * @return string[]
	 */
	function get_file_listing_hashes( $directory ) {

		$cache_key = 'pressmerge_files';

		$files = PressMerge()->cache_hashes()
			? get_transient( $cache_key )
			: null;

		if ( ! $files ) {
			$directory = rtrim( $directory, DIRECTORY_SEPARATOR );
			$rdi = new RecursiveDirectoryIterator( $directory );
			$rii = new RecursiveIteratorIterator( $rdi );
			$files = array();
			foreach( $rii as $index => $file ) {
				if ( preg_match( '#^(\.|\.\.|\.DS_Store)$#', $file->getFilename() ) ) {
					continue;
				}
				$relative_path = PressMerge()->get_relative_path( $file->getRealPath(), $directory );

				$files[ $relative_path ] = md5_file( $file->getRealPath() );
			}
			set_transient( $cache_key, $files, 24*60*60 );
		}
		return $files;
	}

	/**
	 * @param array $files
	 * @return array
	 */
	function fixup_added_files( $files ) {

//		foreach( $files as $index => $file ) {
//
//			list( $file_id ) = $this->get_file_id_from_index( $index );
//
//			$original_file_id = $file_id = intval( $file_id );
//
//			$file_id += PressMerge_Files::ID_INCREMENT;
//			$file[ 'ID' ] = $file_id;
//
//			$guid_regex = '#^.+\?p=' . intval( $original_file_id ) . '$#';
//
//			if ( isset( $file[ 'guid' ] ) && preg_match( $guid_regex, $file[ 'guid' ], $match ) ) {
//				$file['guid'] = "{$match[ 0 ]}{$file_id}";
//			}
//
//			if ( isset( $file[ 'file_parent' ] ) && intval( $file[ 'file_parent' ] ) > self::LAST_SHARED_ID ) {
//				$file[ 'file_parent' ] += PressMerge_Files::ID_INCREMENT;
//			}
//
//			$files[ $this->get_file_index( $file )] = $file;
//
//		}
//		$files[ 'added' ] = $files;

		return $files;
	}

}
