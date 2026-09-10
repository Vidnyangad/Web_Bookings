<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APT_WooCommerce {

    public function __construct() {
        // AJAX Endpoints
        add_action( 'wp_ajax_apt_check_availability', array( $this, 'ajax_check_availability' ) );
        add_action( 'wp_ajax_nopriv_apt_check_availability', array( $this, 'ajax_check_availability' ) );

        add_action( 'wp_ajax_apt_book_tickets', array( $this, 'ajax_book_tickets' ) );
        add_action( 'wp_ajax_nopriv_apt_book_tickets', array( $this, 'ajax_book_tickets' ) );

        // WooCommerce Cart & Checkout Hooks
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
        add_filter( 'woocommerce_get_item_data', array( $this, 'get_item_data' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_meta' ), 10, 4 );

        // Validation at checkout
        add_action( 'woocommerce_check_cart_items', array( $this, 'validate_checkout' ) );

        // Handle completed orders (remove hold, commit inventory)
        add_action( 'woocommerce_payment_complete', array( $this, 'order_payment_complete' ), 5 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'order_payment_complete' ), 5 );
        add_action( 'woocommerce_order_status_processing', array( $this, 'order_payment_complete' ), 5 );

        // Handle failed/cancelled orders (release holds if any lingering)
        add_action( 'woocommerce_order_status_cancelled', array( $this, 'order_cancelled' ) );
        add_action( 'woocommerce_order_status_failed', array( $this, 'order_cancelled' ) );
    }

    /**
     * Returns the configured Adult/Child/Infant variation IDs, e.g.
     * array('adult' => 123, 'child' => 124, 'infant' => 125).
     * Only IDs that are actually children of $product are included, so a
     * stale setting (e.g. after the product was rebuilt) can't silently
     * point at the wrong variation.
     */
    private function get_variation_map( $product ) {
        $valid_children = $product ? array_flip( $product->get_children() ) : array();
        $configured = array(
            'adult'  => intval( get_option( 'apt_variation_id_adult' ) ),
            'child'  => intval( get_option( 'apt_variation_id_child' ) ),
            'infant' => intval( get_option( 'apt_variation_id_infant' ) ),
        );
        $var_map = array();
        foreach ( $configured as $type => $var_id ) {
            if ( $var_id && isset( $valid_children[ $var_id ] ) ) {
                $var_map[ $type ] = $var_id;
            }
        }
        return $var_map;
    }

    /**
     * Given an order/cart line's variation ID, returns 'adult', 'child', 'infant',
     * or null, based on the configured variation IDs (not attribute text guessing).
     */
    private function get_ticket_type_for_variation( $variation_id ) {
        $product_id = get_option( 'apt_wc_product_id' );
        $product    = $product_id ? wc_get_product( $product_id ) : false;
        $var_map    = $this->get_variation_map( $product );
        $type       = array_search( intval( $variation_id ), $var_map, true );
        return $type ?: null;
    }

    /**
     * Per-slot booking cutoff times (site timezone), for the current day only.
     * Future dates are always bookable regardless of time; past dates never are.
     */
    private function get_slot_cutoff_time( $slot ) {
        $cutoffs = array(
            'morning'   => '11:30:00',
            'afternoon' => '15:00:00',
            'evening'   => '17:00:00',
        );
        return isset( $cutoffs[ $slot ] ) ? $cutoffs[ $slot ] : '00:00:00';
    }

    /**
     * Whether a given date+slot combination can still be booked online.
     * - Past dates: never bookable.
     * - Future dates: always bookable.
     * - Today: bookable only until that slot's cutoff time.
     */
    private function is_slot_bookable( $date, $slot ) {
        $today = current_time( 'Y-m-d' );

        if ( $date > $today ) {
            return true;
        }
        if ( $date < $today ) {
            return false;
        }

        // Same day: compare current site time against this slot's cutoff.
        $now = current_time( 'H:i:s' );
        return $now < $this->get_slot_cutoff_time( $slot );
    }

    private function get_availability( $date ) {
        global $wpdb;

        $capacities = array(
            'morning' => intval(get_option('apt_capacity_morning', 100)),
            'afternoon' => intval(get_option('apt_capacity_afternoon', 100)),
            'evening' => intval(get_option('apt_capacity_evening', 100))
        );

        $table_inventory = $wpdb->prefix . 'apt_inventory';
        $overrides = $wpdb->get_results( $wpdb->prepare(
            "SELECT time_slot, adult_capacity, adult_booked FROM $table_inventory WHERE visit_date = %s",
            $date
        ) );

        $booked = array('morning' => 0, 'afternoon' => 0, 'evening' => 0);

        foreach ( $overrides as $row ) {
            if ( $row->adult_capacity !== null ) {
                $capacities[$row->time_slot] = intval($row->adult_capacity);
            }
            $booked[$row->time_slot] = intval($row->adult_booked);
        }

        // Check holds
        $table_holds = $wpdb->prefix . 'apt_holds';
        // Cleanup expired holds first
        $wpdb->query("DELETE FROM $table_holds WHERE expires_at < NOW()");

        $holds = $wpdb->get_results( $wpdb->prepare(
            "SELECT time_slot, SUM(adult_quantity) as held FROM $table_holds WHERE visit_date = %s GROUP BY time_slot",
            $date
        ) );

        foreach ( $holds as $hold ) {
            $booked[$hold->time_slot] += intval($hold->held);
        }

        $availability = array();
        foreach ( array('morning', 'afternoon', 'evening') as $slot ) {
            $available = max(0, $capacities[$slot] - $booked[$slot]);
            $availability[$slot] = array(
                'capacity' => $capacities[$slot],
                'booked' => $booked[$slot],
                'available' => $available
            );
        }

        return $availability;
    }

    public function ajax_check_availability() {
        while ( ob_get_level() > 0 ) { ob_end_clean(); } // Aggressively clear all buffers to prevent JSON corruption
        @ini_set( 'display_errors', 0 ); // Prevent shutdown hooks from appending notices to our JSON
        check_ajax_referer( 'apt_frontend_nonce', 'nonce' );

        $date = sanitize_text_field( $_POST['date'] );
        if ( empty($date) ) {
            remove_all_actions( 'shutdown' );
            wp_send_json_error( 'Invalid date.' );
        }

        try {
            $availability = $this->get_availability( $date );

            // Flag any slot whose same-day cutoff has already passed. Kept separate
            // from 'available' (which only tracks adult capacity) so a cutoff-closed
            // slot is disabled outright, even for child/infant-only bookings.
            foreach ( array( 'morning', 'afternoon', 'evening' ) as $slot ) {
                $availability[$slot]['bookable'] = $this->is_slot_bookable( $date, $slot );
            }

            remove_all_actions( 'shutdown' );
            wp_send_json_success( $availability );
        } catch ( \Throwable $e ) {
            // Log the real error so it survives even if the underlying data
            // that triggered it gets cleaned up afterward.
            error_log( 'APT ajax_check_availability error for date ' . $date . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );

            $message = 'Error checking availability. Please try again.';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $message .= ' (' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
            }
            remove_all_actions( 'shutdown' );
            wp_send_json_error( $message );
        }
    }

    public function ajax_book_tickets() {
        while ( ob_get_level() > 0 ) { ob_end_clean(); } // Aggressively clear all buffers to prevent JSON corruption
        @ini_set( 'display_errors', 0 ); // Prevent shutdown hooks from appending notices to our JSON
        check_ajax_referer( 'apt_frontend_nonce', 'nonce' );

        $date = sanitize_text_field( $_POST['date'] );
        $slot = sanitize_text_field( $_POST['slot'] );
        $adults = intval( $_POST['adults'] );
        $children = intval( $_POST['children'] );
        $infants = intval( $_POST['infants'] );

        if ( empty($date) || empty($slot) ) {
            remove_all_actions( 'shutdown' );
            wp_send_json_error( 'Please select a date and time slot.' );
        }
        if ( ! $this->is_slot_bookable( $date, $slot ) ) {
            remove_all_actions( 'shutdown' );
            wp_send_json_error( 'Booking for the ' . ucfirst( $slot ) . ' slot on this date is closed.' );
        }

        if ( $adults > 0 ) {
            $availability = $this->get_availability( $date );
            if ( $availability[$slot]['available'] < $adults ) {
                remove_all_actions( 'shutdown' );
                wp_send_json_error( 'Not enough adult tickets available for this time slot.' );
            }

            global $wpdb;
            $table_holds = $wpdb->prefix . 'apt_holds';

            // Create a 5-minute hold for Adult tickets
            $session_id = WC()->session->get_customer_id();
            if ( empty($session_id) ) {
                WC()->session->set_customer_session_cookie(true);
                $session_id = WC()->session->get_customer_id();
            }

            $wpdb->insert(
                $table_holds,
                array(
                    'session_id' => $session_id,
                    'visit_date' => $date,
                    'time_slot' => $slot,
                    'adult_quantity' => $adults,
                    'expires_at' => current_time('mysql', 1) // Using UTC + 5 mins would be better, but doing generic +5m in query below
                )
            );

            // Fix expiration properly
            $wpdb->query( $wpdb->prepare(
                "UPDATE $table_holds SET expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = %d",
                $wpdb->insert_id
            ));
        }

        $product_id = get_option('apt_wc_product_id');
        if ( !$product_id ) {
            remove_all_actions( 'shutdown' );
            wp_send_json_error( 'Ticketing system is not configured.' );
        }

        // Find variations
        $product = wc_get_product( $product_id );
        if ( !$product || !$product->is_type('variable') ) {
            remove_all_actions( 'shutdown' );
            wp_send_json_error( 'Configured product is not variable.' );
        }

        // Variation IDs are explicitly configured in the admin settings rather than
        // guessed from attribute text, which is a more reliable mapping.
        $var_map = $this->get_variation_map( $product );

        // Clear cart first if you only want 1 booking at a time, but assuming add to cart
        $cart_item_data = array(
            'apt_date' => $date,
            'apt_slot' => $slot
        );

        $added_any = false;

        if ( $adults > 0 ) {
            if ( isset($var_map['adult']) ) {
                WC()->cart->add_to_cart( $product_id, $adults, $var_map['adult'], array(), $cart_item_data );
                $added_any = true;
            } else {
                remove_all_actions( 'shutdown' );
                wp_send_json_error( 'Could not find the Adult ticket variation. Please check the product configuration.' );
            }
        }
        if ( $children > 0 ) {
            if ( isset($var_map['child']) ) {
                WC()->cart->add_to_cart( $product_id, $children, $var_map['child'], array(), $cart_item_data );
                $added_any = true;
            } else {
                remove_all_actions( 'shutdown' );
                wp_send_json_error( 'Could not find the Child (6-12) ticket variation. Please check the product configuration.' );
            }
        }
        if ( $infants > 0 ) {
            if ( isset($var_map['infant']) ) {
                WC()->cart->add_to_cart( $product_id, $infants, $var_map['infant'], array(), $cart_item_data );
                $added_any = true;
            } else {
                remove_all_actions( 'shutdown' );
                wp_send_json_error( 'Could not find the Child (Under 6) ticket variation. Please check the product configuration.' );
            }
        }

        if ( ! $added_any ) {
            remove_all_actions( 'shutdown' );
            wp_send_json_error( 'No tickets were added to the cart. Please select at least one ticket.' );
        }

        remove_all_actions( 'shutdown' );
        wp_send_json_success( array( 'checkout_url' => wc_get_checkout_url() ) );
    }

    public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
        if ( isset( $_POST['apt_date'] ) ) {
            $cart_item_data['apt_date'] = sanitize_text_field( $_POST['apt_date'] );
            $cart_item_data['apt_slot'] = sanitize_text_field( $_POST['apt_slot'] );
        }
        return $cart_item_data;
    }

    public function get_item_data( $item_data, $cart_item ) {
        if ( isset( $cart_item['apt_date'] ) ) {
            $item_data[] = array(
                'key'   => 'Visit Date',
                'value' => $cart_item['apt_date']
            );
            $item_data[] = array(
                'key'   => 'Time Slot',
                'value' => ucfirst($cart_item['apt_slot'])
            );
        }
        return $item_data;
    }

    public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( isset( $values['apt_date'] ) ) {
            $item->add_meta_data( 'Visit Date', $values['apt_date'] );
            $item->add_meta_data( 'Time Slot', ucfirst($values['apt_slot']) );
            // Store internal meta for processing later
            $item->add_meta_data( '_apt_date', $values['apt_date'] );
            $item->add_meta_data( '_apt_slot', $values['apt_slot'] );
        }
    }

    public function validate_checkout() {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

        $requested = array(); // [date][slot] = adults

        // Sum up required adult tickets by date and slot
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( isset( $cart_item['apt_date'] ) && isset( $cart_item['apt_slot'] ) ) {
                $date = $cart_item['apt_date'];
                $slot = $cart_item['apt_slot'];

                $qty = $cart_item['quantity'];
                $variation_id = isset( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : 0;
                $is_adult = ( 'adult' === $this->get_ticket_type_for_variation( $variation_id ) );

                if ( $is_adult ) {
                    if ( !isset($requested[$date]) ) $requested[$date] = array();
                    if ( !isset($requested[$date][$slot]) ) $requested[$date][$slot] = 0;
                    $requested[$date][$slot] += $qty;
                }
            }
        }

        // Check availability (but ignore OUR OWN holds for this session)
        $session_id = WC()->session->get_customer_id();
        if ( empty($session_id) && isset($_COOKIE['wp_woocommerce_session_'.COOKIEHASH]) ) {
            list( $session_id ) = explode( '||', $_COOKIE['wp_woocommerce_session_'.COOKIEHASH] );
        }

        global $wpdb;
        $table_holds = $wpdb->prefix . 'apt_holds';

        foreach ( $requested as $date => $slots ) {
            $availability = $this->get_availability( $date );

            // Re-add our own held tickets for this date to the available count, since we are the ones holding them
            if ( $session_id ) {
                $our_holds = $wpdb->get_results( $wpdb->prepare(
                    "SELECT time_slot, SUM(adult_quantity) as held FROM $table_holds WHERE visit_date = %s AND session_id = %s GROUP BY time_slot",
                    $date, $session_id
                ) );

                foreach ( $our_holds as $hold ) {
                    $availability[$hold->time_slot]['available'] += intval($hold->held);
                }
            }

            foreach ( $slots as $slot => $qty ) {
                if ( ! $this->is_slot_bookable( $date, $slot ) ) {
                    wc_add_notice( sprintf( 'Sorry, the %s slot on %s is no longer bookable online (the cutoff time has passed). Please remove or update this item.', ucfirst($slot), $date ), 'error' );
                    continue;
                }
                if ( $availability[$slot]['available'] < $qty ) {
                    wc_add_notice( sprintf( 'Sorry, we do not have enough adult tickets available for %s (%s). Please reduce the quantity.', $date, ucfirst($slot) ), 'error' );
                }
            }
        }
    }

    /**
     * Generates a 6-character PNR and guarantees it doesn't already exist in
     * wp_apt_tickets before returning it. Falls back to a longer PNR in the
     * extremely unlikely case the short space is exhausted after many tries.
     */
    private function generate_unique_pnr() {
        global $wpdb;
        $table_tickets = $wpdb->prefix . 'apt_tickets';

        for ( $attempt = 0; $attempt < 20; $attempt++ ) {
            $pnr = strtoupper( substr( wp_hash( uniqid( wp_rand(), true ) ), 0, 6 ) );
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_tickets WHERE pnr = %s", $pnr ) );
            if ( ! $exists ) {
                return $pnr;
            }
        }

        // Extremely unlikely fallback: widen to 10 chars to guarantee uniqueness.
        do {
            $pnr = strtoupper( substr( wp_hash( uniqid( wp_rand(), true ) ), 0, 10 ) );
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_tickets WHERE pnr = %s", $pnr ) );
        } while ( $exists );

        return $pnr;
    }

    public function order_payment_complete( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( !$order || $order->get_meta('_apt_processed') ) {
            return;
        }

        global $wpdb;
        $table_inventory = $wpdb->prefix . 'apt_inventory';
        $table_tickets = $wpdb->prefix . 'apt_tickets';

        $bookings = array(); // date_slot => adult_qty

        // Group items by date and slot
        $ticket_data = array();

        foreach ( $order->get_items() as $item_id => $item ) {
            $date = $item->get_meta('_apt_date');
            $slot = $item->get_meta('_apt_slot');

            if ( $date && $slot ) {
                $key = $date . '|' . $slot;
                if ( !isset($ticket_data[$key]) ) {
                    $ticket_data[$key] = array(
                        'date' => $date,
                        'slot' => $slot,
                        'adults' => 0,
                        'children' => 0,
                        'infants' => 0
                    );
                }

                $qty = $item->get_quantity();
                $variation_id = $item->get_variation_id();
                $ticket_type = $this->get_ticket_type_for_variation( $variation_id );

                if ( 'adult' === $ticket_type ) {
                    $ticket_data[$key]['adults'] += $qty;

                    if ( !isset($bookings[$key]) ) {
                        $bookings[$key] = 0;
                    }
                    $bookings[$key] += $qty;
                } else if ( 'child' === $ticket_type ) {
                    $ticket_data[$key]['children'] += $qty;
                } else if ( 'infant' === $ticket_type ) {
                    $ticket_data[$key]['infants'] += $qty;
                }
            }
        }

        // Commit inventory
        foreach ( $bookings as $key => $qty ) {
            list($date, $slot) = explode('|', $key);

            // Try to update existing
            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE $table_inventory SET adult_booked = adult_booked + %d WHERE visit_date = %s AND time_slot = %s",
                $qty, $date, $slot
            ) );

            // If none existed, insert new
            if ( !$updated ) {
                $wpdb->insert(
                    $table_inventory,
                    array(
                        'visit_date' => $date,
                        'time_slot' => $slot,
                        'adult_booked' => $qty
                    )
                );
            }
        }

        // Create ticket records for each distinct date/slot combination
        $ticket_ids = array();
        foreach ( $ticket_data as $key => $data ) {
            $wpdb->insert(
                $table_tickets,
                array(
                    'order_id' => $order_id,
                    'visit_date' => $data['date'],
                    'time_slot' => $data['slot'],
                    'adult_qty' => $data['adults'],
                    'child_qty' => $data['children'],
                    'infant_qty' => $data['infants'],
                    'pnr' => $this->generate_unique_pnr(),
                    'status' => 'valid'
                )
            );
            $ticket_ids[] = $wpdb->insert_id;
        }

        if ( !empty($ticket_ids) ) {
            $order->update_meta_data('_apt_ticket_ids', $ticket_ids); // Storing multiple IDs

            // Delete holds for this user/session
            $session_id = get_post_meta($order_id, '_customer_user', true);
            if ( empty($session_id) && isset($_COOKIE['wp_woocommerce_session_'.COOKIEHASH]) ) {
                // If guest, try to use the WC session cookie value (it's the first part of the cookie value)
                list( $session_id ) = explode( '||', $_COOKIE['wp_woocommerce_session_'.COOKIEHASH] );
            }
            if ($session_id) {
                $table_holds = $wpdb->prefix . 'apt_holds';
                $wpdb->delete($table_holds, array('session_id' => $session_id));
            }
        }

        $order->update_meta_data('_apt_processed', 'yes');
        $order->save();
    }

    public function order_cancelled( $order_id ) {
        // We could implement hold releasing here if needed, but holds expire in 5 mins anyway
    }
}

new APT_WooCommerce();
