<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APT_Tickets {

    public function __construct() {
        // Hook into WooCommerce emails to display ticket details with PNR
        add_action( 'woocommerce_email_after_order_table', array( $this, 'add_ticket_to_email' ), 10, 4 );
        add_filter( 'woocommerce_email_attachments', array( $this, 'attach_barcodes_to_email' ), 10, 3 );
    }

    public function add_ticket_to_email( $order, $sent_to_admin, $plain_text, $email ) {
        if ( $sent_to_admin || $plain_text || !is_object($order) ) return;

        global $wpdb;
        $table_tickets = $wpdb->prefix . 'apt_tickets';

        $order_id = $order->get_id();
        $tickets = $wpdb->get_results( $wpdb->prepare("SELECT * FROM $table_tickets WHERE order_id = %d", $order_id) );

        if ( empty($tickets) ) return;

        echo '<h2>Your Amusement Park Tickets</h2>';
        foreach ( $tickets as $ticket ) {
            echo '<div style="border: 1px solid #e5e5e5; padding: 20px; text-align: center; margin-bottom: 20px;">';
            echo '<h3 style="margin-top: 0;">PNR: ' . esc_html($ticket->pnr) . '</h3>';
            echo '<p><strong>Date of Visit:</strong> ' . esc_html($ticket->visit_date) . '</p>';
            echo '<p><strong>Time Slot:</strong> ' . esc_html(ucfirst($ticket->time_slot)) . '</p>';
            echo '<p><strong>Tickets:</strong> ' . esc_html($ticket->adult_qty) . ' Adult, ' . esc_html($ticket->child_qty) . ' Child (6-12), ' . esc_html($ticket->infant_qty) . ' Infant (Under 6)</p>';
            echo '<p>Please present the attached barcode (or PNR) at the gate for entry.</p>';
            echo '</div>';
        }
    }

    public function attach_barcodes_to_email( $attachments, $status, $order ) {
        if ( !is_object($order) ) return $attachments;

        $allowed_statuses = array('customer_completed_order', 'customer_invoice', 'customer_processing_order');

        if ( isset($status) && in_array($status, $allowed_statuses) ) {
            global $wpdb;
            $table_tickets = $wpdb->prefix . 'apt_tickets';

            $order_id = $order->get_id();
            $tickets = $wpdb->get_results( $wpdb->prepare("SELECT pnr FROM $table_tickets WHERE order_id = %d", $order_id) );

            if ( !empty($tickets) && class_exists( 'APT_Barcode' ) ) {
                foreach ( $tickets as $ticket ) {
                    if ( !empty($ticket->pnr) ) {
                        $filepath = APT_Barcode::generate_barcode_file( $ticket->pnr );
                        if ( $filepath && file_exists($filepath) ) {
                            $attachments[] = $filepath;
                        }
                    }
                }
            }
        }

        return $attachments;
    }
}

new APT_Tickets();
