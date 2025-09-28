<?php
/**
 * File: class-admin.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-admin.php
 * * Handles all admin-facing functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

// Custom List Table for Reservations
class GMS_Reservations_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(array(
            'singular' => 'Reservation',
            'plural'   => 'Reservations',
            'ajax'     => false
        ));
    }

    public function get_columns() {
        return array(
            'cb'                => '<input type="checkbox" />',
            'guest_name'        => 'Guest',
            'property_name'     => 'Property',
            'checkin_date'      => 'Check-in',
            'checkout_date'     => 'Check-out',
            'status'            => 'Status',
            'booking_reference' => 'Booking Ref',
        );
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'guest_name':
            case 'property_name':
            case 'status':
            case 'booking_reference':
                return $item[$column_name];
            case 'checkin_date':
            case 'checkout_date':
                return date('M j, Y g:i a', strtotime($item[$column_name]));
            default:
                return print_r($item, true);
        }
    }
    
    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="reservation[]" value="%s" />', $item['id']);
    }

    function prepare_items() {
        $this->_column_headers = array($this->get_columns(), array(), array());

        $per_page = 20;
        $current_page = $this->get_pagenum();
        
        // FIX: Use the new function to get the total count for pagination
        $total_items = GMS_Database::get_record_count('reservations');

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page
        ]);

        // Use the new paginated function to get only the items for the current page
        $this->items = GMS_Database::get_reservations($per_page, $current_page);
    }
}

// Custom List Table for Guests
class GMS_Guests_List_Table extends WP_List_Table {
    // (This class uses the new get_all_guests function which should work as intended)
    public function __construct() {
        parent::__construct(array(
            'singular' => 'Guest',
            'plural'   => 'Guests',
            'ajax'     => false
        ));
    }

    public function get_columns() {
        return array(
            'cb'        => '<input type="checkbox" />',
            'name'      => 'Name',
            'email'     => 'Email',
            'phone'     => 'Phone',
            'total_bookings' => 'Total Bookings'
        );
    }
    
    public function column_default($item, $column_name) {
        switch($column_name) {
            case 'email':
            case 'phone':
            case 'total_bookings':
                return $item[$column_name];
            case 'name':
                return $item['first_name'] . ' ' . $item['last_name'];
            default:
                return print_r($item, true);
        }
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="guest[]" value="%s" />', $item['id']);
    }

    function prepare_items() {
        $this->_column_headers = array($this->get_columns(), array(), array());
        $this->items = GMS_Database::get_all_guests(); 
    }
}


class GMS_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Guest Management',
            'Guest Management',
            'manage_options',
            'guest-management-dashboard',
            array($this, 'render_dashboard_page'),
            'dashicons-businessperson',
            25
        );
        
        add_submenu_page(
            'guest-management-dashboard',
            'Reservations',
            'Reservations',
            'manage_options',
            'guest-management-reservations',
            array($this, 'render_reservations_page')
        );
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
    
    // (The rest of the class is the same as your original)
}
