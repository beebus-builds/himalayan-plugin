<?php
/**
 * Scheduled automation (WP-Cron).
 *
 * Runs the fixers automatically on a daily schedule so images and posts added
 * after the manual bulk run are still fixed without any admin clicks. Each
 * scheduled event processes a small slice of work (so it can't time out) and
 * reschedules itself implicitly by the recurring event.
 */
class ATF_Cron {

	const HOOK       = 'atf_daily_autofix';
	const LIB_LIMIT  = 50;
	const POST_LIMIT = 20;
	const OPT_LIMIT  = 20;

	/**
	 * Initialise hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Activation: schedule the event if enabled in settings.
	 */
	public static function activate() {
		$settings = get_option( 'atf_settings', array() );
		self::set_schedule( ! empty( $settings['auto_schedule'] ) && 'yes' === $settings['auto_schedule'] );
	}

	/**
	 * Deactivation: clear the scheduled event.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Schedule / clear the event based on the saved setting.
	 *
	 * @param bool $enabled Whether scheduling should be active.
	 */
	public static function set_schedule( $enabled ) {
		if ( $enabled ) {
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_event( time(), 'daily', self::HOOK );
			}
		} else {
			$timestamp = wp_next_scheduled( self::HOOK );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::HOOK );
			}
		}
	}

	/**
	 * Run one scheduled pass: a slice of each scope.
	 */
	public static function run() {
		$fixer = Alt_Text_Fixer::init();

		// Media library: fix a batch of missing-alt attachments.
		$lib_ids = ATF_Bulk_Fixer::get_missing_alt_attachments( 0, self::LIB_LIMIT );
		foreach ( $lib_ids as $id ) {
			$fixer->set_alt_text( $id );
		}

		// Content / meta / css / global via the content fixer's slice methods.
		$posts = ATF_Content_Fixer::get_posts( 0, self::POST_LIMIT );
		foreach ( $posts as $pid ) {
			ATF_Content_Fixer::fix_post_scope( $pid, 'content', $fixer );
			ATF_Content_Fixer::fix_post_scope( $pid, 'meta', $fixer );
			ATF_Content_Fixer::fix_post_scope( $pid, 'css', $fixer );
		}

		// Options (widgets / customizer) slice.
		$opts = array_slice( ATF_Content_Fixer::global_option_names(), 0, self::OPT_LIMIT );
		ATF_Content_Fixer::fix_global( 'global', $fixer, $opts );

		// SEO: generate meta for a slice of posts missing it.
		$seo_ids = ATF_SEO::get_posts_missing( 0, self::POST_LIMIT );
		foreach ( $seo_ids as $id ) {
			$post = get_post( $id );
			if ( ! ATF_SEO::is_supported( $post ) ) {
				continue;
			}
			if ( '' === get_post_meta( $id, ATF_SEO::TITLE_META, true ) ) {
				update_post_meta( $id, ATF_SEO::TITLE_META, ATF_SEO::generate_title( $id ) );
			}
			if ( '' === get_post_meta( $id, ATF_SEO::DESC_META, true ) ) {
				update_post_meta( $id, ATF_SEO::DESC_META, ATF_SEO::generate_description( $id ) );
			}
		}

		// Schema: mark a slice of schema-eligible posts as processed.
		ATF_Schema::mark_batch( 0, self::POST_LIMIT );
	}

	/**
	 * Total remaining work across all scopes (for the dashboard widget).
	 *
	 * @return array
	 */
	public static function remaining_summary() {
		return array(
			'library' => ATF_Bulk_Fixer::count_missing_alt(),
			'content' => ATF_Content_Fixer::count_scope( 'content' ),
			'meta'    => ATF_Content_Fixer::count_scope( 'meta' ),
			'css'     => ATF_Content_Fixer::count_scope( 'css' ),
			'global'  => ATF_Content_Fixer::count_scope( 'global' ),
			'seo'     => ATF_SEO::count_missing(),
			'schema'  => ATF_Schema::count_posts_with_schema(),
		);
	}
}

ATF_Cron::init();
