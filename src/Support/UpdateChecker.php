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

		$checker = PucFactory::buildUpdateChecker( $repo_url, BLT_SCE_FILE, 'blt-surecart-extensions' );
		$checker->setBranch( $branch );

		// A GitHub personal access token with read access to this private
		// repo. Server-side only, wp-config constant — never stored in
		// the database and never sent to the browser, matching the
		// Shippo token's own handling.
		if ( defined( 'BLT_SCE_GITHUB_TOKEN' ) && BLT_SCE_GITHUB_TOKEN ) {
			$checker->setAuthentication( BLT_SCE_GITHUB_TOKEN );
		}

		// Pull the zip from a GitHub Release asset (our release workflow
		// publishes one) rather than a raw branch zip.
		$checker->getVcsApi()->enableReleaseAssets();
	}
}
