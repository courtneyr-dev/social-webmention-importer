<?php
/**
 * Front-end attribution for curated social responses.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Display;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CourtneyRDev\SocialWebmentionImporter\Plugin;

/**
 * Renders the "Originally posted on …" label and the LinkedIn network badge.
 *
 * Verified Webmentions keep the Webmention plugin's presentation untouched.
 * Curated responses get a server-rendered source label appended to the
 * comment text — no scripts, no embeds, works without JavaScript — plus
 * screen-reader text identifying the response as externally sourced.
 *
 * The theme already paints network badges (X, Mastodon, Bluesky, …) from
 * the comment author URL with pure CSS; this class only adds the missing
 * LinkedIn rule, scoped to the same selector vocabulary, instead of
 * editing the theme.
 */
class Attribution {

	/**
	 * Human names for provider slugs used in the label.
	 *
	 * @var array<string,string>
	 */
	const PROVIDER_LABELS = array(
		'x'        => 'X',
		'linkedin' => 'LinkedIn',
	);

	/**
	 * Hook up display filters.
	 */
	public static function init() {
		add_filter( 'comment_text', array( static::class, 'append_source_label' ), 20, 2 );
		add_filter( 'comment_class', array( static::class, 'provider_comment_class' ), 10, 4 );
		add_action( 'wp_enqueue_scripts', array( static::class, 'enqueue_front_styles' ) );
	}

	/**
	 * Tag imported comments with their provider so CSS can paint a network
	 * badge even when no author URL exists.
	 *
	 * The theme's badges key off the comment author URL, but LinkedIn does
	 * not expose commenter profile URLs publicly — those imports would
	 * otherwise render with no badge at all.
	 *
	 * @param string[]    $classes    Comment classes.
	 * @param string[]    $css_class  Additional classes (unused).
	 * @param string      $comment_id Comment ID.
	 * @param \WP_Comment $comment    Comment object.
	 * @return string[]
	 */
	public static function provider_comment_class( $classes, $css_class, $comment_id, $comment ) {
		$provider = get_comment_meta( (int) $comment_id, '_swi_provider', true );
		if ( ! $provider ) {
			return $classes;
		}

		$classes[] = 'swi-provider-' . sanitize_html_class( $provider );

		// Imported commenters have no email, so without an avatar meta the
		// avatar block renders a Gravatar placeholder. Flag the comment so
		// CSS can hide the placeholder instead; the class disappears as
		// soon as an avatar is added.
		if ( '' === (string) get_comment_meta( (int) $comment_id, 'avatar', true ) ) {
			$classes[] = 'swi-no-avatar';
		}

		return $classes;
	}

	/**
	 * Append the source label to curated social responses, or — for
	 * verified Webmentions, whose display belongs to the Webmention
	 * plugin and stays visually untouched — a hidden text equivalent of
	 * the network badge.
	 *
	 * The network badge itself (the small logo painted next to the
	 * avatar) is a CSS background image driven by a class or href, in
	 * both modes: pure decoration with no accessible name. Verified
	 * Webmentions have no other text naming the network at all, so
	 * without this a screen reader announces nothing about where the
	 * response came from. Curated responses already speak the network
	 * name in the visible "Originally posted on …" sentence, so only the
	 * hidden badge equivalent is added there, not a duplicate label.
	 *
	 * @param string           $comment_text Comment text (already filtered by core).
	 * @param \WP_Comment|null $comment      Comment object.
	 * @return string
	 */
	public static function append_source_label( $comment_text, $comment = null ) {
		if ( ! $comment instanceof \WP_Comment ) {
			return $comment_text;
		}

		$mode     = get_comment_meta( $comment->comment_ID, '_swi_import_mode', true );
		$provider = get_comment_meta( $comment->comment_ID, '_swi_provider', true );

		if ( ! $provider ) {
			return $comment_text;
		}

		$network = self::PROVIDER_LABELS[ $provider ] ?? ucfirst( (string) $provider );

		if ( Plugin::MODE_WEBMENTION === $mode ) {
			return $comment_text . sprintf(
				'<span class="screen-reader-text">%s</span>',
				esc_html(
					sprintf(
						/* translators: %s: network name (X, LinkedIn). */
						__( 'via %s', 'social-webmention-importer' ),
						$network
					)
				)
			);
		}

		if ( Plugin::MODE_SOCIAL_LINKBACK !== $mode ) {
			return $comment_text;
		}

		$source = get_comment_meta( $comment->comment_ID, 'url', true );

		if ( ! $source || ! wp_http_validate_url( $source ) ) {
			return $comment_text;
		}

		$label = sprintf(
			'<p class="swi-source-label"><a href="%1$s" rel="nofollow ugc noopener">%2$s<span class="screen-reader-text">%3$s</span></a></p>',
			esc_url( $source ),
			esc_html(
				sprintf(
					/* translators: %s: network name (X, LinkedIn). */
					__( 'Originally posted on %s', 'social-webmention-importer' ),
					$network
				)
			),
			esc_html__( ' (external)', 'social-webmention-importer' )
		);

		return $comment_text . $label;
	}

	/**
	 * Enqueue the small front-end stylesheet on singular views with comments.
	 *
	 * Contains the source-label styling and the LinkedIn badge rule that
	 * mirrors the theme's CSS-only network badges.
	 */
	public static function enqueue_front_styles() {
		if ( ! is_singular() ) {
			return;
		}

		wp_enqueue_style(
			'swi-front',
			SWI_PLUGIN_URL . 'assets/css/front.css',
			array(),
			SWI_VERSION
		);
	}
}
