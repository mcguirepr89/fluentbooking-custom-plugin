<?php
/**
 * Plugin Name: FluentBooking Child Capacity Limit
 * Description: Enforces max children per group event using booking questions
 * Version: 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/* =========================
 * CONFIG
 * ========================= */
define('FB_CHILD_LIMIT', 10);
define('FB_LIMITED_EVENT_IDS', [8]); // Event IDs to enforce limit on
define('FB_CHILD_FIELD_LABEL', 'children'); // match by keyword, not exact text

/* =========================
 * DEBUG LOGGER
 * ========================= */
function fb_debug($label, $data = null) {
    error_log('FB CHILD LIMIT :: ' . $label);
    if ($data !== null) {
        error_log(print_r($data, true));
    }
}

/* =========================
 * CHILD COUNT EXTRACTOR
 * ========================= */
function fb_get_children_count($meta) {
    if (!is_array($meta)) {
        return 0;
    }

    foreach ($meta as $key => $value) {

        // Match field key, not label
        if (stripos($key, 'number_of_children') !== false) {

            fb_debug('MATCHED CHILD FIELD KEY', $key);
            fb_debug('RAW CHILD FIELD VALUE', $value);

            return max(0, intval($value));
        }
    }

    fb_debug('NO CHILD FIELD MATCH FOUND');
    return 0;
}

/* =========================
 * ENFORCEMENT LOGIC
 * ========================= */
add_action('fluent_booking/after_booking_meta_update', function ($booking) {

    fb_debug('HOOK FIRED');
    fb_debug('BOOKING ID', $booking->id);
    fb_debug('EVENT ID', $booking->event_id);
    fb_debug('START TIME', $booking->start_time);
    fb_debug('STATUS (initial)', $booking->status);

    /* ---- Scope enforcement ---- */
    if (
        $booking->event_type !== 'group' ||
        !in_array((int) $booking->event_id, FB_LIMITED_EVENT_IDS, true)
    ) {
        return;
    }

    /* ---- Current booking children ---- */
    $meta = $booking->getMeta('custom_fields_data', []);
    fb_debug('CUSTOM FIELDS DATA', $meta);

    $childrenThisBooking = fb_get_children_count($meta);
    fb_debug('CHILDREN THIS BOOKING', $childrenThisBooking);

    if ($childrenThisBooking <= 0) {
        return;
    }

    global $wpdb;

    /* ---- Fetch OTHER bookings for same slot ---- */
    $rows = $wpdb->get_results($wpdb->prepare(
        "
        SELECT id
        FROM {$wpdb->prefix}fcal_bookings
        WHERE event_id = %d
          AND start_time = %s
          AND status IN ('scheduled','confirmed')
          AND id != %d
        ",
        $booking->event_id,
        $booking->start_time,
        $booking->id
    ));

    fb_debug('OVERLAPPING BOOKINGS (excluding current)', $rows);

    $totalChildren = $childrenThisBooking;

    foreach ($rows as $row) {
        $bBooking = \FluentBooking\App\Models\Booking::find($row->id);
        if (!$bBooking) {
            continue;
        }

        $bMeta = $bBooking->getMeta('custom_fields_data', []);
        $existingChildren = fb_get_children_count($bMeta);

        fb_debug('EXISTING BOOKING ID', $row->id);
        fb_debug('EXISTING CHILDREN', $existingChildren);

        $totalChildren += $existingChildren;
    }

    fb_debug('TOTAL CHILDREN FINAL', $totalChildren);
    fb_debug('CHILD LIMIT', FB_CHILD_LIMIT);

    /* ---- Enforce limit ---- */
    if ($totalChildren > FB_CHILD_LIMIT) {
        fb_debug('LIMIT EXCEEDED — CANCELLING BOOKING');

        $booking->status = 'cancelled';
        $booking->save();

        wp_die(
            __('Sorry, this event has reached the maximum number of children (10).', 'fluent-booking'),
            __('Booking Unavailable', 'fluent-booking'),
            ['response' => 400]
        );
    }

}, 10, 1);
