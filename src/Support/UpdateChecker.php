<?php
/**
 * Self-hosted update mechanism via private GitHub releases, using
 * YahnisElsts/plugin-update-checker v5 (tasks/00-discovery.md §E).
 * Bundled via Composer; only initialized in wp-admin (update checks are
 * meaningless on the front end).
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Support;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UpdateChecker
 */
final class UpdateChecker {

	const DEFAULT_REPO_URL = 'https://github.com/s-fx-com/blt-surecart-extensions/';
	const DEFAULT_BRANCH   = 'main';
	const SLUG             = 'blt-surecart-extensions';

	/**
	 * The built checker, so a screen can report when it last ran.
	 *
	 * @var object|null
	 */
	private static $checker = null;

	/**
	 * Initialize the update checker, if the library loaded.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! class_exists( PucFactory::class ) ) {
			return;
		}

		$repo_url = defined( 'BLT_SCE_GITHUB_REPO_URL' ) && BLT_SCE_GITHUB_REPO_URL ? BLT_SCE_GITHUB_REPO_URL : self::DEFAULT_REPO_URL;
		$branch   = defined( 'BLT_SCE_GITHUB_BRANCH' ) && BLT_SCE_GITHUB_BRANCH ? BLT_SCE_GITHUB_BRANCH : self::DEFAULT_BRANCH;

		// 24 is required, not a preference: a checker built with 0 registers
		// no scheduler hooks at all and cannot be revived afterwards.
		// BLT_Family_Updates::apply() below then holds automatic checks to
		// one a day and anchors them to midnight site time.
		$checker = PucFactory::buildUpdateChecker( $repo_url, BLT_SCE_FILE, self::SLUG, 24 );
		$checker->setBranch( $branch );

		// A GitHub personal access token with read access to this private
		// repo. Server-side only; never sent to the browser.
		$token = defined( 'BLT_SCE_GITHUB_TOKEN' ) && BLT_SCE_GITHUB_TOKEN ? (string) BLT_SCE_GITHUB_TOKEN : '';

		// Shared-store fallback, consulted only once the wp-config constant
		// has come up empty — precedence stays constant -> shared store, and
		// nothing here writes back to this plugin's own options.
		//
		// This is opt-in for a reason: BLT_Family::get() returns nothing
		// unless the site owner ticked this plugin for the `github` group on
		// the BLT screen, so installing a second BLT plugin can never
		// silently change which credentials this plugin authenticates with.
		if ( '' === $token && class_exists( 'BLT_Family' ) ) {
			$token = (string) \BLT_Family::get( self::SLUG, 'github', 'token' );
		}

		if ( '' !== $token ) {
			$checker->setAuthentication( $token );
		}

		// Pull the zip from a GitHub Release asset (our release workflow
		// publishes one) rather than a raw branch zip.
		$checker->getVcsApi()->enableReleaseAssets();

		// The family update policy: at most one automatic check a day,
		// anchored to 00:00 site time, manual checks always immediate, and
		// the BLT mark on this plugin's card in the update screens.
		\BLT_Family_Updates::apply(
			$checker,
			array(
				'basename'  => BLT_SCE_BASENAME,
				'icons_url' => BLT_SCE_URL . 'assets/img/',
			)
		);

		self::$checker = $checker;
	}

	/**
	 * The built checker, or null if it was never built (library missing, or
	 * a front-end request — init() only runs in wp-admin).
	 *
	 * @return object|null
	 */
	public static function checker() {
		return self::$checker;
	}
}
