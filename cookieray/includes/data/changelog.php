<?php
/**
 * CookieRay changelog — keyed by version (newest first).
 *
 * Add a new top-level entry on every release. The notifications bell in the
 * admin topbar reads this file and shows entries newer than the user's last
 * acknowledged version.
 *
 * @package CookieRay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'1.0.2' => array(
		'date'  => '2026-08-23',
		'items' => array(
			'Added a Text Color control for the consent banner.',
			'Made cookie category labels and descriptions editable for the consent banner.',
			'Added a custom day-count option for consent expiration and log retention.',
			'Fixed the Dashboard compliance score and checklist not refreshing after saving Pro settings.',
			'Tested compatibility with the latest version of WordPress.',
		),
	),
	'1.0.1' => array(
		'date'  => '2026-06-08',
		'items' => array(
			'Added compatibility with the Pro version.',
			'Fixed minor issues to ensure the plugin works properly.',
		),
	),
	'1.0.0' => array(
		'date'  => '2026-04-12',
		'items' => array(
			'Initial release.',
		),
	),
);
