<?php
/**
 * Sinhala date & time helpers.
 *
 * Plain-PHP implementations — no intl extension dependency
 * (intl is not bundled in the wordpress:php8.3-fpm-alpine image).
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
