<?php
/**
 * Plugin Name: PressMerge
 */
require 'includes/PressMerge.php';
require 'includes/PressMerge_Posts.php';
require 'includes/PressMerge_Terms.php';
require 'includes/PressMerge_Media.php';


PressMerge::cache_hashes( false );

PressMerge::on_load();
