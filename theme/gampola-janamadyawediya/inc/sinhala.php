<?php
/**
 * Date & time helpers.
 *
 * The site is Sinhala-only, so every string here is Sinhala. The date helpers
 * are the bulk of it: Sinhala needs its own weekday and month tables.
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
 * Full Sinhala date, e.g. "ඉරිදා, 16 අගෝස්තු 2026".
 *
 * @param int  $timestamp Unix timestamp.
 * @param bool $with_day  Include the weekday name.
 * @return string
 */
function gjn_sinhala_date( $timestamp, $with_day = true ) {
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
 * Not built on human_time_diff(): that measures against time() rather than the
 * current_time() clock used here, and it switches to weeks and months past
 * seven days, which would disagree with the wording below.
 *
 * @param int $timestamp Unix timestamp in the site timezone.
 * @return string
 */
function gjn_relative_time( $timestamp ) {
	$diff = current_time( 'timestamp' ) - $timestamp;

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
