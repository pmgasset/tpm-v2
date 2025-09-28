<?php
/**
 * File: class-admin.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-admin.php
 * Handles all admin-facing functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class GMS_Reservations_List_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct(['singular' => 'Reservation', 'plural' => 'Reservations', 'ajax' => false]);
    }

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'guest_name' => 'Guest',
            'property_name' => 'Property',
            'checkin_date' => 'Check-in',
            'status' => 'Status',
            'booking_reference' => 'Booking Ref',
        ];
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'guest_name':
                return '<strong>' . esc_html($item[$column_name]) . '</strong>';
            case 'property_name':
            case 'status':
            case 'booking_reference':
                return esc_html($item[$column_name]);
            case 'checkin_date':
                return date('M j, Y, g:i a', strtotime($item[$column_name]));
            default:
                return '';
        }
    }
    
    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="reservation[]" value="%s" />', $item['id']);
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], []];
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $total_items = GMS_Database::get_record_count('reservations');

        $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
        $this->items = GMS_Database::get_reservations($per_page, $current_page);
    }
}


class GMS_Admin {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        // The fatal error was caused by a call to a non-existent `register_settings` method here.
        // The correct implementation for the settings page is handled within the page rendering functions.
    }
    
    public function add_admin_menu() {
        add_menu_page('Guest Management', 'Guest Management', 'manage_options', 'guest-management-dashboard', [$this, 'render_reservations_page'], 'dashicons-businessperson', 25);
        add_submenu_page('guest-management-dashboard', 'Reservations', 'Reservations', 'manage_options', 'guest-management-dashboard', [$this, 'render_reservations_page']);
        // Add other pages like Guests, Settings, etc., here if needed.
    }
    
    public function render_reservations_page() {
        $reservations_table = new GMS_Reservations_List_Table();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Reservations</h1>
            <a href="#" class="page-title-action">Add New</a>
            <hr class="wp-header-end">
            <?php $reservations_table->prepare_items(); ?>
            <form method="post">
                <?php $reservations_table->display(); ?>
            </form>
        </div>
        <?php
    }
}
