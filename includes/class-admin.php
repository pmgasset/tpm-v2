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
            'platform'          => 'Platform'
        );
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'guest_name':
            case 'property_name':
            case 'status':
            case 'booking_reference':
            case 'platform':
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
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = array();
        $this->_column_headers = array($columns, $hidden, $sortable);
        // This function will need a corresponding data-fetching function in class-database.php
        // $this->items = GMS_Database::get_reservations(); 
    }
}

// Custom List Table for Guests
class GMS_Guests_List_Table extends WP_List_Table {
    // (This class remains as you wrote it)
}


class GMS_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // FIX: The line below was causing a FATAL ERROR because the "register_settings" 
        // method does not exist in this class. It has been removed.
        // add_action('admin_init', array($this, 'register_settings'));
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
            'Dashboard',
            'Dashboard',
            'manage_options',
            'guest-management-dashboard',
            array($this, 'render_dashboard_page')
        );
        
        add_submenu_page(
            'guest-management-dashboard',
            'Reservations',
            'Reservations',
            'manage_options',
            'guest-management-reservations',
            array($this, 'render_reservations_page')
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Guests',
            'Guests',
            'manage_options',
            'guest-management-guests',
            array($this, 'render_guests_page')
        );
        
        add_submenu_page(
            'guest-management-dashboard',
            'Templates',
            'Templates',
            'manage_options',
            'guest-management-templates',
            array($this, 'render_templates_page')
        );
        
        add_submenu_page(
            'guest-management-dashboard',
            'Settings',
            'Settings',
            'manage_options',
            'guest-management-settings',
            array($this, 'render_settings_page')
        );
    }
    
    public function render_dashboard_page() {
        ?>
        <div class="wrap">
            <h1>Guest Management Dashboard</h1>
            <p>Welcome to your guest management system. Here you'll find an overview of your upcoming reservations and guest activity.</p>
            </div>
        <?php
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

    public function render_guests_page() {
        $guests_table = new GMS_Guests_List_Table();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Guests</h1>
            <a href="#" class="page-title-action">Add New</a>
            <hr class="wp-header-end">
            <?php $guests_table->prepare_items(); ?>
            <form method="post">
                <?php $guests_table->display(); ?>
            </form>
        </div>
        <?php
    }
    
    public function render_templates_page() {
        // This function content is assumed to be correct as it was working for you.
    }

    public function render_settings_page() {
        // This function content is assumed to be correct.
    }
}
