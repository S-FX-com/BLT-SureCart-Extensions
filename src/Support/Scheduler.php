<?php
/**
 * Thin wrapper over Action Scheduler. All external HTTP for this plugin
 * happens inside Action Scheduler jobs — nothing blocking runs in a
 * customer-facing request.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Scheduler
 */
final class Scheduler {

	const GROUP = 'blt-sce';

	/**
	 * Confirm Action Scheduler is available. It is bundled with this
	 * plugin, but another active plugin may already have loaded a
	 * different copy first — Action Scheduler's own loader always keeps
	 * the newest version, so the functions are present either way.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::is_available() ) {
			// Nothing to do here beyond letting callers check
			// is_available() before enqueueing — a missing Action
			// Scheduler is reported as a Site Health issue, not fataled.
			return;
		}
	}

	/**
	 * Whether Action Scheduler's functions are loaded.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Enqueue a one-off async job to run as soon as a scheduler tick
	 * picks it up. Used from customer-facing hooks (order paid) so the
	 * hook itself returns immediately.
	 *
	 * @param string $hook Action hook the job runs.
	 * @param array  $args Arguments passed to the hook.
	 * @return int|null Action ID, or null if Action Scheduler is unavailable.
	 */
	public static function enqueue( $hook, array $args = array() ) {
		if ( ! self::is_available() ) {
			return null;
		}

		return as_enqueue_async_action( $hook, $args, self::GROUP );
	}

	/**
	 * Schedule a one-off job at a specific future time — used for
	 * bounded-retry backoff.
	 *
	 * @param int    $timestamp Unix timestamp to run at.
	 * @param string $hook      Action hook the job runs.
	 * @param array  $args      Arguments passed to the hook.
	 * @return int|null Action ID, or null if Action Scheduler is unavailable.
	 */
	public static function schedule_single( $timestamp, $hook, array $args = array() ) {
		if ( ! self::is_available() ) {
			return null;
		}

		return as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
	}

	/**
	 * Ensure a recurring job is scheduled, without creating duplicates on
	 * every page load.
	 *
	 * @param string $hook             Action hook the job runs.
	 * @param int    $interval_seconds Interval between runs.
	 * @param array  $args             Arguments passed to the hook.
	 * @return void
	 */
	public static function ensure_recurring( $hook, $interval_seconds, array $args = array() ) {
		if ( ! self::is_available() ) {
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action( time() + $interval_seconds, $interval_seconds, $hook, $args, self::GROUP );
	}

	/**
	 * Unschedule everything in our group — called on deactivation. Data
	 * is untouched; only future scheduled runs are cancelled.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( null, array(), self::GROUP );
	}
}
