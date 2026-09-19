<?php
/**
 * Language, date & time helpers.
 *
 * The site is Sinhala-first with English translations under /en/, so every
 * language-specific string in the theme routes through gjn_l10n(). The date
 * helpers below are the biggest consumer: Sinhala needs its own weekday and
 * month tables, English does not.
 *
 * Plain-PHP implementations — no intl extension dependency
 * (intl is not bundled in the wordpress:php8.5-fpm-alpine image).
 * All timestamps use wp_date(), so the site timezone setting is respected.
 *
 * @package Gampola_Janamadyawediya
 * @since   3.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * True while rendering the English edition.
 *
 * English is the only translation that exists, so this one check drives every
 * chrome string in the theme. Polylang is already loaded by the time templates
 * run — gjn_language_attributes() in functions.php depends on the same call at
 * an earlier point.
 *
 * @return bool
 */
function gjn_is_english() {
	return function_exists( 'pll_current_language' ) && 'en' === pll_current_language( 'slug' );
}

/**
 * Picks between the Sinhala and English wording of a UI string.
 *
 * Both halves are written at the call site rather than registered as a
 * Polylang string, so the pair reads together in the template and an
 * untranslated string-translation table cannot quietly serve Sinhala on /en/.
 *
 * @param string $si Sinhala text — used on / and whenever Polylang is absent.
 * @param string $en English text — used on /en/.
 * @return string
 */
function gjn_l10n( $si, $en ) {
	return gjn_is_english() ? $en : $si;
}

/**
 * Full Sinhala date, e.g. "ඉරිදා, 16 අගෝස්තු 2026".
 *
 * @param int  $timestamp Unix timestamp.
 * @param bool $with_day  Include the weekday name.
 * @return string
 */
function gjn_sinhala_date( $timestamp, $with_day = true ) {
	// English needs no table of its own — the site locale already carries the
	// month and weekday names, so wp_date() formats them directly.
	if ( gjn_is_english() ) {
		return wp_date( $with_day ? 'l, j F Y' : 'j F Y', $timestamp );
	}

	$days = array(
		'ඉරිදා',
		'සඳුදා',
		'අඟහරුවාදා',
		'බදාදා',
		'බ්‍රහස්පතින්දා',
		'සිකුරාදා',
		'සෙනසුරාදා',
	);

	$months = array(
		'ජනවාරි',
		'පෙබරවාරි',
		'මාර්තු',
		'අප්‍රේල්',
		'මැයි',
		'ජූනි',
		'ජූලි',
		'අගෝස්තු',
		'සැප්තැම්බර්',
		'ඔක්තෝබර්',
		'නොවැම්බර්',
		'දෙසැම්බර්',
	);

	$day   = (int) wp_date( 'w', $timestamp ); // 0 = Sunday.
	$date  = (int) wp_date( 'j', $timestamp );
	$month = (int) wp_date( 'n', $timestamp ) - 1;
	$year  = wp_date( 'Y', $timestamp );

	// wp_date() returns false when it cannot use the timestamp, which casts to
	// 0 here and leaves $month at -1 — an undefined index below. Assert so the
	// bad input surfaces in development and CI (zend.assertions is on there),
	// then clamp so a production page still renders a date instead of dying.
	assert( $day >= 0 && $day <= 6 );
	assert( $month >= 0 && $month <= 11 );

	$day   = max( 0, min( 6, $day ) );
	$month = max( 0, min( 11, $month ) );

	if ( $with_day ) {
		return sprintf( '%s, %d %s %s', $days[ $day ], $date, $months[ $month ], $year );
	}
	return sprintf( '%d %s %s', $date, $months[ $month ], $year );
}

/**
 * Relative time in Sinhala, e.g. "මිනිත්තු 15කට පෙර", "පැය 2කට පෙර".
 * Falls back to the full Sinhala date for anything older than 30 days.
 *
 * @param int $timestamp Unix timestamp in the site timezone.
 * @return string
 */
function gjn_relative_time( $timestamp ) {
	$diff = current_time( 'timestamp' ) - $timestamp;

	// The English ladder mirrors the Sinhala one threshold for threshold, so
	// a story reads as the same age in both editions. It is not built on
	// human_time_diff(): that measures against time() rather than the
	// current_time() clock $diff uses, and it switches to weeks and months
	// past seven days, which would disagree with the Sinhala wording.
	if ( gjn_is_english() ) {
		if ( $diff < 60 ) {
			return 'Just now';
		}

		$units = array(
			array( HOUR_IN_SECONDS, MINUTE_IN_SECONDS, 'min' ),
			array( DAY_IN_SECONDS, HOUR_IN_SECONDS, 'hour' ),
			array( 30 * DAY_IN_SECONDS, DAY_IN_SECONDS, 'day' ),
		);
		foreach ( $units as $unit ) {
			if ( $diff < $unit[0] ) {
				$count = max( 1, (int) round( $diff / $unit[1] ) );
				return sprintf( '%d %s%s ago', $count, $unit[2], 1 === $count ? '' : 's' );
			}
		}

		return gjn_sinhala_date( $timestamp, false );
	}

	if ( $diff < 60 ) {
		return 'දැන්ම';
	}
	if ( $diff < HOUR_IN_SECONDS ) {
		$minutes = max( 1, (int) round( $diff / MINUTE_IN_SECONDS ) );
		return sprintf( 'මිනිත්තු %dකට පෙර', $minutes );
	}
	if ( $diff < DAY_IN_SECONDS ) {
		$hours = max( 1, (int) round( $diff / HOUR_IN_SECONDS ) );
		return sprintf( 'පැය %dකට පෙර', $hours );
	}
	if ( $diff < 30 * DAY_IN_SECONDS ) {
		$days = max( 1, (int) round( $diff / DAY_IN_SECONDS ) );
		return sprintf( 'දින %dකට පෙර', $days );
	}

	return gjn_sinhala_date( $timestamp, false );
}
