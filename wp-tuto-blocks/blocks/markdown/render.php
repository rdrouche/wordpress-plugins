<?php
defined( 'ABSPATH' ) || exit;

require_once plugin_dir_path( __FILE__ ) . '../../vendor/RdritMarkdownParser.php';

if ( empty( $attributes['content'] ) ) {
	return;
}

$html = RdritMarkdownParser::parse( $attributes['content'] );

$wrapper_attributes = get_block_wrapper_attributes( array( 'class' => 'wp-tuto-markdown' ) );

printf( '<div %s>%s</div>', $wrapper_attributes, $html );
