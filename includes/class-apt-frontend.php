<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APT_Frontend {

    public function __construct() {
        add_shortcode( 'amusement_park_booking', array( $this, 'booking_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    public function enqueue_scripts() {
        wp_register_script( 'apt-frontend-js', APT_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), APT_VERSION, true );
        wp_register_style( 'apt-frontend-css', APT_PLUGIN_URL . 'assets/css/frontend.css', array(), APT_VERSION );

        wp_localize_script( 'apt-frontend-js', 'apt_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'apt_frontend_nonce' )
        ) );
    }

    public function booking_shortcode() {
        wp_enqueue_script( 'apt-frontend-js' );
        wp_enqueue_style( 'apt-frontend-css' );

        $product_id = get_option('apt_wc_product_id');
        if ( !$product_id ) {
            return '<p>Ticketing system is currently not configured.</p>';
        }

        ob_start();
        ?>
        <div id="apt-booking-container" class="apt-booking-widget">
            <div id="apt-step-1" class="apt-step active">
                <h3>Step 1: Select Tickets</h3>
                <div class="apt-ticket-row">
                    <label>Adult (Age 13+)</label>
                    <input type="number" id="apt-qty-adult" min="0" value="0" />
                </div>
                <div class="apt-ticket-row">
                    <label>Child (6-12 years)</label>
                    <input type="number" id="apt-qty-child" min="0" value="0" />
                </div>
                <div class="apt-ticket-row">
                    <label>Child (Under 6 years)</label>
                    <input type="number" id="apt-qty-infant" min="0" value="0" />
                </div>
                <button id="apt-btn-next-1" class="apt-btn">Next</button>
            </div>

            <div id="apt-step-2" class="apt-step" style="display:none;">
                <h3>Step 2: Select Date & Time</h3>
                <p>Select a date within the next 2 months (bookings open from tomorrow onwards).</p>
                <input type="date" id="apt-visit-date" min="<?php echo esc_attr( date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +1 day' ) ) ); ?>" max="<?php echo esc_attr( date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +2 months' ) ) ); ?>" />

                <div id="apt-slots-container" style="display:none; margin-top: 15px;">
                    <h4>Select Time Slot</h4>
                    <div class="apt-slot-buttons">
                        <button class="apt-slot-btn" data-slot="morning">Morning</button>
                        <button class="apt-slot-btn" data-slot="afternoon">Afternoon</button>
                        <button class="apt-slot-btn" data-slot="evening">Evening</button>
                    </div>
                </div>

                <div id="apt-availability-message" style="margin-top: 10px;"></div>

                <button id="apt-btn-back-2" class="apt-btn apt-btn-secondary" style="margin-top: 15px;">Back</button>
                <button id="apt-btn-book" class="apt-btn" style="display:none; margin-top: 15px;">Book Tickets</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

new APT_Frontend();
