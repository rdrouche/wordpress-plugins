<?php
defined( 'ABSPATH' ) || exit;

function rdrit_visibility_is_widget_context() {
	if ( is_admin() ) return true;
	global $wp_current_filter;
	if ( is_array( $wp_current_filter ) ) {
		foreach ( array( 'widget_block_content', 'dynamic_sidebar', 'the_widget' ) as $f ) {
			if ( in_array( $f, $wp_current_filter, true ) ) return true;
		}
	}
	return false;
}

add_filter( 'render_block', 'rdrit_visibility_render_block', 10, 2 );

function rdrit_visibility_render_block( $block_content, $block ) {
	if ( rdrit_visibility_is_widget_context() ) return $block_content;
	$attrs = $block['attrs'] ?? array();
	if ( ! rdrit_visibility_check_user_conditions( $attrs ) ) return '';
	$device_classes = rdrit_visibility_device_classes( $attrs );
	if ( $device_classes && ! empty( $block_content ) ) {
		$block_content = rdrit_visibility_inject_classes( $block_content, $device_classes );
	}
	return $block_content;
}

function rdrit_visibility_check_user_conditions( $attrs ) {
	$login = $attrs['rdritVisibilityLogin'] ?? 'all';
	if ( 'logged_in'  === $login && ! is_user_logged_in() ) return false;
	if ( 'logged_out' === $login &&   is_user_logged_in() ) return false;
	if ( ! is_user_logged_in() ) return true;
	$roles = $attrs['rdritVisibilityRoles'] ?? array();
	if ( ! empty( $roles ) ) {
		$user_roles = (array) wp_get_current_user()->roles;
		$match = false;
		foreach ( $roles as $role ) { if ( in_array( $role, $user_roles, true ) ) { $match = true; break; } }
		if ( ! $match ) return false;
	}
	foreach ( $attrs['rdritVisibilityCapabilities'] ?? array() as $cap ) {
		$cap = sanitize_key( $cap );
		if ( $cap && ! current_user_can( $cap ) ) return false;
	}
	return true;
}

function rdrit_visibility_device_classes( $attrs ) {
	$hide_on = $attrs['rdritVisibilityHideOn'] ?? array();
	$classes = array();
	if ( in_array( 'mobile',  $hide_on, true ) ) $classes[] = 'rdrit-hide-mobile';
	if ( in_array( 'tablet',  $hide_on, true ) ) $classes[] = 'rdrit-hide-tablet';
	if ( in_array( 'desktop', $hide_on, true ) ) $classes[] = 'rdrit-hide-desktop';
	return implode( ' ', $classes );
}

function rdrit_visibility_inject_classes( $html, $classes ) {
	return preg_replace( '/(<[a-z][a-z0-9]*\b)([^>]*>)/i', '$1 ' . esc_attr( $classes ) . ' $2', $html, 1 );
}
