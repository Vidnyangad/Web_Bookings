<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APT_Scanner {

    public function __construct() {
        add_shortcode( 'amusement_park_scanner', array( $this, 'scanner_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // AJAX Endpoints for Staff
        add_action( 'wp_ajax_apt_scan_ticket', array( $this, 'ajax_scan_ticket' ) );
        add_action( 'wp_ajax_apt_get_orders_for_date', array( $this, 'ajax_get_orders_for_date' ) );
        add_action( 'wp_ajax_apt_get_calendar_events', array( $this, 'ajax_get_calendar_events' ) );
    }

    /**
     * Builds the FullCalendar event array from current inventory data.
     * Shared by the initial page render and the AJAX refresh endpoint so
     * both always reflect the exact same, freshly-queried data.
     */
    private function get_calendar_events() {
        global $wpdb;
        $table_inventory = $wpdb->prefix . 'apt_inventory';
        $inventory_data = $wpdb->get_results( "SELECT * FROM $table_inventory" );

        $events = array();
        foreach ( $inventory_data as $row ) {
            $events[] = array(
                'title' => ucfirst($row->time_slot) . ': ' . $row->adult_booked . ' booked',
                'start' => $row->visit_date,
                'color' => ($row->time_slot == 'morning') ? '#ff9f89' : (($row->time_slot == 'afternoon') ? '#89c4ff' : '#8c8c8c'),
                'allDay' => true
            );
        }
        return $events;
    }

    public function enqueue_scripts() {
        // Enqueue HTML5-QRCode library only when needed (we'll load it via CDN for simplicity, or we can download it)

        // FullCalendar
        wp_register_script( 'fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js', array(), null, true );

        wp_register_script( 'apt-scanner-js', APT_PLUGIN_URL . 'assets/js/scanner.js', array('jquery', 'fullcalendar'), APT_VERSION, true );

        wp_localize_script( 'apt-scanner-js', 'apt_scanner_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'apt_scanner_nonce' )
        ) );
    }

    public function scanner_shortcode() {
        // Only allow logged in users (staff)
        if ( ! is_user_logged_in() || ! current_user_can( 'edit_pages' ) ) {
            return '<p>Please log in to access the scanner.</p>';
        }

        wp_enqueue_script( 'apt-scanner-js' );

        // Fetch inventory data to pass to calendar
        $events = $this->get_calendar_events();

        ob_start();
        ?>
        <style>
            .apt-staff-dashboard {
                display: flex;
                flex-direction: column;
                gap: 30px;
                margin-top: 20px;
            }
            .apt-scanner-wrapper, .apt-calendar-wrapper {
                width: 100%;
                box-sizing: border-box;
                background: #f9f9f9;
                padding: 20px;
                border-radius: 8px;
                border: 1px solid #eee;
            }
            #apt-reader {
                width: 100%;
                max-width: 500px;
                margin: 0 auto;
            }
            .apt-scan-result {
                margin-top: 20px;
                padding: 15px;
                border-radius: 5px;
                display: none;
            }
            .apt-scan-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
            .apt-scan-error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
            #apt-calendar {
                background: #fff;
                padding: 10px;
                border-radius: 5px;
            }
            .fc-event { font-size: 0.85em; padding: 2px; }
            .apt-tabs { display: flex; border-bottom: 1px solid #ccc; margin-bottom: 10px; }
            .apt-tab-btn { background: #eee; border: 1px solid #ccc; border-bottom: none; padding: 8px 16px; cursor: pointer; }
            .apt-tab-btn.active { background: #fff; border-top: 2px solid #0073aa; font-weight: bold; }
            .apt-orders-table-wrapper { width: 100%; overflow-x: auto; }
            .apt-orders-table { width: 100%; min-width: 700px; table-layout: fixed; border-collapse: collapse; }
            .apt-orders-table th, .apt-orders-table td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
                word-break: break-word;
                overflow-wrap: break-word;
            }
            .apt-orders-table th { background: #f2f2f2; }
            .apt-orders-table th:nth-child(6), .apt-orders-table th:nth-child(7),
            .apt-orders-table td:nth-child(6), .apt-orders-table td:nth-child(7) {
                min-width: 140px;
            }
        </style>

        <script>
            var aptCalendarEvents = <?php echo json_encode($events); ?>;
        </script>

        <div class="apt-staff-dashboard">
            <div class="apt-scanner-wrapper">
                <h2>Gate Check-in (PNR)</h2>
                <div style="margin-bottom: 15px;">
                    <input type="text" id="apt-pnr-input" placeholder="Enter 6-char PNR" style="padding: 10px; font-size: 16px; text-transform: uppercase; width: 100%; max-width: 250px;" maxlength="6">
                    <button id="apt-btn-check-pnr" class="button button-primary" style="padding: 10px 15px; font-size: 16px; margin-left: 10px; cursor: pointer;">Check-in</button>
                </div>

                <div id="apt-scan-result" class="apt-scan-result"></div>

                <button id="apt-btn-scan-again" class="apt-btn" style="display:none; margin-top:15px;">Check Next Ticket</button>
            </div>

            <div class="apt-calendar-wrapper">
                <h2>Reservations Calendar
                    <button id="apt-btn-refresh-calendar" class="button" type="button" style="margin-left:10px; vertical-align:middle;">Refresh Calendar</button>
                </h2>
                <div id="apt-calendar"></div>

                <div id="apt-order-details" style="display:none; margin-top:20px;">
                    <h3>Orders for <span id="apt-selected-date"></span></h3>
                    <div class="apt-tabs">
                        <button class="apt-tab-btn active" data-tab="morning">Morning</button>
                        <button class="apt-tab-btn" data-tab="afternoon">Afternoon</button>
                        <button class="apt-tab-btn" data-tab="evening">Evening</button>
                    </div>
                    <div class="apt-tab-content active" id="tab-morning"></div>
                    <div class="apt-tab-content" id="tab-afternoon" style="display:none;"></div>
                    <div class="apt-tab-content" id="tab-evening" style="display:none;"></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax_scan_ticket() {
        while ( ob_get_level() > 0 ) { ob_end_clean(); } // Aggressively clear all buffers to prevent JSON corruption
        @ini_set( 'display_errors', 0 ); // Prevent shutdown hooks from appending notices to our JSON
        check_ajax_referer( 'apt_scanner_nonce', 'nonce' );

        // Ensure user is logged in
        if ( ! is_user_logged_in() || ! current_user_can( 'edit_pages' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $pnr = isset($_POST['pnr']) ? sanitize_text_field(strtoupper($_POST['pnr'])) : '';
        if ( empty($pnr) ) {
            wp_send_json_error( 'No PNR received.' );
        }

        global $wpdb;
        $table_tickets = $wpdb->prefix . 'apt_tickets';

        $ticket = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $table_tickets WHERE pnr = %s", $pnr) );

        if ( !$ticket ) {
            wp_send_json_error( 'Ticket not found in database for PNR: ' . esc_html($pnr) );
        }

        if ( $ticket->status === 'used' ) {
            wp_send_json_error( 'Ticket has already been USED on ' . date('Y-m-d H:i:s', strtotime($ticket->checkin_time)) );
        }

        // Check if date is today (Optional, but good for real world)
        $today = current_time('Y-m-d');
        if ( $ticket->visit_date !== $today ) {
            // Depending on policy, you might want to allow early/late or reject
            // wp_send_json_error( 'Ticket is valid, but is for a different date: ' . $ticket->visit_date );
        }

        // Mark as used
        $wpdb->update(
            $table_tickets,
            array(
                'status' => 'used',
                'checkin_time' => current_time('mysql')
            ),
            array( 'id' => $ticket->id )
        );

        // Construct success message
        $msg = sprintf(
            '<strong>Valid Ticket! (Order #%d)</strong><br>Date: %s<br>Slot: %s<br>Admits: %d Adult(s), %d Child(ren), %d Infant(s)',
            $ticket->order_id,
            $ticket->visit_date,
            ucfirst($ticket->time_slot),
            $ticket->adult_qty,
            $ticket->child_qty,
            $ticket->infant_qty
        );

        wp_send_json_success( $msg );
    }
    public function ajax_get_orders_for_date() {
        while ( ob_get_level() > 0 ) { ob_end_clean(); } // Aggressively clear all buffers to prevent JSON corruption
        @ini_set( 'display_errors', 0 ); // Prevent shutdown hooks from appending notices to our JSON
        check_ajax_referer( 'apt_scanner_nonce', 'nonce' );

        if ( ! is_user_logged_in() || ! current_user_can( 'edit_pages' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        if ( empty($date) ) {
            wp_send_json_error( 'No date provided.' );
        }

        global $wpdb;
        $table_tickets = $wpdb->prefix . 'apt_tickets';

        $tickets = $wpdb->get_results( $wpdb->prepare("SELECT * FROM $table_tickets WHERE visit_date = %s", $date) );

        $results = array(
            'morning' => array(),
            'afternoon' => array(),
            'evening' => array()
        );

        foreach ( $tickets as $ticket ) {
            $order = wc_get_order( $ticket->order_id );
            if ( ! $order ) continue;

            $slot = strtolower($ticket->time_slot);
            if ( ! isset($results[$slot]) ) {
                $results[$slot] = array();
            }

            $results[$slot][] = array(
                'order_no' => esc_html($order->get_order_number()),
                'adults' => $ticket->adult_qty,
                'child_6_12' => $ticket->child_qty,
                'child_under_6' => $ticket->infant_qty,
                'phone' => esc_html($order->get_billing_phone()),
                'email' => esc_html($order->get_billing_email())
                ,'pnr' => esc_html($ticket->pnr)
                ,'status' => esc_html($ticket->status)
            );
        }

        wp_send_json_success( $results );
    }
    public function ajax_get_calendar_events() {
        while ( ob_get_level() > 0 ) { ob_end_clean(); } // Aggressively clear all buffers to prevent JSON corruption
        @ini_set( 'display_errors', 0 ); // Prevent shutdown hooks from appending notices to our JSON
        check_ajax_referer( 'apt_scanner_nonce', 'nonce' );

        if ( ! is_user_logged_in() || ! current_user_can( 'edit_pages' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        wp_send_json_success( $this->get_calendar_events() );
    }
}
new APT_Scanner();
