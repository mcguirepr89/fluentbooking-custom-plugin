<?php
/**
 * Plugin Name: FluentBooking Child Capacity Limit
 * Description: Enforces max children per group event using booking questions
 * Version: 1.3.1
 */

if (!defined('ABSPATH')) {
    exit;
}

/* =========================
 * CONFIG
 * ========================= */
const FB_CHILD_LIMIT = 10;
const FB_LIMITED_EVENT_IDS = [8, 12]; // Event IDs to enforce limit on

/* =========================
 * DEBUG LOGGER
 * ========================= */
function fb_child_limit_debug($label, $data = null) {
    error_log('FB CHILD LIMIT :: ' . $label);
    if ($data !== null) {
        error_log(print_r($data, true));
    }
}

/* =========================
 * CHILD COUNT EXTRACTION
 * ========================= */
function fb_child_limit_get_children_count(array $meta): int {

    foreach ($meta as $key => $value) {

        // Match the numeric "number of children" field by key
        if (stripos($key, 'number_of_children') !== false) {

            fb_child_limit_debug('MATCHED CHILD FIELD KEY', $key);
            fb_child_limit_debug('RAW CHILD FIELD VALUE', $value);

            return max(0, (int) $value);
        }
    }

    fb_child_limit_debug('NO CHILD FIELD FOUND');
    return 0;
}

/* =========================
 * FETCH EXISTING BOOKINGS
 * ========================= */
function fb_child_limit_get_overlapping_bookings($booking) {

    global $wpdb;

    return $wpdb->get_results($wpdb->prepare(
        "
        SELECT id
        FROM {$wpdb->prefix}fcal_bookings
        WHERE event_id = %d
          AND start_time = %s
          AND status IN ('scheduled','confirmed')
          AND id != %d
        ",
        (int) $booking->event_id,
        $booking->start_time,
        (int) $booking->id
    ));
}

/* =========================
 * ENFORCEMENT
 * ========================= */
add_action('fluent_booking/after_booking_meta_update', function ($booking) {

    fb_child_limit_debug('HOOK FIRED');
    fb_child_limit_debug('BOOKING ID', $booking->id);
    fb_child_limit_debug('EVENT ID', $booking->event_id);
    fb_child_limit_debug('START TIME', $booking->start_time);
    fb_child_limit_debug('STATUS (initial)', $booking->status);

    /* ---- Scope enforcement ---- */
    if (
        $booking->event_type !== 'group' ||
        !in_array((int) $booking->event_id, FB_LIMITED_EVENT_IDS, true)
    ) {
        return;
    }

    /* ---- Children for current booking ---- */
    $meta = (array) $booking->getMeta('custom_fields_data', []);
    fb_child_limit_debug('CUSTOM FIELDS DATA', $meta);

    $children_this_booking = fb_child_limit_get_children_count($meta);
    fb_child_limit_debug('CHILDREN THIS BOOKING', $children_this_booking);

    if ($children_this_booking <= 0) {
        return;
    }

    /* ---- Sum existing children ---- */
    $total_children = $children_this_booking;

    $rows = fb_child_limit_get_overlapping_bookings($booking);
    fb_child_limit_debug('OVERLAPPING BOOKINGS (excluding current)', $rows);

    foreach ($rows as $row) {

        $existing_booking = \FluentBooking\App\Models\Booking::find($row->id);
        if (!$existing_booking) {
            continue;
        }

        $existing_meta = (array) $existing_booking->getMeta('custom_fields_data', []);
        $existing_children = fb_child_limit_get_children_count($existing_meta);

        fb_child_limit_debug('EXISTING BOOKING ID', $row->id);
        fb_child_limit_debug('EXISTING CHILDREN', $existing_children);

        $total_children += $existing_children;
    }

    fb_child_limit_debug('TOTAL CHILDREN FINAL', $total_children);
    fb_child_limit_debug('CHILD LIMIT', FB_CHILD_LIMIT);

    /* ---- Enforce ---- */
    if ($total_children > FB_CHILD_LIMIT) {
    
        fb_child_limit_debug('LIMIT EXCEEDED — CANCELLING BOOKING');
    
        $already_booked = $total_children - $children_this_booking;
        $remaining = max(0, FB_CHILD_LIMIT - $already_booked);
    
        // Cancel booking (hard enforcement)
        $booking->status = 'cancelled';
        $booking->save();
    
        // Send frontend-friendly error
        wp_send_json_error([
            'message' => sprintf(
                __(
                    'This event can accommodate up to %d children. %d spot(s) remain, but you attempted to register %d child(ren). Please adjust the number or choose another date.',
                    'fluent-booking'
                ),
                FB_CHILD_LIMIT,
                $remaining,
                $children_this_booking
	    ),
        ], 400);
    }


}, 10, 1);

add_action('fluent_booking/before_booking', function ($bookingData) {

    // Scope: only target group events we care about
    if (
        empty($bookingData['event_type']) ||
        $bookingData['event_type'] !== 'group' ||
        empty($bookingData['event_id']) ||
        !in_array((int) $bookingData['event_id'], FB_LIMITED_EVENT_IDS, true)
    ) {
        return;
    }

    if (empty($bookingData['start_time'])) {
        return;
    }

    global $wpdb;

    /* ---- Fetch existing bookings for this slot ---- */
    $rows = $wpdb->get_results($wpdb->prepare(
        "
        SELECT id
        FROM {$wpdb->prefix}fcal_bookings
        WHERE event_id = %d
          AND start_time = %s
          AND status IN ('scheduled','confirmed')
        ",
        (int) $bookingData['event_id'],
        $bookingData['start_time']
    ));

    $totalChildren = 0;

    foreach ($rows as $row) {
        $existingBooking = \FluentBooking\App\Models\Booking::find($row->id);
        if (!$existingBooking) {
            continue;
        }

        $meta = $existingBooking->getMeta('custom_fields_data', []);
        $totalChildren += fb_child_limit_get_children_count($meta);
    }

    /* ---- Enforce hard capacity ---- */
    if ($totalChildren >= FB_CHILD_LIMIT) {

        wp_send_json_error([
            'message' => __(
                'This event has reached its maximum number of children and is now fully booked.',
                'fluent-booking'
            )
        ], 400);
    }

}, 10, 1);
