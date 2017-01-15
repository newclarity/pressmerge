<?php
/**
 * Plugin Name: PressMerge
 */
require 'includes/PressMerge.php';
require 'includes/PressMerge_Posts.php';
require 'includes/PressMerge_Terms.php';
require 'includes/PressMerge_Files.php';


PressMerge::on_load();
PressMerge()->cache_hashes( true );
