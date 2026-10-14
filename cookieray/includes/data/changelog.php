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
	'1.0.4' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'Fixed the compliance checklist counting a placeholder "#" privacy policy link as configured.',
			'Fixed the Settings Consent Health card saying all thresholds are met when checklist items need attention.',
			'Renamed the Dashboard "Total Consents" card to "Consents (Last 30 Days)" to match the number it shows.',
			'Fixed license expiry and support dates showing as raw timestamps (Pro).',
			'Tested compatibility with WordPress 7.1 and PHP 8.4.',
		),
	),
	'1.0.3' => array(
		'date'  => '2026-09-30',
		'items' => array(
			'Fixed theme button styles overriding the consent banner and preferences panel buttons, including hover and focus states.',
			'Fixed the Save preferences button losing its background color when no accent color was set.',
		),
	),
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
