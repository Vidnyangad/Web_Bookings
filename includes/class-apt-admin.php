<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APT_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_apt_export_reservations_csv', array( $this, 'handle_export_csv' ) );
    }

    public function add_admin_menu() {
        add_menu_page(
            'Amusement Park Ticketing',
            'Amusement Park',
            'manage_options',
            'apt-settings',
            array( $this, 'settings_page_html' ),
            'dashicons-tickets',
            56
        );

        add_submenu_page(
            'apt-settings',
            'Settings',
            'Settings',
            'manage_options',
            'apt-settings',
            array( $this, 'settings_page_html' )
        );

        add_submenu_page(
            'apt-settings',
            'Inventory Overrides',
            'Inventory Overrides',
            'manage_options',
            'apt-inventory',
            array( $this, 'inventory_page_html' )
        );

        add_submenu_page(
            'apt-settings',
            'Manage Reservations',
            'Manage Reservations',
            'manage_options',
            'apt-reservations',
            array( $this, 'reservations_page_html' )
        );
    }

    public function register_settings() {
        register_setting( 'apt_settings_group', 'apt_wc_product_id' );
        register_setting( 'apt_settings_group', 'apt_variation_id_adult' );
        register_setting( 'apt_settings_group', 'apt_variation_id_child' );
        register_setting( 'apt_settings_group', 'apt_variation_id_infant' );
        register_setting( 'apt_settings_group', 'apt_capacity_morning' );
        register_setting( 'apt_settings_group', 'apt_capacity_afternoon' );
        register_setting( 'apt_settings_group', 'apt_capacity_evening' );
    }

    /**
     * Renders a table of a variable product's variations (ID, attribute values, price)
     * so the admin can copy the correct variation IDs into the settings below.
     */
    private function render_variation_lookup( $product_id ) {
        if ( ! $product_id ) {
            return;
        }
        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            echo '<p class="description" style="color:#a00;">No variable product found for that ID. Save the Product ID above first.</p>';
            return;
        }
        echo '<table class="widefat striped" style="max-width:600px;margin-top:10px;">';
        echo '<thead><tr><th>Variation ID</th><th>Attributes</th><th>Price</th></tr></thead><tbody>';
        foreach ( $product->get_children() as $var_id ) {
            $variation = wc_get_product( $var_id );
            if ( ! $variation ) continue;
            $labels = array();
            foreach ( $variation->get_attributes() as $attr_name => $attr_value ) {
                $labels[] = $attr_value !== '' ? $attr_value : '(any)';
            }
            echo '<tr><td><strong>' . esc_html( $var_id ) . '</strong></td><td>' . esc_html( implode( ', ', $labels ) ) . '</td><td>' . wp_kses_post( wc_price( $variation->get_price() ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public function settings_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Amusement Park Ticketing Settings</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'apt_settings_group' );
                do_settings_sections( 'apt_settings_group' );
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">WooCommerce Product ID</th>
                        <td><input type="number" name="apt_wc_product_id" value="<?php echo esc_attr( get_option('apt_wc_product_id') ); ?>" />
                        <p class="description">The ID of the Variable Product configured for the tickets.</p>
                        <?php $this->render_variation_lookup( get_option( 'apt_wc_product_id' ) ); ?>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Adult Variation ID</th>
                        <td><input type="number" name="apt_variation_id_adult" value="<?php echo esc_attr( get_option('apt_variation_id_adult') ); ?>" />
                        <p class="description">Variation ID for the "Adult" ticket (see table above). Required — this is also what booking availability is tracked against.</p></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Child (6-12) Variation ID</th>
                        <td><input type="number" name="apt_variation_id_child" value="<?php echo esc_attr( get_option('apt_variation_id_child') ); ?>" />
                        <p class="description">Variation ID for the "Child (6-12)" ticket. Leave blank if you don't sell this type.</p></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Child (Under 6) Variation ID</th>
                        <td><input type="number" name="apt_variation_id_infant" value="<?php echo esc_attr( get_option('apt_variation_id_infant') ); ?>" />
                        <p class="description">Variation ID for the free "Child (Under 6)" ticket. Leave blank if you don't sell this type.</p></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Default Adult Capacity (Morning)</th>
                        <td><input type="number" name="apt_capacity_morning" value="<?php echo esc_attr( get_option('apt_capacity_morning', 100) ); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Default Adult Capacity (Afternoon)</th>
                        <td><input type="number" name="apt_capacity_afternoon" value="<?php echo esc_attr( get_option('apt_capacity_afternoon', 100) ); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Default Adult Capacity (Evening)</th>
                        <td><input type="number" name="apt_capacity_evening" value="<?php echo esc_attr( get_option('apt_capacity_evening', 100) ); ?>" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function inventory_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'apt_inventory';

        // Handle form submission
        if ( isset($_POST['apt_inventory_nonce']) && wp_verify_nonce($_POST['apt_inventory_nonce'], 'apt_inventory_override') ) {
            $date = sanitize_text_field($_POST['visit_date']);
            $slot = sanitize_text_field($_POST['time_slot']);
            $capacity = intval($_POST['adult_capacity']);

            if ( !empty($date) && !empty($slot) ) {
                $wpdb->replace(
                    $table_name,
                    array(
                        'visit_date' => $date,
                        'time_slot' => $slot,
                        'adult_capacity' => $capacity
                    ),
                    array( '%s', '%s', '%d' )
                );
                echo '<div class="updated"><p>Inventory override saved.</p></div>';
            }
        }

        // Handle deletion
        if ( isset($_GET['delete_override']) && isset($_GET['slot']) ) {
            check_admin_referer('apt_delete_override', 'apt_nonce');
            $date = sanitize_text_field($_GET['delete_override']);
            $slot = sanitize_text_field($_GET['slot']);
            $wpdb->delete( $table_name, array( 'visit_date' => $date, 'time_slot' => $slot ) );
            echo '<div class="updated"><p>Inventory override removed.</p></div>';
        }

        $overrides = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY visit_date ASC" );
        ?>
        <div class="wrap">
            <h1>Inventory Overrides</h1>
            <p>Use this page to override the default capacities for specific dates and time slots.</p>

            <h2>Add New Override</h2>
            <form method="post" action="">
                <?php wp_nonce_field('apt_inventory_override', 'apt_inventory_nonce'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Date</th>
                        <td><input type="date" name="visit_date" required /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Time Slot</th>
                        <td>
                            <select name="time_slot">
                                <option value="morning">Morning</option>
                                <option value="afternoon">Afternoon</option>
                                <option value="evening">Evening</option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Adult Capacity Override</th>
                        <td><input type="number" name="adult_capacity" required /></td>
                    </tr>
                </table>
                <?php submit_button('Save Override'); ?>
            </form>

            <h2>Current Overrides & Bookings</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time Slot</th>
                        <th>Capacity Override</th>
                        <th>Adults Booked</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty($overrides) ) : ?>
                        <tr><td colspan="5">No overrides found.</td></tr>
                    <?php else: ?>
                        <?php foreach ( $overrides as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html($row->visit_date); ?></td>
                                <td><?php echo esc_html(ucfirst($row->time_slot)); ?></td>
                                <td><?php echo esc_html($row->adult_capacity !== null ? $row->adult_capacity : 'Default'); ?></td>
                                <td><?php echo esc_html($row->adult_booked); ?></td>
                                <td>
                                    <?php $delete_url = wp_nonce_url( admin_url('admin.php?page=apt-inventory&delete_override='.$row->visit_date.'&slot='.$row->time_slot), 'apt_delete_override', 'apt_nonce' ); ?>
                                    <a href="<?php echo esc_url($delete_url); ?>" class="button" onclick="return confirm('Remove override?');">Remove Override</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Adjusts wp_apt_inventory.adult_booked for a given date/slot by $delta
     * (positive or negative), floored at 0. Mirrors the increment logic used
     * when an order is first paid (class-apt-woocommerce.php), so manual
     * edits/deletes here keep capacity tracking accurate.
     */
    private function adjust_booked_count( $date, $slot, $delta ) {
        if ( ! $delta ) {
            return;
        }
        global $wpdb;
        $table_inventory = $wpdb->prefix . 'apt_inventory';

        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE $table_inventory SET adult_booked = GREATEST(0, adult_booked + %d) WHERE visit_date = %s AND time_slot = %s",
            $delta, $date, $slot
        ) );

        if ( ! $updated && $delta > 0 ) {
            $wpdb->insert(
                $table_inventory,
                array(
                    'visit_date'    => $date,
                    'time_slot'     => $slot,
                    'adult_booked'  => max( 0, $delta ),
                )
            );
        }
    }

    /**
     * Manage Reservations page: view/edit/delete individual reservations,
     * plus period-based CSV export and bulk delete.
     */
    public function reservations_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $table_tickets   = $wpdb->prefix . 'apt_tickets';
        $slots           = array( 'morning', 'afternoon', 'evening' );
        $notice          = '';

        // --- Handle: update a single reservation's date/time slot ---
        if ( isset( $_POST['apt_update_ticket_nonce'] ) && wp_verify_nonce( $_POST['apt_update_ticket_nonce'], 'apt_update_reservation' ) ) {
            $ticket_id = intval( $_POST['ticket_id'] );
            $new_date  = sanitize_text_field( $_POST['new_visit_date'] );
            $new_slot  = sanitize_text_field( $_POST['new_time_slot'] );

            $ticket = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_tickets WHERE id = %d", $ticket_id ) );

            if ( $ticket && ! empty( $new_date ) && in_array( $new_slot, $slots, true ) ) {
                if ( $new_date !== $ticket->visit_date || $new_slot !== $ticket->time_slot ) {
                    $this->adjust_booked_count( $ticket->visit_date, $ticket->time_slot, -intval( $ticket->adult_qty ) );
                    $this->adjust_booked_count( $new_date, $new_slot, intval( $ticket->adult_qty ) );

                    $wpdb->update(
                        $table_tickets,
                        array( 'visit_date' => $new_date, 'time_slot' => $new_slot ),
                        array( 'id' => $ticket_id )
                    );
                }
                $notice = '<div class="updated"><p>Reservation #' . intval( $ticket_id ) . ' updated.</p></div>';
            } else {
                $notice = '<div class="error"><p>Could not update reservation — invalid data.</p></div>';
            }
        }

        // --- Handle: delete a single reservation ---
        if ( isset( $_GET['delete_ticket'] ) ) {
            check_admin_referer( 'apt_delete_reservation', 'apt_nonce' );
            $ticket_id = intval( $_GET['delete_ticket'] );
            $ticket    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_tickets WHERE id = %d", $ticket_id ) );

            if ( $ticket ) {
                $this->adjust_booked_count( $ticket->visit_date, $ticket->time_slot, -intval( $ticket->adult_qty ) );
                $wpdb->delete( $table_tickets, array( 'id' => $ticket_id ) );
                $notice = '<div class="updated"><p>Reservation #' . intval( $ticket_id ) . ' deleted.</p></div>';
            }
        }

        // --- Handle: bulk delete all reservations in the selected period ---
        if ( isset( $_POST['apt_bulk_delete_nonce'] ) && wp_verify_nonce( $_POST['apt_bulk_delete_nonce'], 'apt_bulk_delete_reservations' ) ) {
            $confirm_text = isset( $_POST['confirm_text'] ) ? trim( $_POST['confirm_text'] ) : '';
            $bulk_from    = sanitize_text_field( $_POST['date_from'] );
            $bulk_to      = sanitize_text_field( $_POST['date_to'] );

            if ( 'DELETE' !== $confirm_text ) {
                $notice = '<div class="error"><p>Bulk delete cancelled — you must type DELETE exactly to confirm.</p></div>';
            } elseif ( empty( $bulk_from ) || empty( $bulk_to ) ) {
                $notice = '<div class="error"><p>Bulk delete cancelled — please select a valid date range.</p></div>';
            } else {
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT visit_date, time_slot, adult_qty FROM $table_tickets WHERE visit_date BETWEEN %s AND %s",
                    $bulk_from, $bulk_to
                ) );

                foreach ( $rows as $row ) {
                    $this->adjust_booked_count( $row->visit_date, $row->time_slot, -intval( $row->adult_qty ) );
                }

                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM $table_tickets WHERE visit_date BETWEEN %s AND %s",
                    $bulk_from, $bulk_to
                ) );

                $notice = '<div class="updated"><p>' . count( $rows ) . ' reservation(s) between ' . esc_html( $bulk_from ) . ' and ' . esc_html( $bulk_to ) . ' were deleted.</p></div>';
            }
        }

        // --- Determine the current filter period (defaults to a 30-day window around today) ---
        $date_from = isset( $_REQUEST['date_from'] ) ? sanitize_text_field( $_REQUEST['date_from'] ) : date( 'Y-m-d', strtotime( '-30 days' ) );
        $date_to   = isset( $_REQUEST['date_to'] ) ? sanitize_text_field( $_REQUEST['date_to'] ) : date( 'Y-m-d', strtotime( '+30 days' ) );

        $reservations = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_tickets WHERE visit_date BETWEEN %s AND %s ORDER BY visit_date ASC, time_slot ASC",
            $date_from, $date_to
        ) );

        $export_nonce = wp_create_nonce( 'apt_export_csv' );
        ?>
        <div class="wrap">
            <h1>Manage Reservations</h1>
            <?php echo $notice; ?>

            <form method="get" action="">
                <input type="hidden" name="page" value="apt-reservations" />
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">From</th>
                        <td><input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">To</th>
                        <td><input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" /></td>
                    </tr>
                </table>
                <?php submit_button( 'Filter', 'secondary' ); ?>
            </form>

            <p>
                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin-post.php?action=apt_export_reservations_csv&date_from=' . rawurlencode( $date_from ) . '&date_to=' . rawurlencode( $date_to ) . '&_wpnonce=' . $export_nonce ) ); ?>">
                    Download CSV for this period
                </a>
            </p>

            <h2>Reservations from <?php echo esc_html( $date_from ); ?> to <?php echo esc_html( $date_to ); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>PNR</th>
                        <th>Date</th>
                        <th>Slot</th>
                        <th>Adults</th>
                        <th>Child (6-12)</th>
                        <th>Child (Under 6)</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $reservations ) ) : ?>
                        <tr><td colspan="9">No reservations found in this period.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $reservations as $ticket ) : ?>
                            <tr>
                                <td>
                                    <?php
                                    $order = wc_get_order( $ticket->order_id );
                                    echo $order
                                        ? '<a href="' . esc_url( admin_url( 'post.php?post=' . $ticket->order_id . '&action=edit' ) ) . '">#' . esc_html( $order->get_order_number() ) . '</a>'
                                        : '#' . esc_html( $ticket->order_id ) . ' (missing)';
                                    ?>
                                </td>
                                <td><?php echo esc_html( $ticket->pnr ); ?></td>
                                <td colspan="2">
                                    <form method="post" action="" style="display:flex; gap:6px; align-items:center;">
                                        <?php wp_nonce_field( 'apt_update_reservation', 'apt_update_ticket_nonce' ); ?>
                                        <input type="hidden" name="ticket_id" value="<?php echo esc_attr( $ticket->id ); ?>" />
                                        <input type="date" name="new_visit_date" value="<?php echo esc_attr( $ticket->visit_date ); ?>" required />
                                        <select name="new_time_slot">
                                            <?php foreach ( $slots as $slot ) : ?>
                                                <option value="<?php echo esc_attr( $slot ); ?>" <?php selected( $ticket->time_slot, $slot ); ?>><?php echo esc_html( ucfirst( $slot ) ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="button">Save</button>
                                    </form>
                                </td>
                                <td><?php echo esc_html( $ticket->adult_qty ); ?></td>
                                <td><?php echo esc_html( $ticket->child_qty ); ?></td>
                                <td><?php echo esc_html( $ticket->infant_qty ); ?></td>
                                <td><?php echo esc_html( ucfirst( $ticket->status ) ); ?></td>
                                <td>
                                    <?php $delete_url = wp_nonce_url( admin_url( 'admin.php?page=apt-reservations&delete_ticket=' . $ticket->id . '&date_from=' . rawurlencode( $date_from ) . '&date_to=' . rawurlencode( $date_to ) ), 'apt_delete_reservation', 'apt_nonce' ); ?>
                                    <a href="<?php echo esc_url( $delete_url ); ?>" class="button" onclick="return confirm('Delete reservation #<?php echo esc_js( $ticket->id ); ?> permanently?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2 style="color:#a00;">Danger zone</h2>
            <p>This permanently deletes <strong>all</strong> reservations shown above (visit dates between <?php echo esc_html( $date_from ); ?> and <?php echo esc_html( $date_to ); ?>). This cannot be undone.</p>
            <form method="post" action="" onsubmit="return confirm('This will permanently delete ALL reservations in this period. Are you absolutely sure?');">
                <?php wp_nonce_field( 'apt_bulk_delete_reservations', 'apt_bulk_delete_nonce' ); ?>
                <input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
                <input type="hidden" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
                <p>
                    <label>Type <strong>DELETE</strong> to confirm:</label><br/>
                    <input type="text" name="confirm_text" required />
                </p>
                <?php submit_button( 'Delete All Reservations In Period', 'delete' ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Streams a CSV of all reservations in the given period, including
     * WooCommerce billing phone/email, and exits.
     */
    public function handle_export_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        check_admin_referer( 'apt_export_csv' );

        $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : '';
        $date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : '';

        global $wpdb;
        $table_tickets = $wpdb->prefix . 'apt_tickets';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_tickets WHERE visit_date BETWEEN %s AND %s ORDER BY visit_date ASC, time_slot ASC",
            $date_from, $date_to
        ) );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=reservations_' . $date_from . '_to_' . $date_to . '.csv' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'Order ID', 'PNR', 'Visit Date', 'Time Slot', 'Adults', 'Child (6-12)', 'Child (Under 6)', 'Status', 'Checkin Time', 'Phone', 'Email' ) );

        foreach ( $rows as $ticket ) {
            $order = wc_get_order( $ticket->order_id );
            $phone = $order ? $order->get_billing_phone() : '';
            $email = $order ? $order->get_billing_email() : '';

            fputcsv( $out, array(
                $ticket->order_id,
                $ticket->pnr,
                $ticket->visit_date,
                $ticket->time_slot,
                $ticket->adult_qty,
                $ticket->child_qty,
                $ticket->infant_qty,
                $ticket->status,
                $ticket->checkin_time,
                $phone,
                $email,
            ) );
        }

        fclose( $out );
        exit;
    }
}

new APT_Admin();
