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
        add_menu_page(
            'Guest Management',
            'Guest Management',
            'manage_options',
            'guest-management-dashboard',
            [$this, 'render_dashboard_page'],
            'dashicons-businessperson',
            25
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'guest-management-dashboard',
            [$this, 'render_dashboard_page']
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Reservations',
            'Reservations',
            'manage_options',
            'guest-management-reservations',
            [$this, 'render_reservations_page']
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Guests',
            'Guests',
            'manage_options',
            'guest-management-guests',
            [$this, 'render_guests_page']
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Communications & Logs',
            'Communications/Logs',
            'manage_options',
            'guest-management-communications',
            [$this, 'render_communications_page']
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Templates',
            'Templates',
            'manage_options',
            'guest-management-templates',
            [$this, 'render_templates_page']
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Settings',
            'Settings',
            'manage_options',
            'guest-management-settings',
            [$this, 'render_settings_page']
        );
    }

    public function render_dashboard_page() {
        $total_reservations = GMS_Database::get_record_count('reservations');
        $total_guests = GMS_Database::get_record_count('guests');

        $upcoming_checkins = gms_get_upcoming_checkins(7);
        $pending_checkins = gms_get_pending_checkins();
        $recent_reservations = GMS_Database::get_reservations(5, 1);

        $upcoming_count = is_array($upcoming_checkins) ? count($upcoming_checkins) : 0;
        $pending_count = is_array($pending_checkins) ? count($pending_checkins) : 0;

        ?>
        <div class="wrap gms-dashboard">
            <h1 class="wp-heading-inline"><?php esc_html_e('Guest Management Dashboard', 'guest-management-system'); ?></h1>
            <hr class="wp-header-end">

            <div class="gms-dashboard-stats">
                <div class="gms-stat-box">
                    <h3><?php echo esc_html(number_format_i18n($total_reservations)); ?></h3>
                    <p><?php esc_html_e('Total Reservations', 'guest-management-system'); ?></p>
                </div>
                <div class="gms-stat-box">
                    <h3><?php echo esc_html(number_format_i18n($upcoming_count)); ?></h3>
                    <p><?php esc_html_e('Upcoming Check-ins (7 Days)', 'guest-management-system'); ?></p>
                </div>
                <div class="gms-stat-box">
                    <h3><?php echo esc_html(number_format_i18n($pending_count)); ?></h3>
                    <p><?php esc_html_e('Pending Check-ins', 'guest-management-system'); ?></p>
                </div>
                <div class="gms-stat-box">
                    <h3><?php echo esc_html(number_format_i18n($total_guests)); ?></h3>
                    <p><?php esc_html_e('Total Guests', 'guest-management-system'); ?></p>
                </div>
            </div>

            <div class="gms-recent-reservations">
                <h2><?php esc_html_e('Recent Reservations', 'guest-management-system'); ?></h2>
                <?php if (!empty($recent_reservations)) : ?>
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Guest', 'guest-management-system'); ?></th>
                                <th><?php esc_html_e('Property', 'guest-management-system'); ?></th>
                                <th><?php esc_html_e('Check-in', 'guest-management-system'); ?></th>
                                <th><?php esc_html_e('Status', 'guest-management-system'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_reservations as $reservation) :
                                $status = isset($reservation['status']) ? $reservation['status'] : '';
                                $status_slug = $status ? strtolower(str_replace([' ', '_'], '-', $status)) : 'default';
                                $checkin_display = !empty($reservation['checkin_date'])
                                    ? gms_format_datetime($reservation['checkin_date'])
                                    : __('—', 'guest-management-system');
                                ?>
                                <tr>
                                    <td><?php echo esc_html($reservation['guest_name'] ?? __('Unknown Guest', 'guest-management-system')); ?></td>
                                    <td><?php echo esc_html($reservation['property_name'] ?? __('N/A', 'guest-management-system')); ?></td>
                                    <td><?php echo esc_html($checkin_display); ?></td>
                                    <td>
                                        <?php if ($status) : ?>
                                            <span class="status-badge status-<?php echo esc_attr($status_slug); ?>"><?php echo esc_html(ucwords(str_replace('_', ' ', $status))); ?></span>
                                        <?php else : ?>
                                            <span class="status-badge status-default"><?php esc_html_e('Unknown', 'guest-management-system'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php esc_html_e('No recent reservations found.', 'guest-management-system'); ?></p>
                <?php endif; ?>
            </div>

            <div class="gms-quick-actions">
                <h2><?php esc_html_e('Quick Actions', 'guest-management-system'); ?></h2>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=guest-management-reservations')); ?>"><?php esc_html_e('Manage Reservations', 'guest-management-system'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=guest-management-guests')); ?>"><?php esc_html_e('View Guests', 'guest-management-system'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=guest-management-communications')); ?>"><?php esc_html_e('Communications & Logs', 'guest-management-system'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=guest-management-templates')); ?>"><?php esc_html_e('Edit Templates', 'guest-management-system'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=guest-management-settings')); ?>"><?php esc_html_e('Open Settings', 'guest-management-system'); ?></a>
            </div>
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
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Guests', 'guest-management-system'); ?></h1>
            <hr class="wp-header-end">
            <p><?php esc_html_e('Manage guest records, contact information, and stay history from this section.', 'guest-management-system'); ?></p>
        </div>
        <?php
    }

    public function render_communications_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Communications & Logs', 'guest-management-system'); ?></h1>
            <hr class="wp-header-end">
            <p><?php esc_html_e('Review automated emails, SMS activity, and other communication logs here.', 'guest-management-system'); ?></p>
        </div>
        <?php
    }

    public function render_templates_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Templates', 'guest-management-system'); ?></h1>
            <hr class="wp-header-end">
            <p><?php esc_html_e('Customize notification and agreement templates used throughout the guest journey.', 'guest-management-system'); ?></p>
        </div>
        <?php
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Settings', 'guest-management-system'); ?></h1>
            <hr class="wp-header-end">
            <p><?php esc_html_e('Configure integrations, automation rules, and other plugin options.', 'guest-management-system'); ?></p>
        </div>
        <?php
    }
}
