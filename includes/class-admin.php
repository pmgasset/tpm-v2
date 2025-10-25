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

if (!function_exists('gms_admin_format_datetime_column')) {
    /**
     * Formats a datetime string for admin column display using the WordPress timezone settings.
     *
     * @param mixed       $datetime Raw datetime string.
     * @param string|null $format   Optional date format. When omitted the site preferences are used.
     * @return string|null          Formatted datetime string or null when the value cannot be parsed.
     */
    function gms_admin_format_datetime_column($datetime, $format = null)
    {
        if (!is_string($datetime)) {
            return null;
        }

        $datetime = trim($datetime);
        if ($datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return null;
        }

        $timezone = wp_timezone();

        try {
            $date = new \DateTimeImmutable($datetime, $timezone);
        } catch (\Exception $exception) {
            return null;
        }

        try {
            $date = $date->setTimezone($timezone);
        } catch (\Exception $exception) {
            return null;
        }

        if ($format === null) {
            $date_format = get_option('date_format', 'M j, Y');
            $time_format = get_option('time_format', 'g:i a');
            $format = trim($date_format . ' ' . $time_format);
        }

        if ($format === '') {
            $format = 'M j, Y g:i a';
        }

        return wp_date($format, $date->getTimestamp(), $timezone);
    }
}

class GMS_Reservations_List_Table extends WP_List_Table {
    protected $status_filter = '';
    protected $checkin_filter = '';
    protected $search_term = '';
    protected $guest_profile_cache = array();

    public function __construct() {
        parent::__construct([
            'singular' => 'reservation',
            'plural' => 'reservations',
            'ajax' => false,
        ]);
    }

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'guest_name' => __('Guest', 'guest-management-system'),
            'property_name' => __('Property', 'guest-management-system'),
            'checkin_date' => __('Check-in', 'guest-management-system'),
            'status' => __('Status', 'guest-management-system'),
            'booking_reference' => __('Booking Ref', 'guest-management-system'),
            'guest_profile' => __('Guest Profile', 'guest-management-system'),
            'portal_link' => __('Guest Portal', 'guest-management-system'),
        ];
    }

    public function get_sortable_columns() {
        return [
            'guest_name' => ['guest_name', false],
            'property_name' => ['property_name', false],
            'checkin_date' => ['checkin_date', true],
            'status' => ['status', false],
            'booking_reference' => ['booking_reference', false],
        ];
    }

    public function get_bulk_actions() {
        return [
            'delete' => __('Delete', 'guest-management-system'),
        ];
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'property_name':
            case 'status':
                return esc_html($item[$column_name]);
            case 'checkin_date':
                $formatted = gms_admin_format_datetime_column($item[$column_name] ?? '', 'M j, Y, g:i a');
                if ($formatted === null) {
                    return '&mdash;';
                }

                return esc_html($formatted);
            default:
                return '';
        }
    }

    public function column_guest_name($item) {
        $reservation_id = isset($item['id']) ? absint($item['id']) : 0;
        $guest_name = isset($item['guest_name']) && $item['guest_name'] !== ''
            ? $item['guest_name']
            : __('Unknown Guest', 'guest-management-system');

        $profile_link = $this->get_guest_profile_link($reservation_id);

        if (!empty($profile_link['url'])) {
            $value = sprintf(
                '<strong><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></strong>',
                esc_url($profile_link['url']),
                esc_html($guest_name)
            );
        } else {
            $value = '<strong>' . esc_html($guest_name) . '</strong>';
        }

        if ($reservation_id) {
            $actions = [];

            $edit_url = add_query_arg(
                [
                    'page' => 'guest-management-reservations',
                    'action' => 'edit',
                    'reservation_id' => $reservation_id,
                ],
                admin_url('admin.php')
            );

            $actions['edit'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($edit_url),
                esc_html__('Edit', 'guest-management-system')
            );

            $delete_url = wp_nonce_url(
                add_query_arg(
                    [
                        'page' => 'guest-management-reservations',
                        'action' => 'delete',
                        'reservation_id' => $reservation_id,
                    ],
                    admin_url('admin.php')
                ),
                'gms_delete_reservation_' . $reservation_id
            );

            $actions['delete'] = sprintf(
                '<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
                esc_url($delete_url),
                esc_attr__('Are you sure you want to delete this reservation?', 'guest-management-system'),
                esc_html__('Delete', 'guest-management-system')
            );

            $value .= $this->row_actions($actions);
        }

        return $value;
    }

    public function column_guest_profile($item) {
        $reservation_id = isset($item['id']) ? absint($item['id']) : 0;

        if ($reservation_id <= 0) {
            return '&mdash;';
        }

        $profile_link = $this->get_guest_profile_link($reservation_id);

        if (empty($profile_link['url'])) {
            return '&mdash;';
        }

        $display = !empty($profile_link['short_link']) ? $profile_link['short_link'] : $profile_link['url'];

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url($profile_link['url']),
            esc_html($display)
        );
    }

    public function column_booking_reference($item) {
        $reservation_id = isset($item['id']) ? absint($item['id']) : 0;
        $reference = isset($item['booking_reference']) ? (string) $item['booking_reference'] : '';

        if (!$reservation_id) {
            return esc_html($reference);
        }

        $label = $reference !== '' ? $reference : __('Edit Reservation', 'guest-management-system');

        $edit_url = add_query_arg(
            [
                'page' => 'guest-management-reservations',
                'action' => 'edit',
                'reservation_id' => $reservation_id,
            ],
            admin_url('admin.php')
        );

        $aria_label = sprintf(
            /* translators: %s: reservation identifier */
            __('Edit reservation %s', 'guest-management-system'),
            $label
        );

        return sprintf(
            '<a class="gms-reservation-edit-link" href="%1$s" aria-label="%2$s">%3$s</a>',
            esc_url($edit_url),
            esc_attr($aria_label),
            esc_html($label)
        );
    }

    public function column_portal_link($item) {
        $reservation_id = isset($item['id']) ? absint($item['id']) : 0;

        if (!$reservation_id) {
            return '&mdash;';
        }

        $portal_url = gms_get_portal_url($reservation_id);

        if (empty($portal_url)) {
            return '&mdash;';
        }

        $copy_label = __('Copy portal link', 'guest-management-system');
        $button_label = __('Open Portal', 'guest-management-system');
        $aria_label = __('Open the guest portal in a new tab. The link will be copied to your clipboard.', 'guest-management-system');

        return sprintf(
            '<a class="button button-small gms-open-portal" href="%1$s" target="_blank" rel="noopener noreferrer" data-copy-url="%1$s" aria-label="%2$s" data-copy-label="%3$s">%4$s</a>',
            esc_url($portal_url),
            esc_attr($aria_label),
            esc_attr($copy_label),
            esc_html($button_label)
        );
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="reservation[]" value="%s" />', absint($item['id']));
    }

    public function get_primary_column_name() {
        return 'guest_name';
    }

    public function no_items() {
        esc_html_e('No reservations found.', 'guest-management-system');
    }

    public function extra_tablenav($which) {
        if ('top' !== $which) {
            return;
        }

        $status_options = function_exists('gms_get_reservation_status_options')
            ? gms_get_reservation_status_options()
            : [
                'pending' => __('Pending Approval', 'guest-management-system'),
                'approved' => __('Approved', 'guest-management-system'),
                'awaiting_signature' => __('Awaiting Signature', 'guest-management-system'),
                'awaiting_id_verification' => __('Awaiting ID Verification', 'guest-management-system'),
                'confirmed' => __('Confirmed', 'guest-management-system'),
                'completed' => __('Completed', 'guest-management-system'),
                'cancelled' => __('Cancelled', 'guest-management-system'),
            ];

        echo '<div class="alignleft actions">';
        echo '<label class="screen-reader-text" for="filter-by-reservation-status">' . esc_html__('Filter by status', 'guest-management-system') . '</label>';
        echo '<select name="reservation_status" id="filter-by-reservation-status">';
        echo '<option value="">' . esc_html__('All statuses', 'guest-management-system') . '</option>';
        echo '<option value="pending_checkins"' . selected($this->status_filter, 'pending_checkins', false) . '>' . esc_html__('Pending Check-ins', 'guest-management-system') . '</option>';
        foreach ($status_options as $status_key => $status_label) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($status_key),
                selected($this->status_filter, $status_key, false),
                esc_html($status_label)
            );
        }
        echo '</select>';

        echo '<label class="screen-reader-text" for="filter-by-checkin">' . esc_html__('Filter by check-in window', 'guest-management-system') . '</label>';
        echo '<select name="checkin_filter" id="filter-by-checkin">';
        echo '<option value="">' . esc_html__('All dates', 'guest-management-system') . '</option>';
        echo '<option value="upcoming"' . selected($this->checkin_filter, 'upcoming', false) . '>' . esc_html__('Upcoming (7 days)', 'guest-management-system') . '</option>';
        echo '<option value="pending_checkins"' . selected($this->checkin_filter, 'pending_checkins', false) . '>' . esc_html__('Future check-ins', 'guest-management-system') . '</option>';
        echo '</select>';

        submit_button(__('Filter'), '', 'filter_action', false);
        echo '</div>';
    }

    public function process_bulk_action() {
        // Bulk deletions are processed early on the load-* hook to avoid
        // triggering redirects after headers have already been sent.
        if ($this->current_action() === 'delete') {
            return;
        }
    }

    public function prepare_items() {
        $this->status_filter = isset($_REQUEST['reservation_status']) ? sanitize_key(wp_unslash($_REQUEST['reservation_status'])) : '';
        if ($this->status_filter === 'all') {
            $this->status_filter = '';
        }

        $this->checkin_filter = isset($_REQUEST['checkin_filter']) ? sanitize_key(wp_unslash($_REQUEST['checkin_filter'])) : '';
        if ($this->checkin_filter === 'all') {
            $this->checkin_filter = '';
        }

        $this->search_term = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';

        $orderby = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'checkin_date';
        $order = isset($_REQUEST['order']) ? strtoupper(sanitize_key(wp_unslash($_REQUEST['order']))) : 'DESC';
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $this->process_bulk_action();

        $per_page = $this->get_items_per_page('gms_reservations_per_page', 20);
        $current_page = $this->get_pagenum();

        $query_args = [
            'per_page' => $per_page,
            'page' => $current_page,
            'search' => $this->search_term,
            'status' => $this->status_filter,
            'checkin_filter' => $this->checkin_filter,
            'orderby' => $orderby,
            'order' => $order,
        ];

        $total_items = GMS_Database::count_reservations($query_args);
        $this->items = GMS_Database::get_reservations($query_args);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
        ]);
    }

    private function get_guest_profile_link($reservation_id) {
        $reservation_id = absint($reservation_id);

        if ($reservation_id <= 0) {
            return array('url' => '', 'short_link' => '', 'token' => '');
        }

        if (!isset($this->guest_profile_cache[$reservation_id])) {
            $this->guest_profile_cache[$reservation_id] = GMS_Database::getGuestProfileLinkForReservation($reservation_id);
        }

        return $this->guest_profile_cache[$reservation_id];
    }
}


class GMS_Guests_List_Table extends WP_List_Table {
    protected $status_filter = '';
    protected $search_term = '';

    public function __construct() {
        parent::__construct([
            'singular' => 'guest',
            'plural' => 'guests',
            'ajax' => false,
        ]);
    }

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'name' => __('Name', 'guest-management-system'),
            'email' => __('Email', 'guest-management-system'),
            'phone' => __('Phone', 'guest-management-system'),
            'created_at' => __('Created', 'guest-management-system'),
            'status' => __('Status', 'guest-management-system'),
        ];
    }

    public function get_sortable_columns() {
        return [
            'name' => ['name', false],
            'email' => ['email', false],
            'phone' => ['phone', false],
            'created_at' => ['created_at', true],
        ];
    }

    public function get_bulk_actions() {
        return [
            'delete' => __('Delete', 'guest-management-system'),
        ];
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'email':
            case 'phone':
                return $item[$column_name] !== '' ? esc_html($item[$column_name]) : '&mdash;';
            case 'created_at':
                $formatted = gms_admin_format_datetime_column($item[$column_name] ?? '');
                if ($formatted === null) {
                    return '&mdash;';
                }

                return esc_html($formatted);
            case 'status':
                $has_name = !empty($item['name']);
                $has_contact = !empty($item['email']) || !empty($item['phone']);
                $status = ($has_name && $has_contact)
                    ? __('Complete', 'guest-management-system')
                    : __('Incomplete', 'guest-management-system');
                return esc_html($status);
            default:
                return '';
        }
    }

    public function column_name($item) {
        $guest_id = isset($item['id']) ? absint($item['id']) : 0;
        $name = isset($item['name']) && $item['name'] !== ''
            ? $item['name']
            : __('Unnamed Guest', 'guest-management-system');

        $value = '<strong>' . esc_html($name) . '</strong>';

        if ($guest_id) {
            $profile_url = add_query_arg(
                [
                    'page' => 'guest-management-guests',
                    'action' => 'view',
                    'guest_id' => $guest_id,
                ],
                admin_url('admin.php')
            );

            $value = sprintf('<strong><a href="%s">%s</a></strong>', esc_url($profile_url), esc_html($name));

            $actions = [];

            $actions['view'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($profile_url),
                esc_html__('View Profile', 'guest-management-system')
            );

            $edit_url = add_query_arg(
                [
                    'page' => 'guest-management-guests',
                    'action' => 'edit',
                    'guest_id' => $guest_id,
                ],
                admin_url('admin.php')
            );

            $actions['edit'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($edit_url),
                esc_html__('Edit', 'guest-management-system')
            );

            $delete_url = wp_nonce_url(
                add_query_arg(
                    [
                        'page' => 'guest-management-guests',
                        'action' => 'delete',
                        'guest_id' => $guest_id,
                    ],
                    admin_url('admin.php')
                ),
                'gms_delete_guest_' . $guest_id
            );

            $actions['delete'] = sprintf(
                '<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
                esc_url($delete_url),
                esc_attr__('Are you sure you want to delete this guest?', 'guest-management-system'),
                esc_html__('Delete', 'guest-management-system')
            );

            $value .= $this->row_actions($actions);
        }

        return $value;
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="guest[]" value="%s" />', absint($item['id']));
    }

    public function get_primary_column_name() {
        return 'name';
    }

    public function no_items() {
        esc_html_e('No guests found.', 'guest-management-system');
    }

    public function extra_tablenav($which) {
        if ('top' !== $which) {
            return;
        }

        echo '<div class="alignleft actions">';
        echo '<label class="screen-reader-text" for="filter-by-guest-status">' . esc_html__('Filter guests', 'guest-management-system') . '</label>';
        echo '<select name="guest_status" id="filter-by-guest-status">';
        echo '<option value="">' . esc_html__('All guests', 'guest-management-system') . '</option>';
        echo '<option value="complete"' . selected($this->status_filter, 'complete', false) . '>' . esc_html__('Complete profiles', 'guest-management-system') . '</option>';
        echo '<option value="incomplete"' . selected($this->status_filter, 'incomplete', false) . '>' . esc_html__('Incomplete profiles', 'guest-management-system') . '</option>';
        echo '</select>';
        submit_button(__('Filter'), '', 'filter_action', false);
        echo '</div>';
    }

    public function process_bulk_action() {
        // Bulk deletions are handled before the page renders so redirects
        // occur before WordPress outputs the admin header.
        if ($this->current_action() === 'delete') {
            return;
        }
    }

    public function prepare_items() {
        $this->status_filter = isset($_REQUEST['guest_status']) ? sanitize_key(wp_unslash($_REQUEST['guest_status'])) : '';
        if ($this->status_filter === 'all') {
            $this->status_filter = '';
        }

        $this->search_term = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';

        $orderby = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'created_at';
        $order = isset($_REQUEST['order']) ? strtoupper(sanitize_key(wp_unslash($_REQUEST['order']))) : 'DESC';
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $this->process_bulk_action();

        $per_page = $this->get_items_per_page('gms_guests_per_page', 20);
        $current_page = $this->get_pagenum();

        $query_args = [
            'per_page' => $per_page,
            'page' => $current_page,
            'search' => $this->search_term,
            'status' => $this->status_filter,
            'orderby' => $orderby,
            'order' => $order,
        ];

        $total_items = GMS_Database::get_guest_count($query_args);
        $this->items = GMS_Database::get_guests($query_args);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
        ]);
    }
}

class GMS_Message_Templates_List_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct([
            'singular' => 'gms_message_template',
            'plural' => 'gms_message_templates',
            'ajax' => false,
        ]);
    }

    public function get_columns() {
        return array(
            'label' => __('Label', 'guest-management-system'),
            'channel' => __('Channel', 'guest-management-system'),
            'content' => __('Preview', 'guest-management-system'),
            'is_active' => __('Active', 'guest-management-system'),
            'updated_at' => __('Last Updated', 'guest-management-system'),
        );
    }

    protected function get_table_classes() {
        $classes = parent::get_table_classes();
        $classes[] = 'widefat';

        return $classes;
    }

    public function no_items() {
        esc_html_e('No templates found.', 'guest-management-system');
    }

    protected function column_label($item) {
        $label = isset($item['label']) ? (string) $item['label'] : '';
        $template_id = isset($item['id']) ? intval($item['id']) : 0;

        $actions = array();

        if ($template_id > 0) {
            $edit_url = add_query_arg(
                array(
                    'page' => 'guest-management-templates',
                    'action' => 'edit_template',
                    'template_id' => $template_id,
                ),
                admin_url('admin.php')
            );

            $delete_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action' => 'gms_delete_message_template',
                        'template_id' => $template_id,
                    ),
                    admin_url('admin-post.php')
                ),
                'gms_delete_message_template_' . $template_id
            );

            $actions['edit'] = sprintf('<a href="%s">%s</a>', esc_url($edit_url), esc_html__('Edit', 'guest-management-system'));
            $actions['delete'] = sprintf(
                '<a href="%1$s" class="submitdelete" onclick="return confirm(%2$s);">%3$s</a>',
                esc_url($delete_url),
                wp_json_encode(__('Are you sure you want to delete this template?', 'guest-management-system')),
                esc_html__('Delete', 'guest-management-system')
            );
        }

        $label_display = '<strong>' . esc_html($label) . '</strong>';

        if (!empty($actions)) {
            $label_display .= $this->row_actions($actions);
        }

        return $label_display;
    }

    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'channel':
                $channel = sanitize_key($item['channel'] ?? '');
                switch ($channel) {
                    case 'whatsapp':
                        return esc_html__('WhatsApp', 'guest-management-system');
                    case 'all':
                        return esc_html__('All Channels', 'guest-management-system');
                    case 'sms':
                    default:
                        return esc_html__('SMS', 'guest-management-system');
                }
            case 'content':
                $content = isset($item['content']) ? (string) $item['content'] : '';
                if ($content === '') {
                    return '&mdash;';
                }

                $substr = function_exists('mb_substr') ? 'mb_substr' : 'substr';
                $strlen = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';

                $preview = $substr($content, 0, 120);
                if ($strlen($content) > 120) {
                    $preview .= '…';
                }

                $preview = str_replace('\n', ' / ', $preview);

                return esc_html($preview);
            case 'is_active':
                $active = !empty($item['is_active']);
                return $active ? esc_html__('Yes', 'guest-management-system') : esc_html__('No', 'guest-management-system');
            case 'updated_at':
                $formatted = gms_admin_format_datetime_column($item['updated_at'] ?? '');
                if ($formatted === null) {
                    return '&mdash;';
                }

                return esc_html($formatted);
            default:
                return isset($item[$column_name]) ? esc_html($item[$column_name]) : '';
        }
    }

    protected function extra_tablenav($which) {
        if ($which !== 'top') {
            return;
        }

        $channel = isset($_REQUEST['filter_channel']) ? sanitize_key(wp_unslash($_REQUEST['filter_channel'])) : '';

        $channels = array(
            '' => __('All channels', 'guest-management-system'),
            'sms' => __('SMS', 'guest-management-system'),
            'whatsapp' => __('WhatsApp', 'guest-management-system'),
            'all' => __('All templates', 'guest-management-system'),
        );

        echo '<div class="alignleft actions">';
        echo '<label class="screen-reader-text" for="filter_channel">' . esc_html__('Filter by channel', 'guest-management-system') . '</label>';
        echo '<select name="filter_channel" id="filter_channel">';
        foreach ($channels as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($channel, $value, false), esc_html($label));
        }
        echo '</select>';
        submit_button(__('Filter'), 'secondary', false, false, array('id' => 'gms-filter-message-templates'));
        echo '</div>';
    }

    public function prepare_items() {
        $per_page = 10;
        $current_page = $this->get_pagenum();
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        $channel = isset($_REQUEST['filter_channel']) ? sanitize_key(wp_unslash($_REQUEST['filter_channel'])) : '';

        $result = GMS_Database::getMessageTemplates(array(
            'page' => $current_page,
            'per_page' => $per_page,
            'search' => $search,
            'channel' => $channel,
            'include_inactive' => true,
        ));

        $this->items = isset($result['items']) ? $result['items'] : array();
        $total_items = isset($result['total']) ? intval($result['total']) : 0;

        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => isset($result['total_pages']) ? max(1, intval($result['total_pages'])) : 1,
        ));
    }
}


class GMS_Admin {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_gms_test_sms', array($this, 'ajax_test_sms'));
        add_action('wp_ajax_gms_test_email', array($this, 'ajax_test_email'));
        add_action('wp_ajax_gms_resend_notification', array($this, 'ajax_resend_notification'));
        add_action('wp_ajax_gms_bulk_action', array($this, 'ajax_bulk_action'));
        add_action('wp_ajax_gms_autosave_template', array($this, 'ajax_autosave_template'));
        add_action('wp_ajax_gms_refresh_stats', array($this, 'ajax_refresh_stats'));
        add_action('admin_post_gms_save_message_template', array($this, 'handle_save_message_template'));
        add_action('admin_post_gms_delete_message_template', array($this, 'handle_delete_message_template'));
        add_action('admin_post_gms_housekeeper_ical', array($this, 'handle_housekeeper_ical_export'));
        add_action('admin_post_gms_refresh_guest_verification', array($this, 'handle_refresh_guest_verification'));
        add_action('admin_post_gms_view_verification_asset', array($this, 'handle_view_verification_asset'));
        add_action('admin_notices', array($this, 'maybe_show_housekeeper_submission_notice'));
    }

    private function build_reservations_redirect_url() {
        $redirect_args = array(
            'page' => 'guest-management-reservations',
        );

        $keys = array('reservation_status', 'checkin_filter', 'orderby', 'order');
        foreach ($keys as $key) {
            if (!isset($_REQUEST[$key])) {
                continue;
            }

            $value = sanitize_key(wp_unslash($_REQUEST[$key]));
            if ($value !== '') {
                $redirect_args[$key] = $value;
            }
        }

        if (!empty($_REQUEST['s'])) {
            $redirect_args['s'] = sanitize_text_field(wp_unslash($_REQUEST['s']));
        }

        $paged = isset($_REQUEST['paged']) ? absint($_REQUEST['paged']) : 0;
        if ($paged > 1) {
            $redirect_args['paged'] = $paged;
        }

        return add_query_arg($redirect_args, admin_url('admin.php'));
    }

    private function build_guests_redirect_url() {
        $redirect_args = array(
            'page' => 'guest-management-guests',
        );

        $keys = array('guest_status', 'orderby', 'order');
        foreach ($keys as $key) {
            if (!isset($_REQUEST[$key])) {
                continue;
            }

            $value = sanitize_key(wp_unslash($_REQUEST[$key]));
            if ($value !== '') {
                $redirect_args[$key] = $value;
            }
        }

        if (!empty($_REQUEST['s'])) {
            $redirect_args['s'] = sanitize_text_field(wp_unslash($_REQUEST['s']));
        }

        $paged = isset($_REQUEST['paged']) ? absint($_REQUEST['paged']) : 0;
        if ($paged > 1) {
            $redirect_args['paged'] = $paged;
        }

        return add_query_arg($redirect_args, admin_url('admin.php'));
    }

    public function ajax_test_sms() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $number = isset($_POST['number']) ? sanitize_text_field(wp_unslash($_POST['number'])) : '';

        if (empty($number)) {
            wp_send_json_error(__('Please provide a phone number.', 'guest-management-system'));
        }

        $company_name = get_option('gms_company_name', get_option('blogname'));
        $message = sprintf(__('This is a test SMS from %s.', 'guest-management-system'), $company_name);

        $sms_handler = new GMS_SMS_Handler();
        $sent = $sms_handler->sendSMS($number, $message);

        if (!$sent) {
            wp_send_json_error(__('Failed to send test SMS. Please verify your VoIP.ms configuration.', 'guest-management-system'));
        }

        wp_send_json_success(array(
            'message' => __('Test SMS sent successfully.', 'guest-management-system'),
        ));
    }

    public function ajax_test_email() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(__('Please enter a valid email address.', 'guest-management-system'));
        }

        $email_handler = new GMS_Email_Handler();
        $subject = __('Test Email from Guest Management System', 'guest-management-system');
        $message = __('This is a test email to confirm your notification settings are working.', 'guest-management-system');

        $sent = $email_handler->sendEmail($email, $subject, $message);

        if (!$sent) {
            wp_send_json_error(__('Failed to send test email. Please verify your email configuration.', 'guest-management-system'));
        }

        wp_send_json_success(array(
            'message' => __('Test email sent successfully.', 'guest-management-system'),
        ));
    }

    public function ajax_resend_notification() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $reservation_id = isset($_POST['reservation_id']) ? absint(wp_unslash($_POST['reservation_id'])) : 0;

        if (!$reservation_id) {
            wp_send_json_error(__('Invalid reservation selected.', 'guest-management-system'));
        }

        $reservation = GMS_Database::getReservationById($reservation_id);

        if (!$reservation) {
            wp_send_json_error(__('The reservation could not be found.', 'guest-management-system'));
        }

        $results = $this->send_reservation_notifications($reservation);

        if (!$results['email_sent'] && !$results['sms_sent']) {
            wp_send_json_error(__('Notifications could not be sent for this reservation.', 'guest-management-system'));
        }

        wp_send_json_success(array(
            'results' => $results,
        ));
    }

    public function ajax_bulk_action() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $action = isset($_POST['bulk_action']) ? sanitize_key(wp_unslash($_POST['bulk_action'])) : '';
        $ids_raw = isset($_POST['reservation_ids']) ? (array) wp_unslash($_POST['reservation_ids']) : array();
        $reservation_ids = array_filter(array_map('absint', $ids_raw));

        if (empty($action) || empty($reservation_ids)) {
            wp_send_json_error(__('Please select at least one reservation and a valid action.', 'guest-management-system'));
        }

        $processed = 0;
        $errors = array();

        if (strpos($action, 'mark_') === 0) {
            $status = str_replace('mark_', '', $action);
            $status = str_replace('-', '_', $status);

            foreach ($reservation_ids as $reservation_id) {
                $updated = GMS_Database::updateReservation($reservation_id, array('status' => $status));
                if ($updated) {
                    $processed++;
                } else {
                    $errors[] = sprintf(__('Failed to update reservation #%d.', 'guest-management-system'), $reservation_id);
                }
            }
        } elseif ($action === 'resend_notifications' || $action === 'resend_notification') {
            foreach ($reservation_ids as $reservation_id) {
                $reservation = GMS_Database::getReservationById($reservation_id);

                if (!$reservation) {
                    $errors[] = sprintf(__('Reservation #%d could not be found.', 'guest-management-system'), $reservation_id);
                    continue;
                }

                $results = $this->send_reservation_notifications($reservation);
                if ($results['email_sent'] || $results['sms_sent']) {
                    $processed++;
                } else {
                    $errors[] = sprintf(__('Notifications failed for reservation #%d.', 'guest-management-system'), $reservation_id);
                }
            }
        } else {
            wp_send_json_error(__('Unsupported bulk action.', 'guest-management-system'));
        }

        if ($processed === 0) {
            $message = !empty($errors)
                ? implode(' ', $errors)
                : __('No reservations were processed.', 'guest-management-system');

            wp_send_json_error($message);
        }

        $response = array(
            'processed' => $processed,
        );

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        wp_send_json_success($response);
    }

    public function ajax_autosave_template() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $field = isset($_POST['field']) ? sanitize_key(wp_unslash($_POST['field'])) : '';
        $value = isset($_POST['value']) ? wp_unslash($_POST['value']) : '';

        $allowed = array(
            'gms_agreement_template' => 'sanitize_template',
            'gms_email_template' => 'sanitize_template',
            'gms_sms_template' => 'sanitize_plain_textarea',
            'gms_sms_reminder_template' => 'sanitize_plain_textarea',
        );

        if (!array_key_exists($field, $allowed)) {
            wp_send_json_error(__('This template cannot be auto-saved.', 'guest-management-system'));
        }

        $callback = $allowed[$field];
        $sanitized_value = call_user_func(array($this, $callback), $value);

        update_option($field, $sanitized_value);

        wp_send_json_success(array(
            'message' => __('Template saved.', 'guest-management-system'),
        ));
    }

    public function ajax_refresh_stats() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $upcoming_checkins = gms_get_upcoming_checkins(7);
        $pending_checkins = gms_get_pending_checkins();

        $stats = array(
            'total_reservations' => GMS_Database::get_record_count('reservations'),
            'upcoming_checkins' => is_array($upcoming_checkins) ? count($upcoming_checkins) : 0,
            'pending_checkins' => is_array($pending_checkins) ? count($pending_checkins) : 0,
            'total_guests' => GMS_Database::get_record_count('guests'),
            'refreshed_at' => current_time('mysql'),
        );

        wp_send_json_success(array(
            'stats' => $stats,
        ));
    }

    public function handle_save_message_template() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to manage templates.', 'guest-management-system'));
        }

        check_admin_referer('gms_save_message_template');

        $template_id = isset($_POST['template_id']) ? absint(wp_unslash($_POST['template_id'])) : 0;
        $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';
        $channel = isset($_POST['channel']) ? sanitize_key(wp_unslash($_POST['channel'])) : 'sms';
        $content = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
        $is_active = isset($_POST['is_active']) && (int) $_POST['is_active'] === 1;

        $data = array(
            'label' => $label,
            'channel' => $channel,
            'content' => $content,
            'is_active' => $is_active ? 1 : 0,
        );

        if ($template_id > 0) {
            $result = GMS_Database::updateMessageTemplate($template_id, $data);
            $notice_key = 'updated';
        } else {
            $result = GMS_Database::createMessageTemplate($data);
            $notice_key = 'created';
            if (!is_wp_error($result)) {
                $template_id = intval($result);
            }
        }

        $notice = 'created';
        $message = '';

        if (is_wp_error($result)) {
            $notice = 'error';
            $message = wp_strip_all_tags($result->get_error_message());
        } else {
            $notice = $notice_key;
            if ($notice === 'created') {
                $message = __('Template created successfully.', 'guest-management-system');
            } elseif ($notice === 'updated') {
                $message = __('Template updated successfully.', 'guest-management-system');
            }
        }

        $redirect_url = wp_get_referer();
        $base_url = admin_url('admin.php?page=guest-management-templates');

        if (!$redirect_url || strpos($redirect_url, 'guest-management-templates') === false) {
            $redirect_url = $base_url;
        }

        $redirect_url = remove_query_arg(array('gms_template_notice', 'gms_template_message', 'action', 'template_id'), $redirect_url);

        $args = array(
            'gms_template_notice' => $notice,
        );

        if ($message !== '') {
            $args['gms_template_message'] = rawurlencode($message);
        }

        if ($notice === 'error' && $template_id > 0) {
            $args['action'] = 'edit_template';
            $args['template_id'] = $template_id;
        }

        $redirect_url = add_query_arg($args, $redirect_url);

        wp_safe_redirect($redirect_url);
        exit;
    }

    public function handle_delete_message_template() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to delete templates.', 'guest-management-system'));
        }

        $template_id = isset($_GET['template_id']) ? absint(wp_unslash($_GET['template_id'])) : 0;
        check_admin_referer('gms_delete_message_template_' . $template_id);

        $result = GMS_Database::deleteMessageTemplate($template_id);

        $notice = 'deleted';
        $message = __('Template deleted successfully.', 'guest-management-system');

        if (is_wp_error($result)) {
            $notice = 'error';
            $message = wp_strip_all_tags($result->get_error_message());
        }

        $redirect_url = wp_get_referer();
        $base_url = admin_url('admin.php?page=guest-management-templates');

        if (!$redirect_url || strpos($redirect_url, 'guest-management-templates') === false) {
            $redirect_url = $base_url;
        }

        $redirect_url = remove_query_arg(array('gms_template_notice', 'gms_template_message', 'action', 'template_id'), $redirect_url);
        $redirect_url = add_query_arg(
            array(
                'gms_template_notice' => $notice,
                'gms_template_message' => rawurlencode($message),
            ),
            $redirect_url
        );

        wp_safe_redirect($redirect_url);
        exit;
    }
    public function ajax_get_reservation() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $reservation_id = isset($_REQUEST['reservation_id']) ? absint(wp_unslash($_REQUEST['reservation_id'])) : 0;

        if (!$reservation_id) {
            wp_send_json_error(__('Invalid reservation ID supplied.', 'guest-management-system'));
        }

        $reservation = GMS_Database::getReservationById($reservation_id);

        if (!$reservation) {
            wp_send_json_error(__('Reservation not found.', 'guest-management-system'));
        }

        $response = $this->prepare_reservation_response($reservation);

        wp_send_json_success($response);
    }

    public function ajax_update_reservation() {
        check_ajax_referer('gms_admin_nonce', 'nonce');
        $this->ensure_ajax_permissions();

        $reservation_id = isset($_POST['reservation_id']) ? absint(wp_unslash($_POST['reservation_id'])) : 0;

        if (!$reservation_id) {
            wp_send_json_error(__('Invalid reservation ID supplied.', 'guest-management-system'));
        }

        $payload = isset($_POST['reservation']) ? wp_unslash($_POST['reservation']) : array();

        if (!is_array($payload)) {
            $payload = array();
        }

        $allowed = array(
            'guest_name',
            'guest_email',
            'guest_phone',
            'property_name',
            'booking_reference',
            'checkin_date',
            'checkout_date',
            'status',
        );

        $update_data = array();
        $sanitizers = array(
            'guest_name' => 'sanitize_text_field',
            'guest_email' => 'sanitize_email',
            'guest_phone' => 'sanitize_text_field',
            'property_name' => 'sanitize_text_field',
            'booking_reference' => 'sanitize_text_field',
            'checkin_date' => 'sanitize_text_field',
            'checkout_date' => 'sanitize_text_field',
            'status' => 'sanitize_key',
        );

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];

            if (is_array($value)) {
                continue;
            }

            if (isset($sanitizers[$field]) && is_callable($sanitizers[$field])) {
                $value = call_user_func($sanitizers[$field], $value);
            }

            $update_data[$field] = $value;
        }

        if (!empty($update_data)) {
            $updated = GMS_Database::updateReservation($reservation_id, $update_data);

            if (!$updated) {
                wp_send_json_error(__('Unable to save the reservation. Please try again.', 'guest-management-system'));
            }
        }

        $reservation = GMS_Database::getReservationById($reservation_id);

        if (!$reservation) {
            wp_send_json_error(__('Unable to load the updated reservation.', 'guest-management-system'));
        }

        $response = $this->prepare_reservation_response($reservation);

        wp_send_json_success($response);
    }


    /**
     * Return available settings tabs
     */
    private function get_settings_tabs() {
        return array(
            'general' => __('General', 'guest-management-system'),
            'integrations' => __('Integrations', 'guest-management-system'),
            'branding' => __('Branding', 'guest-management-system'),
            'templates' => __('Templates', 'guest-management-system')
        );
    }

    /**
     * Register plugin settings and sections
     */
    public function register_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }
        // General tab - contact defaults
        register_setting('gms_settings_general', 'gms_email_from_name', array(
            'sanitize_callback' => array($this, 'sanitize_text')
        ));
        register_setting('gms_settings_general', 'gms_email_from', array(
            'sanitize_callback' => array($this, 'sanitize_email')
        ));

        add_settings_section(
            'gms_general_contact',
            __('Contact Defaults', 'guest-management-system'),
            function() {
                echo '<p>' . esc_html__('Configure the default sender details used for guest communications.', 'guest-management-system') . '</p>';
            },
            'gms_settings_general'
        );

        add_settings_field(
            'gms_email_from_name',
            __('From Name', 'guest-management-system'),
            array($this, 'render_text_field'),
            'gms_settings_general',
            'gms_general_contact',
            array(
                'option_name' => 'gms_email_from_name',
                'placeholder' => get_option('blogname'),
                'description' => __('Displayed as the sender name in outgoing emails.', 'guest-management-system')
            )
        );

        add_settings_field(
            'gms_email_from',
            __('From Email', 'guest-management-system'),
            array($this, 'render_email_field'),
            'gms_settings_general',
            'gms_general_contact',
            array(
                'option_name' => 'gms_email_from',
                'placeholder' => get_option('admin_email'),
                'description' => __('Used as the reply-to address for guest notifications.', 'guest-management-system')
            )
        );

        add_settings_section(
            'gms_general_staff_access',
            __('Staff Access', 'guest-management-system'),
            function() {
                echo '<p>' . esc_html__('Share read-only dashboards with property staff who do not require full admin access.', 'guest-management-system') . '</p>';
            },
            'gms_settings_general'
        );

        add_settings_field(
            'gms_staff_overview_link',
            __('Staff Overview Link', 'guest-management-system'),
            array($this, 'render_staff_overview_link_field'),
            'gms_settings_general',
            'gms_general_staff_access'
        );

        // Integrations tab - API credentials
        $integration_options = array(
            'gms_stripe_pk' => array('label' => __('Stripe Publishable Key', 'guest-management-system')),
            'gms_stripe_sk' => array('label' => __('Stripe Secret Key', 'guest-management-system')),
            'gms_stripe_identity_restricted_key' => array(
                'label' => __('Stripe Identity Restricted Key', 'guest-management-system'),
                'description' => __('Use a restricted key with Identity permissions to view verification files inside Guest Profiles.', 'guest-management-system'),
            ),
            'gms_stripe_webhook_secret' => array('label' => __('Stripe Webhook Secret', 'guest-management-system')),
            'gms_voipms_user' => array('label' => __('VoIP.ms Username', 'guest-management-system')),
            'gms_voipms_pass' => array('label' => __('VoIP.ms API Password', 'guest-management-system')),
            'gms_voipms_did' => array('label' => __('VoIP.ms SMS DID', 'guest-management-system')),
            'gms_airbnb_access_token' => array(
                'label' => __('Airbnb Messaging Token', 'guest-management-system'),
                'description' => __('Required to send reservation updates directly to Airbnb inboxes.', 'guest-management-system'),
            ),
            'gms_vrbo_access_token' => array(
                'label' => __('VRBO Messaging Token', 'guest-management-system'),
                'description' => __('Enable VRBO in-app messaging when guest contact details are hidden.', 'guest-management-system'),
            ),
            'gms_booking_access_token' => array(
                'label' => __('Booking.com Messaging Token', 'guest-management-system'),
                'description' => __('Used for posting messages to Booking.com guest conversations.', 'guest-management-system'),
            ),
            'gms_shortener_api_token' => array(
                'label' => __('Link Shortener API Token', 'guest-management-system'),
                'description' => __('Bearer token used when requesting short links from the 240jv.link Cloudflare Worker.', 'guest-management-system'),
            ),
            'gms_webhook_token' => array('label' => __('Webhook Shared Secret', 'guest-management-system')),
            'gms_roku_api_token' => array(
                'label' => __('Roku API Token', 'guest-management-system'),
                'description' => __('Authenticate the Jordan View Roku app by sending this token in the X-Roku-Token header.', 'guest-management-system'),
            ),
        );

        foreach ($integration_options as $option => $meta) {
            register_setting('gms_settings_integrations', $option, array(
                'sanitize_callback' => array($this, 'sanitize_api_credential')
            ));
        }

        add_settings_section(
            'gms_integrations_section',
            __('API Integrations', 'guest-management-system'),
            array($this, 'render_integrations_help'),
            'gms_settings_integrations'
        );

        foreach ($integration_options as $option => $meta) {
            add_settings_field(
                $option,
                $meta['label'],
                array($this, 'render_api_field'),
                'gms_settings_integrations',
                'gms_integrations_section',
                array(
                    'option_name' => $option,
                    'description' => isset($meta['description']) ? $meta['description'] : ''
                )
            );
        }

        register_setting('gms_settings_integrations', 'gms_roku_media_tag_prefix', array(
            'sanitize_callback' => array($this, 'sanitize_text')
        ));

        add_settings_field(
            'gms_roku_media_tag_prefix',
            __('Roku Gallery Tag Prefix', 'guest-management-system'),
            array($this, 'render_text_field'),
            'gms_settings_integrations',
            'gms_integrations_section',
            array(
                'option_name' => 'gms_roku_media_tag_prefix',
                'placeholder' => __('roku', 'guest-management-system'),
                'description' => __('Media library tag prefix applied to property-specific artwork for the Roku app.', 'guest-management-system'),
            )
        );

        // Branding tab - appearance
        register_setting('gms_settings_branding', 'gms_company_name', array(
            'sanitize_callback' => array($this, 'sanitize_text')
        ));
        register_setting('gms_settings_branding', 'gms_company_logo', array(
            'sanitize_callback' => array($this, 'sanitize_url')
        ));
        register_setting('gms_settings_branding', 'gms_portal_primary_color', array(
            'sanitize_callback' => array($this, 'sanitize_color')
        ));
        register_setting('gms_settings_branding', 'gms_portal_secondary_color', array(
            'sanitize_callback' => array($this, 'sanitize_color')
        ));

        add_settings_section(
            'gms_branding_section',
            __('Branding', 'guest-management-system'),
            function() {
                echo '<p>' . esc_html__('Customize the branding shown in guest emails and the portal experience.', 'guest-management-system') . '</p>';
            },
            'gms_settings_branding'
        );

        add_settings_field(
            'gms_company_name',
            __('Company Name', 'guest-management-system'),
            array($this, 'render_text_field'),
            'gms_settings_branding',
            'gms_branding_section',
            array(
                'option_name' => 'gms_company_name',
                'placeholder' => get_option('blogname'),
                'description' => __('Displayed across the guest portal and in notification templates.', 'guest-management-system')
            )
        );

        add_settings_field(
            'gms_company_logo',
            __('Company Logo', 'guest-management-system'),
            array($this, 'render_logo_field'),
            'gms_settings_branding',
            'gms_branding_section',
            array(
                'option_name' => 'gms_company_logo',
                'description' => __('Upload a logo to display on guest emails and portal headers.', 'guest-management-system')
            )
        );

        add_settings_field(
            'gms_portal_primary_color',
            __('Primary Color', 'guest-management-system'),
            array($this, 'render_color_field'),
            'gms_settings_branding',
            'gms_branding_section',
            array(
                'option_name' => 'gms_portal_primary_color',
                'default' => '#0073aa',
                'description' => __('Used for primary buttons and accents.', 'guest-management-system')
            )
        );

        add_settings_field(
            'gms_portal_secondary_color',
            __('Secondary Color', 'guest-management-system'),
            array($this, 'render_color_field'),
            'gms_settings_branding',
            'gms_branding_section',
            array(
                'option_name' => 'gms_portal_secondary_color',
                'default' => '#005a87',
                'description' => __('Used for backgrounds and supporting UI elements.', 'guest-management-system')
            )
        );

        // Templates tab - communication content
        $template_options = array(
            'gms_agreement_template' => array(
                'label' => __('Guest Agreement Template', 'guest-management-system'),
                'description' => __('Displayed to guests prior to signing their agreement.', 'guest-management-system'),
                'sanitize_callback' => array($this, 'sanitize_template'),
                'rows' => 8
            ),
            'gms_email_template' => array(
                'label' => __('Welcome Email Template', 'guest-management-system'),
                'description' => __('Sent when a reservation is created. Supports HTML.', 'guest-management-system'),
                'sanitize_callback' => array($this, 'sanitize_template'),
                'rows' => 8
            ),
            'gms_approved_email_template' => array(
                'label' => __('Reservation Approved Email Template', 'guest-management-system'),
                'description' => __('Sent when a reservation transitions to Approved status. Supports HTML.', 'guest-management-system'),
                'sanitize_callback' => array($this, 'sanitize_template'),
                'rows' => 8
            ),
            'gms_sms_template' => array(
                'label' => __('Welcome SMS Template', 'guest-management-system'),
                'description' => __('Used for initial SMS notifications to guests.', 'guest-management-system'),
                'sanitize_callback' => array($this, 'sanitize_plain_textarea'),
                'rows' => 4
            ),
            'gms_approved_sms_template' => array(
                'label' => __('Reservation Approved SMS Template', 'guest-management-system'),
                'description' => __('Sent after approval so guests can complete portal tasks.', 'guest-management-system'),
                'sanitize_callback' => array($this, 'sanitize_plain_textarea'),
                'rows' => 4
            ),
            'gms_sms_reminder_template' => array(
                'label' => __('Reminder SMS Template', 'guest-management-system'),
                'description' => __('Used for reminder SMS follow-ups.', 'guest-management-system'),
                'sanitize_callback' => array($this, 'sanitize_plain_textarea'),
                'rows' => 4
            )
        );

        foreach ($template_options as $option => $meta) {
            register_setting('gms_settings_templates', $option, array(
                'sanitize_callback' => $meta['sanitize_callback']
            ));
        }

        add_settings_section(
            'gms_templates_section',
            __('Message Templates', 'guest-management-system'),
            array($this, 'render_template_tokens_help'),
            'gms_settings_templates'
        );

        foreach ($template_options as $option => $meta) {
            add_settings_field(
                $option,
                $meta['label'],
                array($this, 'render_textarea_field'),
                'gms_settings_templates',
                'gms_templates_section',
                array(
                    'option_name' => $option,
                    'description' => $meta['description'],
                    'rows' => $meta['rows']
                )
            );
        }
    }

    /**
     * Sanitize helper for generic text fields
     */
    public function sanitize_text($value) {
        return sanitize_text_field($value);
    }

    /**
     * Sanitize helper for emails
     */
    public function sanitize_email($value) {
        return sanitize_email($value);
    }

    /**
     * Sanitize helper for API credentials
     */
    public function sanitize_api_credential($value) {
        return trim(wp_strip_all_tags($value));
    }

    /**
     * Sanitize helper for URLs
     */
    public function sanitize_url($value) {
        return esc_url_raw($value);
    }

    /**
     * Sanitize helper for color values
     */
    public function sanitize_color($value) {
        $color = sanitize_hex_color($value);
        return $color ? $color : '';
    }

    /**
     * Sanitize template content allowing limited HTML
     */
    public function sanitize_template($value) {
        $allowed_tags = array(
            'a' => array('href' => array(), 'title' => array(), 'target' => array()),
            'br' => array(),
            'em' => array(),
            'strong' => array(),
            'p' => array(),
            'ul' => array(),
            'ol' => array(),
            'li' => array(),
        );

        return wp_kses($value, $allowed_tags);
    }

    /**
     * Sanitize plain text area fields
     */
    public function sanitize_plain_textarea($value) {
        return sanitize_textarea_field($value);
    }

    private function ensure_ajax_permissions() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'guest-management-system'));
        }
    }

    private function send_reservation_notifications($reservation) {
        $results = array(
            'email_sent' => false,
            'sms_sent' => false,
        );

        if (empty($reservation) || !is_array($reservation)) {
            return $results;
        }

        $status = isset($reservation['status']) ? sanitize_key($reservation['status']) : '';
        $approved_statuses = array('approved', 'awaiting_signature', 'awaiting_id_verification');

        if (!empty($reservation['guest_email']) && is_email($reservation['guest_email'])) {
            static $email_handler = null;

            if ($email_handler === null) {
                $email_handler = new GMS_Email_Handler();
            }

            if (in_array($status, $approved_statuses, true)) {
                $results['email_sent'] = $email_handler->sendReservationApprovedEmail($reservation);
            } else {
                $results['email_sent'] = $email_handler->sendWelcomeEmail($reservation);
            }
        }

        if (!empty($reservation['guest_phone'])) {
            static $sms_handler = null;

            if ($sms_handler === null) {
                $sms_handler = new GMS_SMS_Handler();
            }

            if (in_array($status, $approved_statuses, true)) {
                $results['sms_sent'] = $sms_handler->sendReservationApprovedSMS($reservation);
            } else {
                $results['sms_sent'] = $sms_handler->sendWelcomeSMS($reservation);
            }
        }

        return $results;
    }

    /**
     * Render a generic text input field
     */
    public function render_text_field($args) {
        $option_name = $args['option_name'];
        $value = get_option($option_name, '');
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        $description = isset($args['description']) ? $args['description'] : '';

        printf(
            '<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
            esc_attr($option_name),
            esc_attr($value),
            esc_attr($placeholder)
        );

        if (!empty($description)) {
            printf('<span class="description">%s</span>', esc_html($description));
        }
    }

    /**
     * Render an email input field
     */
    public function render_email_field($args) {
        $option_name = $args['option_name'];
        $value = get_option($option_name, '');
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        $description = isset($args['description']) ? $args['description'] : '';

        printf(
            '<input type="email" name="%1$s" id="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
            esc_attr($option_name),
            esc_attr($value),
            esc_attr($placeholder)
        );

        if (!empty($description)) {
            printf('<span class="description">%s</span>', esc_html($description));
        }
    }

    /**
     * Render the staff overview link field.
     */
    public function render_staff_overview_link_field() {
        if (!class_exists('GMS_Database')) {
            echo '<p class="description">' . esc_html__('Staff overview access is unavailable because the database layer is not loaded.', 'guest-management-system') . '</p>';

            return;
        }

        $token = GMS_Database::getStaffOverviewAccessToken();
        $url = ($token !== '' && function_exists('gms_build_staff_overview_url')) ? gms_build_staff_overview_url($token) : '';

        if ($url === '') {
            echo '<p class="description">' . esc_html__('No staff overview link is available yet. Activate the plugin or refresh this page to generate a token automatically.', 'guest-management-system') . '</p>';

            return;
        }

        $field_id = 'gms_staff_overview_url';

        printf(
            '<input type="text" readonly id="%1$s" class="regular-text code" value="%2$s" />',
            esc_attr($field_id),
            esc_attr($url)
        );

        printf(
            ' <button type="button" class="button gms-copy-trigger" data-copy-value="%1$s" data-copy-success="%2$s">%3$s</button>',
            esc_attr($url),
            esc_attr__('Staff overview link copied to clipboard.', 'guest-management-system'),
            esc_html__('Copy Link', 'guest-management-system')
        );

        echo '<p class="description">' . esc_html__('Share this read-only link with hotel managers who only need to view guest and reservation summaries.', 'guest-management-system') . '</p>';
        echo '<p class="description">' . esc_html__('Current access token:', 'guest-management-system') . ' <code>' . esc_html($token) . '</code></p>';
    }

    /**
     * Render API credential fields with monospace styling
     */
    public function render_api_field($args) {
        $option_name = $args['option_name'];
        $value = get_option($option_name, '');
        $description = isset($args['description']) ? $args['description'] : '';

        printf(
            '<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text api-key-field" autocomplete="off" />',
            esc_attr($option_name),
            esc_attr($value)
        );

        if (!empty($description)) {
            printf('<span class="description">%s</span>', esc_html($description));
        }
    }

    /**
     * Render color picker fields
     */
    public function render_color_field($args) {
        $option_name = $args['option_name'];
        $default = isset($args['default']) ? $args['default'] : '#0073aa';
        $value = get_option($option_name, $default);
        $description = isset($args['description']) ? $args['description'] : '';

        printf(
            '<input type="color" name="%1$s" id="%1$s" value="%2$s" />',
            esc_attr($option_name),
            esc_attr($value)
        );

        if (!empty($description)) {
            printf('<span class="description">%s</span>', esc_html($description));
        }
    }

    /**
     * Render logo upload field with preview
     */
    public function render_logo_field($args) {
        $option_name = $args['option_name'];
        $value = get_option($option_name, '');
        $description = isset($args['description']) ? $args['description'] : '';
        $preview_id = $option_name . '_preview';

        echo '<div class="gms-logo-field">';
        printf(
            '<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
            esc_attr($option_name),
            esc_attr($value),
            esc_attr__('https://example.com/logo.png', 'guest-management-system')
        );
        echo '<br />';
        printf(
            '<button type="button" class="button gms-upload-logo" data-target="%1$s" data-preview="%2$s">%3$s</button> ',
            esc_attr($option_name),
            esc_attr($preview_id),
            esc_html__('Upload Logo', 'guest-management-system')
        );
        printf(
            '<button type="button" class="button-secondary gms-remove-logo" data-target="%1$s" data-preview="%2$s">%3$s</button>',
            esc_attr($option_name),
            esc_attr($preview_id),
            esc_html__('Remove', 'guest-management-system')
        );

        if (!empty($description)) {
            printf('<span class="description">%s</span>', esc_html($description));
        }

        $preview_style = empty($value) ? 'style="display:none;"' : '';
        printf(
            '<div id="%1$s" class="gms-logo-preview" %3$s>%2$s</div>',
            esc_attr($preview_id),
            $value ? '<img src="' . esc_url($value) . '" alt="" style="max-width:200px;height:auto;margin-top:10px;" />' : '',
            $preview_style
        );
        echo '</div>';
    }

    /**
     * Render textarea fields used for templates
     */
    public function render_textarea_field($args) {
        $option_name = $args['option_name'];
        $value = get_option($option_name, '');
        $description = isset($args['description']) ? $args['description'] : '';
        $rows = isset($args['rows']) ? intval($args['rows']) : 6;

        printf(
            '<textarea name="%1$s" id="%1$s" rows="%3$d" class="large-text code">%2$s</textarea>',
            esc_attr($option_name),
            esc_textarea($value),
            $rows
        );

        if (!empty($description)) {
            printf('<span class="description">%s</span>', esc_html($description));
        }
    }

    /**
     * Render contextual help for template tokens
     */
    public function render_template_tokens_help() {
        $tokens = array(
            '{guest_name}' => __('Guest name', 'guest-management-system'),
            '{property_name}' => __('Property name', 'guest-management-system'),
            '{booking_reference}' => __('Reservation confirmation or reference number', 'guest-management-system'),
            '{checkin_date}' => __('Formatted check-in date', 'guest-management-system'),
            '{checkout_date}' => __('Formatted check-out date', 'guest-management-system'),
            '{checkin_time}' => __('Formatted check-in time', 'guest-management-system'),
            '{checkout_time}' => __('Formatted check-out time', 'guest-management-system'),
            '{portal_link}' => __('Secure guest portal link', 'guest-management-system'),
            '{company_name}' => __('Configured company name', 'guest-management-system')
        );

        echo '<p>' . esc_html__('Use the tokens below to personalize email, SMS, and agreement templates. Tokens will be replaced with reservation data when messages are sent.', 'guest-management-system') . '</p>';

        echo '<div class="notice notice-info inline"><p><strong>' . esc_html__('Available Tokens:', 'guest-management-system') . '</strong></p><ul>';
        foreach ($tokens as $token => $description) {
            printf('<li><code>%1$s</code> — %2$s</li>', esc_html($token), esc_html($description));
        }
        echo '</ul></div>';
    }

    /**
     * Render contextual guidance for integrations, including webhook endpoints
     */
    public function render_integrations_help() {
        echo '<p>' . esc_html__('Provide credentials for payment processing, SMS delivery, OTA inbox messaging, URL shortening, and webhook security.', 'guest-management-system') . '</p>';

        $webhook_urls = function_exists('gms_get_webhook_urls') ? gms_get_webhook_urls() : array();

        if (empty($webhook_urls) || !is_array($webhook_urls)) {
            return;
        }

        echo '<div class="notice notice-info inline webhook-url-box">';
        echo '<p><strong>' . esc_html__('Webhook Endpoints', 'guest-management-system') . '</strong></p>';
        echo '<ul>';

        foreach ($webhook_urls as $platform => $url) {
            if (empty($url)) {
                continue;
            }

            $label = ucwords(str_replace(array('-', '_'), ' ', $platform));
            printf(
                '<li><span class="webhook-label">%1$s:</span> <code>%2$s</code></li>',
                esc_html($label),
                esc_html($url)
            );
        }

        echo '</ul>';
        echo '<p>' . esc_html__('Authenticate webhook requests by sending the shared secret saved below as the X-Webhook-Token header or as a webhook_token query parameter.', 'guest-management-system') . '</p>';
        echo '</div>';

        echo '<p class="description">' . esc_html__('Add the Airbnb, VRBO, and Booking.com messaging tokens above to unlock automated OTA conversations when guest contact details are hidden.', 'guest-management-system') . '</p>';
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

        $reservations_hook = add_submenu_page(
            'guest-management-dashboard',
            'Reservations',
            'Reservations',
            'manage_options',
            'guest-management-reservations',
            [$this, 'render_reservations_page']
        );

        $guests_hook = add_submenu_page(
            'guest-management-dashboard',
            'Guest Profiles',
            'Guest Profiles',
            'manage_options',
            'guest-management-guests',
            [$this, 'render_guests_page']
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Housekeeper Schedule',
            'Housekeeper',
            'manage_options',
            'guest-management-housekeeper',
            [$this, 'render_housekeeper_page']
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Messaging Inbox',
            'Messaging',
            'manage_options',
            'guest-management-messaging',
            [$this, 'render_communications_page']
        );

        add_submenu_page(
            'guest-management-dashboard',
            'Activity Logs',
            'Logs',
            'manage_options',
            'guest-management-logs',
            [$this, 'render_logs_page']
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

        if (!empty($reservations_hook)) {
            add_action('load-' . $reservations_hook, [$this, 'handle_reservations_actions']);
        }

        if (!empty($guests_hook)) {
            add_action('load-' . $guests_hook, [$this, 'handle_guests_actions']);
        }
    }

    public function handle_reservations_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['action']) && sanitize_key(wp_unslash($_GET['action'])) === 'delete') {
            $reservation_id = isset($_GET['reservation_id']) ? absint(wp_unslash($_GET['reservation_id'])) : 0;
            $nonce = isset($_GET['_wpnonce']) ? wp_unslash($_GET['_wpnonce']) : '';

            $redirect_url = $this->build_reservations_redirect_url();

            if ($reservation_id > 0 && wp_verify_nonce($nonce, 'gms_delete_reservation_' . $reservation_id)) {
                $deleted = GMS_Database::delete_reservations(array($reservation_id));

                if ($deleted > 0) {
                    $redirect_url = add_query_arg('gms_reservation_deleted', $deleted, $redirect_url);
                } else {
                    $redirect_url = add_query_arg('gms_reservation_delete_error', 1, $redirect_url);
                }
            } else {
                $redirect_url = add_query_arg('gms_reservation_delete_error', 1, $redirect_url);
            }

            wp_safe_redirect($redirect_url);
            exit;
        }

        $primary_action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        $secondary_action = isset($_REQUEST['action2']) ? sanitize_key(wp_unslash($_REQUEST['action2'])) : '';
        $current_action = $primary_action !== '-1' ? $primary_action : $secondary_action;

        if ($current_action !== 'delete') {
            return;
        }

        $redirect_url = $this->build_reservations_redirect_url();

        $nonce = isset($_REQUEST['_wpnonce']) ? wp_unslash($_REQUEST['_wpnonce']) : '';
        if (!wp_verify_nonce($nonce, 'bulk-reservations')) {
            $redirect_url = add_query_arg('gms_reservation_delete_error', 1, $redirect_url);
            wp_safe_redirect($redirect_url);
            exit;
        }

        $ids = isset($_REQUEST['reservation']) ? array_map('absint', (array) $_REQUEST['reservation']) : array();
        $ids = array_filter($ids);

        if (empty($ids)) {
            wp_safe_redirect($redirect_url);
            exit;
        }

        $deleted = GMS_Database::delete_reservations($ids);

        if ($deleted > 0) {
            $redirect_url = add_query_arg('gms_reservation_deleted', $deleted, $redirect_url);
        } else {
            $redirect_url = add_query_arg('gms_reservation_delete_error', 1, $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    public function handle_guests_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['action']) && sanitize_key(wp_unslash($_GET['action'])) === 'delete') {
            $guest_id = isset($_GET['guest_id']) ? absint(wp_unslash($_GET['guest_id'])) : 0;
            $nonce = isset($_GET['_wpnonce']) ? wp_unslash($_GET['_wpnonce']) : '';

            $redirect_url = $this->build_guests_redirect_url();

            if ($guest_id > 0 && wp_verify_nonce($nonce, 'gms_delete_guest_' . $guest_id)) {
                $deleted = GMS_Database::delete_guests(array($guest_id));

                if ($deleted > 0) {
                    $redirect_url = add_query_arg('gms_guest_deleted', $deleted, $redirect_url);
                } else {
                    $redirect_url = add_query_arg('gms_guest_delete_error', 1, $redirect_url);
                }
            } else {
                $redirect_url = add_query_arg('gms_guest_delete_error', 1, $redirect_url);
            }

            wp_safe_redirect($redirect_url);
            exit;
        }

        $primary_action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        $secondary_action = isset($_REQUEST['action2']) ? sanitize_key(wp_unslash($_REQUEST['action2'])) : '';
        $current_action = $primary_action !== '-1' ? $primary_action : $secondary_action;

        if ($current_action !== 'delete') {
            return;
        }

        $redirect_url = $this->build_guests_redirect_url();

        $nonce = isset($_REQUEST['_wpnonce']) ? wp_unslash($_REQUEST['_wpnonce']) : '';
        if (!wp_verify_nonce($nonce, 'bulk-guests')) {
            $redirect_url = add_query_arg('gms_guest_delete_error', 1, $redirect_url);
            wp_safe_redirect($redirect_url);
            exit;
        }

        $ids = isset($_REQUEST['guest']) ? array_map('absint', (array) $_REQUEST['guest']) : array();
        $ids = array_filter($ids);

        if (empty($ids)) {
            wp_safe_redirect($redirect_url);
            exit;
        }

        $deleted = GMS_Database::delete_guests($ids);

        if ($deleted > 0) {
            $redirect_url = add_query_arg('gms_guest_deleted', $deleted, $redirect_url);
        } else {
            $redirect_url = add_query_arg('gms_guest_delete_error', 1, $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    public function render_housekeeper_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $reservations = $this->get_housekeeper_reservations();
        $blueprint = GMS_Housekeeper_Checklist_View::getBlueprint();
        $checklist_view = isset($_GET['gms_checklist_view']) ? sanitize_key(wp_unslash($_GET['gms_checklist_view'])) : 'completed';
        if (!in_array($checklist_view, array('completed', 'pending'), true)) {
            $checklist_view = 'completed';
        }

        $submissions = GMS_Database::getHousekeeperSubmissions(array(
            'limit' => 50,
            'order' => 'DESC',
        ));
        $submissions_by_reservation = array();
        $latest_submission_id = 0;

        foreach ($submissions as &$submission) {
            if (!is_array($submission['payload'])) {
                $submission['payload'] = array();
            }

            $submission['photos'] = GMS_Database::getHousekeeperPhotos(array(
                'submission_id' => intval($submission['id']),
            ));

            $latest_submission_id = max($latest_submission_id, intval($submission['id']));

            $reservation_id = isset($submission['reservation_id']) ? intval($submission['reservation_id']) : 0;
            if ($reservation_id > 0 && !isset($submissions_by_reservation[$reservation_id])) {
                $submissions_by_reservation[$reservation_id] = $submission;
            }
        }
        unset($submission);

        $reservation_map = array();
        foreach ($reservations as $reservation_item) {
            $reservation_map[$reservation_item['id']] = $reservation_item;
        }

        $reservations = array_values(array_filter($reservations, function($reservation_item) use ($submissions_by_reservation) {
            $reservation_id = isset($reservation_item['id']) ? intval($reservation_item['id']) : 0;

            if ($reservation_id <= 0) {
                return true;
            }

            return !isset($submissions_by_reservation[$reservation_id]);
        }));

        $now_timestamp = current_time('timestamp');
        $pending_reservations = array();

        foreach ($reservations as $reservation_item) {
            $reservation_id = $reservation_item['id'];

            if (isset($submissions_by_reservation[$reservation_id])) {
                continue;
            }

            if ($reservation_item['checkout_ts'] <= $now_timestamp) {
                $pending_reservations[] = $reservation_item;
            }
        }

        $pending_count = count($pending_reservations);
        $blueprint_index = $this->index_housekeeper_blueprint($blueprint);

        $timezone = wp_timezone();
        $current_timestamp = $now_timestamp;
        $default_month = wp_date('Y-m', $current_timestamp, $timezone);

        $requested_month = isset($_GET['gms_housekeeper_month']) ? sanitize_text_field(wp_unslash($_GET['gms_housekeeper_month'])) : '';
        if (!preg_match('/^\d{4}-\d{2}$/', $requested_month)) {
            $requested_month = $default_month;
        }

        $initial_view = isset($_GET['housekeeper_view']) ? sanitize_key(wp_unslash($_GET['housekeeper_view'])) : 'list';
        if (!in_array($initial_view, array('list', 'calendar'), true)) {
            $initial_view = 'list';
        }

        try {
            $month_start = new \DateTimeImmutable($requested_month . '-01 00:00:00', $timezone);
        } catch (\Exception $exception) {
            $month_start = new \DateTimeImmutable($default_month . '-01 00:00:00', $timezone);
        }

        $days_in_month = (int) $month_start->format('t');
        $month_label = wp_date('F Y', $month_start->getTimestamp(), $timezone);

        $prev_month_object = $month_start->modify('-1 month');
        $next_month_object = $month_start->modify('+1 month');

        $prev_month_value = $prev_month_object->format('Y-m');
        $next_month_value = $next_month_object->format('Y-m');

        $prev_month_label = wp_date('M Y', $prev_month_object->getTimestamp(), $timezone);
        $next_month_label = wp_date('M Y', $next_month_object->getTimestamp(), $timezone);

        $calendar_start = $month_start->modify('-' . (int) $month_start->format('w') . ' days');
        $month_end = $month_start->modify('+' . ($days_in_month - 1) . ' days');
        $calendar_end = $month_end->modify('+' . (6 - (int) $month_end->format('w')) . ' days');

        $calendar_period = new \DatePeriod($calendar_start, new \DateInterval('P1D'), $calendar_end->modify('+1 day'));
        $weeks = array();
        $current_week = array();
        $today = new \DateTimeImmutable('today', $timezone);

        $weekday_labels = array();
        for ($i = 0; $i < 7; $i++) {
            $reference = $calendar_start->modify('+' . $i . ' days');
            $weekday_labels[] = array(
                'short' => wp_date('D', $reference->getTimestamp(), $timezone),
                'long' => wp_date('l', $reference->getTimestamp(), $timezone),
            );
        }

        foreach ($calendar_period as $day) {
            $day_start = $day->setTime(0, 0);
            $day_end = $day->setTime(23, 59, 59);
            $day_timestamp = $day_start->getTimestamp();

            $day_reservations = array();

            foreach ($reservations as $reservation) {
                if ($reservation['checkin_ts'] <= $day_end->getTimestamp() && $reservation['checkout_ts'] > $day_timestamp) {
                    $day_reservations[] = array(
                        'details' => $reservation,
                        'arrives' => $reservation['checkin_day_ts'] === $day_timestamp,
                        'departs' => $reservation['checkout_day_ts'] === $day_timestamp,
                    );
                }
            }

            $current_week[] = array(
                'date' => $day,
                'is_current_month' => $day->format('m') === $month_start->format('m'),
                'is_today' => $day_start->format('Y-m-d') === $today->format('Y-m-d'),
                'reservations' => $day_reservations,
            );

            if (count($current_week) === 7) {
                $weeks[] = $current_week;
                $current_week = array();
            }
        }

        usort($reservations, function($a, $b) {
            return $a['checkin_ts'] <=> $b['checkin_ts'];
        });

        $reservation_count = count($reservations);
        $upcoming_week_cutoff = (new \DateTimeImmutable('today', $timezone))->modify('+7 days')->getTimestamp();
        $upcoming_week_count = 0;
        foreach ($reservations as $reservation) {
            if ($reservation['checkin_ts'] < $upcoming_week_cutoff) {
                $upcoming_week_count++;
            }
        }

        $base_url_args = array('page' => 'guest-management-housekeeper');
        if ($checklist_view !== 'completed') {
            $base_url_args['gms_checklist_view'] = $checklist_view;
        }
        $base_url = add_query_arg($base_url_args, admin_url('admin.php'));
        $prev_month_url = add_query_arg('gms_housekeeper_month', $prev_month_value, $base_url);
        $next_month_url = add_query_arg('gms_housekeeper_month', $next_month_value, $base_url);

        $ical_url = wp_nonce_url(admin_url('admin-post.php?action=gms_housekeeper_ical'), 'gms_housekeeper_ical');

        $list_aria_hidden = $initial_view === 'list' ? 'false' : 'true';
        $calendar_aria_hidden = $initial_view === 'calendar' ? 'false' : 'true';

        $list_button_classes = 'button button-secondary' . ($initial_view === 'list' ? ' is-active' : '');
        $calendar_button_classes = 'button button-secondary' . ($initial_view === 'calendar' ? ' is-active' : '');

        $housekeeper_cta_statuses = array('completed');
        if (function_exists('apply_filters')) {
            $housekeeper_cta_statuses = apply_filters('gms_housekeeper_cta_statuses', $housekeeper_cta_statuses);
        }

        if (!is_array($housekeeper_cta_statuses)) {
            $housekeeper_cta_statuses = array();
        }

        $housekeeper_cta_statuses = array_values(array_unique(array_filter(array_map(
            function($status) {
                if (!is_scalar($status)) {
                    return '';
                }

                $sanitized = sanitize_key($status);

                return $sanitized !== '' ? $sanitized : '';
            },
            $housekeeper_cta_statuses
        ))));

        $housekeeper_cta_locked_message = __('The checklist link unlocks after the stay is completed.', 'guest-management-system');

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Housekeeper Schedule', 'guest-management-system'); ?></h1>
            <p class="description"><?php esc_html_e('Keep your cleaning team informed with a quick overview of upcoming stays and turn days.', 'guest-management-system'); ?></p>

            <div class="gms-housekeeper" data-view="<?php echo esc_attr($initial_view); ?>">
                <div class="gms-housekeeper__summary">
                    <div class="gms-housekeeper__summary-item">
                        <span class="gms-housekeeper__summary-value"><?php echo esc_html(number_format_i18n($reservation_count)); ?></span>
                        <span class="gms-housekeeper__summary-label"><?php esc_html_e('Upcoming reservations', 'guest-management-system'); ?></span>
                    </div>
                    <div class="gms-housekeeper__summary-item">
                        <span class="gms-housekeeper__summary-value"><?php echo esc_html(number_format_i18n($upcoming_week_count)); ?></span>
                        <span class="gms-housekeeper__summary-label"><?php esc_html_e('Check-ins in the next 7 days', 'guest-management-system'); ?></span>
                    </div>
                    <div class="gms-housekeeper__summary-item">
                        <span class="gms-housekeeper__summary-value"><?php echo esc_html(number_format_i18n($pending_count)); ?></span>
                        <span class="gms-housekeeper__summary-label"><?php esc_html_e('Pending cleanings', 'guest-management-system'); ?></span>
                    </div>
                </div>

                <section class="gms-housekeeper__checklists" aria-labelledby="gms-housekeeper-checklists-title">
                    <header class="gms-housekeeper__checklists-header">
                        <div>
                            <h2 id="gms-housekeeper-checklists-title"><?php esc_html_e('Cleaning checklists', 'guest-management-system'); ?></h2>
                            <p class="gms-housekeeper__checklists-description"><?php esc_html_e('Review submitted reports or follow up on turnovers waiting for confirmation.', 'guest-management-system'); ?></p>
                        </div>
                        <nav class="gms-housekeeper__checklists-nav" aria-label="<?php esc_attr_e('Checklist filters', 'guest-management-system'); ?>">
                            <a href="<?php echo esc_url(add_query_arg('gms_checklist_view', 'completed', $base_url)); ?>" class="<?php echo $checklist_view === 'completed' ? 'is-active' : ''; ?>"><?php esc_html_e('Completed', 'guest-management-system'); ?></a>
                            <a href="<?php echo esc_url(add_query_arg('gms_checklist_view', 'pending', $base_url)); ?>" class="<?php echo $checklist_view === 'pending' ? 'is-active' : ''; ?>"><?php esc_html_e('Pending follow-up', 'guest-management-system'); ?></a>
                        </nav>
                    </header>
                    <?php if ($checklist_view === 'completed') : ?>
                        <?php if (empty($submissions)) : ?>
                            <p class="gms-housekeeper__checklists-empty"><?php esc_html_e('No cleaning checklists have been submitted yet.', 'guest-management-system'); ?></p>
                        <?php else : ?>
                            <div class="gms-housekeeper__checklist-grid">
                                <?php foreach ($submissions as $submission_item) :
                                    $reservation_id = isset($submission_item['reservation_id']) ? intval($submission_item['reservation_id']) : 0;
                                    $reservation_details = isset($reservation_map[$reservation_id]) ? $reservation_map[$reservation_id] : GMS_Database::getReservationById($reservation_id);
                                    $property_label = isset($reservation_details['property_name']) ? $reservation_details['property_name'] : '';
                                    $guest_label = isset($reservation_details['guest_name']) ? $reservation_details['guest_name'] : '';
                                    $submitted_at = isset($submission_item['submitted_at']) ? $submission_item['submitted_at'] : '';
                                    $submitted_at_display = $submitted_at && $submitted_at !== '0000-00-00 00:00:00' ? wp_date(get_option('date_format', 'M j, Y') . ' ' . get_option('time_format', 'g:i a'), strtotime($submitted_at), $timezone) : '';
                                    $sections = $this->build_housekeeper_submission_sections($blueprint, $submission_item);
                                    $checklist_link = GMS_Database::getHousekeeperLinkForReservation($reservation_id);
                                    ?>
                                    <article class="gms-housekeeper-checklist-card">
                                        <header class="gms-housekeeper-checklist-card__header">
                                            <div>
                                                <?php if ($property_label !== '') : ?>
                                                    <h3 class="gms-housekeeper-checklist-card__title"><?php echo esc_html($property_label); ?></h3>
                                                <?php endif; ?>
                                                <?php if ($guest_label !== '') : ?>
                                                    <p class="gms-housekeeper-checklist-card__guest"><?php echo esc_html($guest_label); ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($submitted_at_display !== '') : ?>
                                                <time datetime="<?php echo esc_attr($submitted_at); ?>" class="gms-housekeeper-checklist-card__submitted"><?php echo esc_html(sprintf(__('Submitted %s', 'guest-management-system'), $submitted_at_display)); ?></time>
                                            <?php endif; ?>
                                        </header>
                                        <?php if (!empty($submission_item['inventory_notes'])) : ?>
                                            <div class="gms-housekeeper-checklist-card__inventory">
                                                <strong><?php esc_html_e('Inventory notes', 'guest-management-system'); ?>:</strong>
                                                <p><?php echo wp_kses_post($submission_item['inventory_notes']); ?></p>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($sections)) : ?>
                                            <div class="gms-housekeeper-checklist-card__sections">
                                                <?php foreach ($sections as $section_view) : ?>
                                                    <details class="gms-housekeeper-checklist-section" <?php echo $section_view['expanded'] ? 'open' : ''; ?>>
                                                        <summary>
                                                            <span class="gms-housekeeper-checklist-section__phase"><?php echo esc_html($section_view['phase']); ?></span>
                                                            <span class="gms-housekeeper-checklist-section__label"><?php echo esc_html($section_view['label']); ?></span>
                                                        </summary>
                                                        <?php if (!empty($section_view['tasks'])) : ?>
                                                            <ul class="gms-housekeeper-checklist-tasks">
                                                                <?php foreach ($section_view['tasks'] as $task_view) : ?>
                                                                    <li class="<?php echo $task_view['complete'] ? 'is-complete' : 'is-missing'; ?>">
                                                                        <span class="dashicons <?php echo $task_view['complete'] ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>" aria-hidden="true"></span>
                                                                        <span><?php echo esc_html($task_view['label']); ?></span>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        <?php endif; ?>
                                                        <?php if (!empty($section_view['notes'])) : ?>
                                                            <div class="gms-housekeeper-checklist-notes">
                                                                <?php foreach ($section_view['notes'] as $note_view) : ?>
                                                                    <h4><?php echo esc_html($note_view['label']); ?></h4>
                                                                    <p><?php echo wp_kses_post($note_view['value']); ?></p>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($section_view['photos'])) : ?>
                                                            <div class="gms-housekeeper-checklist-photos">
                                                                <?php foreach ($section_view['photos'] as $photo_view) :
                                                                    $full_url = wp_get_attachment_url($photo_view['attachment_id']);
                                                                    $thumbnail = wp_get_attachment_image($photo_view['attachment_id'], 'medium', false, array('class' => 'gms-housekeeper-checklist-thumb'));
                                                                    if (!$full_url) {
                                                                        continue;
                                                                    }
                                                                    ?>
                                                                    <a href="<?php echo esc_url($full_url); ?>" class="thickbox" rel="gms-housekeeper-gallery-<?php echo esc_attr($submission_item['id']); ?>" title="<?php echo esc_attr($photo_view['label']); ?>">
                                                                        <?php echo $thumbnail ? $thumbnail : esc_html($photo_view['label']); ?>
                                                                    </a>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </details>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($checklist_link['url'])) : ?>
                                            <footer class="gms-housekeeper-checklist-card__footer">
                                                <a class="gms-housekeeper-checklist-card__link" href="<?php echo esc_url($checklist_link['url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open housekeeper link', 'guest-management-system'); ?></a>
                                            </footer>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php else : ?>
                        <?php if (empty($pending_reservations)) : ?>
                            <p class="gms-housekeeper__checklists-empty"><?php esc_html_e('No pending cleanings require attention.', 'guest-management-system'); ?></p>
                        <?php else : ?>
                            <ul class="gms-housekeeper-pending">
                                <?php foreach ($pending_reservations as $pending) :
                                    $link = GMS_Database::getHousekeeperLinkForReservation($pending['id']);
                                    ?>
                                    <li>
                                        <div>
                                            <p class="gms-housekeeper-pending__property"><?php echo esc_html($pending['property_name']); ?></p>
                                            <p class="gms-housekeeper-pending__meta"><?php echo esc_html(sprintf(__('Checked out %s', 'guest-management-system'), $pending['checkout_datetime_label'])); ?></p>
                                        </div>
                                        <?php if (!empty($link['url'])) : ?>
                                            <a class="button button-secondary" href="<?php echo esc_url($link['url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open checklist', 'guest-management-system'); ?></a>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <div class="gms-housekeeper__toolbar">
                    <div class="gms-housekeeper__toolbar-group">
                        <a class="button button-primary" href="<?php echo esc_url($ical_url); ?>"><?php esc_html_e('Download iCal', 'guest-management-system'); ?></a>
                    </div>
                    <div class="gms-housekeeper__view-toggle" role="group" aria-label="<?php esc_attr_e('Switch schedule view', 'guest-management-system'); ?>">
                        <button type="button" class="<?php echo esc_attr($list_button_classes); ?>" data-view="list" aria-pressed="<?php echo $initial_view === 'list' ? 'true' : 'false'; ?>"><?php esc_html_e('List', 'guest-management-system'); ?></button>
                        <button type="button" class="<?php echo esc_attr($calendar_button_classes); ?>" data-view="calendar" aria-pressed="<?php echo $initial_view === 'calendar' ? 'true' : 'false'; ?>"><?php esc_html_e('Calendar', 'guest-management-system'); ?></button>
                    </div>
                </div>

                <div class="gms-housekeeper__list" aria-hidden="<?php echo esc_attr($list_aria_hidden); ?>">
                    <?php if (empty($reservations)) : ?>
                        <div class="gms-housekeeper__empty"><?php esc_html_e('There are no upcoming reservations that require cleaning.', 'guest-management-system'); ?></div>
                    <?php else : ?>
                        <?php foreach ($reservations as $reservation) : ?>
                            <?php
                            $status = $reservation['status'];
                            $status_label = $status !== '' ? ucwords(str_replace('_', ' ', $status)) : '';
                            $status_modifier = $status !== '' ? ' gms-housekeeper__status--' . sanitize_html_class($status) : '';
                            $status_class = 'gms-housekeeper__status' . $status_modifier;
                            ?>
                            <article class="gms-housekeeper__card">
                                <header class="gms-housekeeper__card-header">
                                    <div class="gms-housekeeper__card-date">
                                        <span class="gms-housekeeper__label"><?php esc_html_e('Check-in', 'guest-management-system'); ?></span>
                                        <span class="gms-housekeeper__value"><?php echo esc_html($reservation['checkin_date_label']); ?></span>
                                        <span class="gms-housekeeper__time"><?php echo esc_html($reservation['checkin_time_label']); ?></span>
                                    </div>
                                    <div class="gms-housekeeper__card-date">
                                        <span class="gms-housekeeper__label"><?php esc_html_e('Check-out', 'guest-management-system'); ?></span>
                                        <span class="gms-housekeeper__value"><?php echo esc_html($reservation['checkout_date_label']); ?></span>
                                        <span class="gms-housekeeper__time"><?php echo esc_html($reservation['checkout_time_label']); ?></span>
                                    </div>
                                </header>

                                <div class="gms-housekeeper__card-body">
                                    <?php if ($reservation['property_name'] !== '') : ?>
                                        <h2 class="gms-housekeeper__property"><?php echo esc_html($reservation['property_name']); ?></h2>
                                    <?php endif; ?>
                                    <?php if ($reservation['guest_name'] !== '') : ?>
                                        <p class="gms-housekeeper__guest"><?php echo esc_html($reservation['guest_name']); ?></p>
                                    <?php endif; ?>

                                    <ul class="gms-housekeeper__details">
                                        <li>
                                            <span class="gms-housekeeper__details-label"><?php esc_html_e('Nights', 'guest-management-system'); ?></span>
                                            <span class="gms-housekeeper__details-value"><?php echo esc_html(number_format_i18n($reservation['nights'])); ?></span>
                                        </li>
                                        <li>
                                            <span class="gms-housekeeper__details-label"><?php esc_html_e('Status', 'guest-management-system'); ?></span>
                                            <?php if ($status_label !== '') : ?>
                                                <span class="<?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span>
                                            <?php else : ?>
                                                <span class="gms-housekeeper__details-value"><?php esc_html_e('Unknown', 'guest-management-system'); ?></span>
                                            <?php endif; ?>
                                        </li>
                                    </ul>
                                    <?php
                                    $has_housekeeper_url = $reservation['housekeeper_url'] !== '';
                                    $cta_is_enabled = $has_housekeeper_url && in_array($status, $housekeeper_cta_statuses, true);

                                    if ($has_housekeeper_url) :
                                        $housekeeper_action_attributes = ' data-housekeeper-url="' . esc_attr($reservation['housekeeper_url']) . '"';
                                        if (!empty($reservation['housekeeper_token'])) {
                                            $housekeeper_action_attributes .= ' data-housekeeper-token="' . esc_attr($reservation['housekeeper_token']) . '"';
                                        }
                                        ?>
                                        <div class="gms-housekeeper__actions">
                                            <?php if ($cta_is_enabled) : ?>
                                                <a class="button button-secondary"
                                                    href="<?php echo esc_url($reservation['housekeeper_url']); ?>"
                                                    target="_blank"
                                                    rel="noopener noreferrer"<?php echo $housekeeper_action_attributes; ?>>
                                                    <?php esc_html_e('Open checklist', 'guest-management-system'); ?>
                                                </a>
                                            <?php else : ?>
                                                <span class="button button-secondary disabled"
                                                    role="button"
                                                    aria-disabled="true"
                                                    title="<?php echo esc_attr($housekeeper_cta_locked_message); ?>">
                                                    <?php esc_html_e('Open checklist', 'guest-management-system'); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="gms-housekeeper__calendar" aria-hidden="<?php echo esc_attr($calendar_aria_hidden); ?>">
                    <div class="gms-housekeeper__calendar-toolbar">
                        <a class="button button-secondary gms-housekeeper__calendar-nav" href="<?php echo esc_url($prev_month_url); ?>">
                            <span aria-hidden="true">&lsaquo;</span>
                            <span class="screen-reader-text"><?php echo esc_html(sprintf(__('View %s', 'guest-management-system'), $prev_month_label)); ?></span>
                        </a>
                        <strong class="gms-housekeeper__calendar-month"><?php echo esc_html($month_label); ?></strong>
                        <a class="button button-secondary gms-housekeeper__calendar-nav" href="<?php echo esc_url($next_month_url); ?>">
                            <span aria-hidden="true">&rsaquo;</span>
                            <span class="screen-reader-text"><?php echo esc_html(sprintf(__('View %s', 'guest-management-system'), $next_month_label)); ?></span>
                        </a>
                    </div>

                    <div class="gms-housekeeper__calendar-grid-wrapper">
                        <div class="gms-housekeeper__week gms-housekeeper__week--labels" role="row">
                            <?php foreach ($weekday_labels as $weekday_label) : ?>
                                <div class="gms-housekeeper__weekday" role="columnheader" title="<?php echo esc_attr($weekday_label['long']); ?>">
                                    <?php echo esc_html($weekday_label['short']); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php foreach ($weeks as $week) : ?>
                            <div class="gms-housekeeper__week" role="row">
                                <?php foreach ($week as $day) : ?>
                                    <?php
                                    $day_classes = array('gms-housekeeper__day');
                                    if (!$day['is_current_month']) {
                                        $day_classes[] = 'gms-housekeeper__day--muted';
                                    }
                                    if ($day['is_today']) {
                                        $day_classes[] = 'gms-housekeeper__day--today';
                                    }
                                    $day_class_attr = implode(' ', array_map('sanitize_html_class', $day_classes));

                                    $reservation_total = count($day['reservations']);
                                    $day_date_label = wp_date(get_option('date_format', 'F j, Y'), $day['date']->getTimestamp(), $timezone);
                                    if ($reservation_total > 0) {
                                        $aria_label = sprintf(_n('%1$s — %2$d reservation', '%1$s — %2$d reservations', $reservation_total, 'guest-management-system'), $day_date_label, $reservation_total);
                                    } else {
                                        $aria_label = sprintf(__('%s — No reservations', 'guest-management-system'), $day_date_label);
                                    }
                                    ?>
                                    <div class="<?php echo esc_attr($day_class_attr); ?>" role="gridcell" aria-label="<?php echo esc_attr($aria_label); ?>">
                                        <div class="gms-housekeeper__day-header">
                                            <span class="gms-housekeeper__day-number"><?php echo esc_html($day['date']->format('j')); ?></span>
                                            <?php if ($day['is_today']) : ?>
                                                <span class="gms-housekeeper__today"><?php esc_html_e('Today', 'guest-management-system'); ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($day['reservations'])) : ?>
                                            <ul class="gms-housekeeper__stays">
                                                <?php foreach ($day['reservations'] as $entry) : ?>
                                                    <?php
                                                    $details = $entry['details'];
                                                    $stay_classes = array('gms-housekeeper__stay');
                                                    if ($entry['arrives']) {
                                                        $stay_classes[] = 'gms-housekeeper__stay--arrival';
                                                    }
                                                    if ($entry['departs']) {
                                                        $stay_classes[] = 'gms-housekeeper__stay--departure';
                                                    }
                                                    $stay_class_attr = implode(' ', array_map('sanitize_html_class', $stay_classes));

                                                    $property_display = $details['property_name'] !== '' ? $details['property_name'] : __('Unassigned property', 'guest-management-system');

                                                    $meta_parts = array();
                                                    if ($entry['arrives'] && $entry['departs']) {
                                                        $meta_parts[] = sprintf(__('Same-day turn (%1$s - %2$s)', 'guest-management-system'), $details['checkin_time_label'], $details['checkout_time_label']);
                                                    } else {
                                                        if ($entry['arrives']) {
                                                            $meta_parts[] = sprintf(__('Arrival %s', 'guest-management-system'), $details['checkin_time_label']);
                                                        }
                                                        if ($entry['departs']) {
                                                            $meta_parts[] = sprintf(__('Departure %s', 'guest-management-system'), $details['checkout_time_label']);
                                                        }
                                                    }
                                                    if (empty($meta_parts)) {
                                                        $meta_parts[] = __('Guest in residence', 'guest-management-system');
                                                    }

                                                    $status_note = '';
                                                    if ($details['status'] !== '') {
                                                        $status_note = ucwords(str_replace('_', ' ', $details['status']));
                                                    }
                                                    ?>
                                                    <li class="<?php echo esc_attr($stay_class_attr); ?>">
                                                        <span class="gms-housekeeper__stay-property"><?php echo esc_html($property_display); ?></span>
                                                        <?php if ($details['guest_name'] !== '') : ?>
                                                            <span class="gms-housekeeper__stay-guest"><?php echo esc_html($details['guest_name']); ?></span>
                                                        <?php endif; ?>
                                                        <span class="gms-housekeeper__stay-meta"><?php echo esc_html(implode(' • ', $meta_parts)); ?></span>
                                                        <?php if ($status_note !== '') : ?>
                                                            <span class="gms-housekeeper__stay-status"><?php echo esc_html($status_note); ?></span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else : ?>
                                            <div class="gms-housekeeper__day-empty"><?php esc_html_e('No stays', 'guest-management-system'); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
        if ($checklist_view === 'completed' && $latest_submission_id > 0) {
            update_user_meta(get_current_user_id(), 'gms_last_seen_housekeeper_submission', $latest_submission_id);
        }
    }

    public function maybe_show_housekeeper_submission_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || strpos($screen->id, 'guest-management') === false) {
            return;
        }

        $recent = GMS_Database::getHousekeeperSubmissions(array(
            'limit' => 20,
            'order' => 'DESC',
        ));

        if (empty($recent)) {
            return;
        }

        $latest_id = intval($recent[0]['id']);
        $user_id = get_current_user_id();
        $last_seen = intval(get_user_meta($user_id, 'gms_last_seen_housekeeper_submission', true));

        $new_count = 0;
        foreach ($recent as $submission) {
            if (intval($submission['id']) > $last_seen) {
                $new_count++;
            }
        }

        if ($new_count === 0) {
            return;
        }

        $link = add_query_arg(
            array(
                'page' => 'guest-management-housekeeper',
                'gms_checklist_view' => 'completed',
            ),
            admin_url('admin.php')
        );

        printf(
            '<div class="notice notice-info is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
            esc_html(
                sprintf(
                    _n('%d new cleaning checklist has been submitted.', '%d new cleaning checklists have been submitted.', $new_count, 'guest-management-system'),
                    $new_count
                )
            ),
            esc_url($link),
            esc_html__('Review now', 'guest-management-system')
        );

        if ($latest_id > $last_seen && $screen->id === 'guest-management_page_guest-management-housekeeper') {
            update_user_meta($user_id, 'gms_last_seen_housekeeper_submission', $latest_id);
        }
    }

    private function index_housekeeper_blueprint(array $blueprint) {
        $index = array(
            'sections' => array(),
            'tasks' => array(),
            'photos' => array(),
            'notes' => array(),
        );

        foreach ($blueprint['phases'] as $phase) {
            $phase_label = isset($phase['label']) ? $phase['label'] : '';

            foreach ($phase['sections'] as $section) {
                $section_key = $section['key'];
                $section_label = isset($section['label']) ? $section['label'] : $section_key;

                $index['sections'][$section_key] = array(
                    'label' => $section_label,
                    'phase' => $phase_label,
                );

                if (!empty($section['tasks'])) {
                    foreach ($section['tasks'] as $task) {
                        $index['tasks'][$task['key']] = array(
                            'label' => $task['label'],
                            'section' => $section_key,
                            'phase' => $phase_label,
                        );
                    }
                }

                if (!empty($section['notes'])) {
                    foreach ($section['notes'] as $note) {
                        $index['notes'][$note['key']] = array(
                            'label' => $note['label'],
                            'section' => $section_key,
                            'phase' => $phase_label,
                        );
                    }
                }

                if (!empty($section['photos'])) {
                    foreach ($section['photos'] as $photo) {
                        $index['photos'][$section_key . ':' . $photo['key']] = array(
                            'label' => $photo['label'],
                            'section' => $section_key,
                            'phase' => $phase_label,
                        );
                    }
                }
            }
        }

        return $index;
    }

    private function build_housekeeper_submission_sections(array $blueprint, array $submission) {
        $sections = array();
        $payload = isset($submission['payload']) && is_array($submission['payload']) ? $submission['payload'] : array();
        $task_payload = isset($payload['tasks']) && is_array($payload['tasks']) ? $payload['tasks'] : array();
        $note_payload = isset($payload['notes']) && is_array($payload['notes']) ? $payload['notes'] : array();
        $photos = isset($submission['photos']) && is_array($submission['photos']) ? $submission['photos'] : array();
        $photo_groups = array();

        foreach ($photos as $photo) {
            $task_key = isset($photo['task_key']) ? $photo['task_key'] : '';
            if ($task_key === '') {
                continue;
            }
            $photo_groups[$task_key][] = $photo;
        }

        foreach ($blueprint['phases'] as $phase) {
            $phase_label = isset($phase['label']) ? $phase['label'] : '';
            foreach ($phase['sections'] as $section) {
                $section_key = $section['key'];
                $section_label = isset($section['label']) ? $section['label'] : $section_key;
                $section_data = array(
                    'phase' => $phase_label,
                    'label' => $section_label,
                    'tasks' => array(),
                    'notes' => array(),
                    'photos' => array(),
                    'expanded' => false,
                );

                if (!empty($section['tasks'])) {
                    foreach ($section['tasks'] as $task) {
                        $task_key = $task['key'];
                        $section_data['tasks'][] = array(
                            'label' => $task['label'],
                            'complete' => !empty($task_payload[$task_key]),
                        );
                    }
                }

                if (!empty($section['notes'])) {
                    foreach ($section['notes'] as $note) {
                        $note_key = $note['key'];
                        $value = isset($note_payload[$note_key]) ? $note_payload[$note_key] : '';
                        if ($value === '' && $note_key === 'inventory_notes' && !empty($submission['inventory_notes'])) {
                            $value = $submission['inventory_notes'];
                        }
                        if ($value !== '') {
                            $section_data['notes'][] = array(
                                'label' => $note['label'],
                                'value' => $value,
                            );
                        }
                    }
                }

                if (!empty($section['photos'])) {
                    foreach ($section['photos'] as $photo_slot) {
                        $task_key = $section_key . ':' . $photo_slot['key'];
                        if (empty($photo_groups[$task_key])) {
                            continue;
                        }
                        foreach ($photo_groups[$task_key] as $photo) {
                            $section_data['photos'][] = array(
                                'attachment_id' => isset($photo['attachment_id']) ? intval($photo['attachment_id']) : 0,
                                'label' => $photo_slot['label'],
                                'task_key' => $task_key,
                            );
                        }
                    }
                }

                if (!empty($section_data['tasks']) || !empty($section_data['notes']) || !empty($section_data['photos'])) {
                    $section_data['expanded'] = !empty($section_data['notes']) || !empty($section_data['photos']);
                    $sections[] = $section_data;
                }
            }
        }

        return $sections;
    }

    protected function render_guest_profile_page($guest_id) {
        $list_url = add_query_arg(['page' => 'guest-management-guests'], admin_url('admin.php'));

        if ($guest_id <= 0) {
            ?>
            <div class="wrap">
                <h1 class="wp-heading-inline"><?php esc_html_e('Guest Profiles', 'guest-management-system'); ?></h1>
                <a href="<?php echo esc_url($list_url); ?>" class="page-title-action"><?php esc_html_e('Back to Guest Profiles', 'guest-management-system'); ?></a>
                <hr class="wp-header-end">
                <div class="notice notice-error"><p><?php esc_html_e('The requested guest profile could not be found.', 'guest-management-system'); ?></p></div>
            </div>
            <?php
            return;
        }

        $guest = GMS_Database::get_guest_by_id($guest_id);

        if (!$guest) {
            ?>
            <div class="wrap">
                <h1 class="wp-heading-inline"><?php esc_html_e('Guest Profiles', 'guest-management-system'); ?></h1>
                <a href="<?php echo esc_url($list_url); ?>" class="page-title-action"><?php esc_html_e('Back to Guest Profiles', 'guest-management-system'); ?></a>
                <hr class="wp-header-end">
                <div class="notice notice-error"><p><?php esc_html_e('The requested guest profile could not be found.', 'guest-management-system'); ?></p></div>
            </div>
            <?php
            return;
        }

        $full_name = trim($guest['name'] ?? '');
        if ($full_name === '') {
            $full_name = trim(trim($guest['first_name'] ?? '') . ' ' . trim($guest['last_name'] ?? ''));
        }
        if ($full_name === '') {
            $full_name = esc_html__('Unnamed Guest', 'guest-management-system');
        }

        $email = isset($guest['email']) ? sanitize_email($guest['email']) : '';
        $phone = isset($guest['phone']) ? sanitize_text_field($guest['phone']) : '';
        $display_phone = $this->format_guest_phone_display($phone);

        $verification = GMS_Database::getLatestVerificationForGuest($guest_id);
        $reservations = GMS_Database::getReservationsForGuest($guest_id);

        $verification_status = isset($verification['status']) ? sanitize_text_field($verification['status']) : '';
        $verification_status = $verification_status !== '' ? ucwords(str_replace(['_', '-'], ' ', $verification_status)) : esc_html__('Not yet verified', 'guest-management-system');

        $verified_at = isset($verification['verified_at']) ? $verification['verified_at'] : '';
        if ($verified_at !== '') {
            if (function_exists('gms_format_datetime')) {
                $verified_at = gms_format_datetime($verified_at);
            } else {
                $verified_at = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($verified_at));
            }
        }

        $selfie_preview = null;
        if (is_array($verification)) {
            $selfie_preview = $this->build_verification_media_preview(
                $verification['selfie_path'] ?? '',
                $verification['selfie_mime'] ?? '',
                $verification['selfie_file_id'] ?? ''
            );
        }

        $guest_user_id = GMS_Database::get_guest_wp_user_id($guest_id);
        $stored_selfie_url = '';
        if ($guest_user_id > 0) {
            $stored_selfie_url = get_user_meta($guest_user_id, 'gms_verification_selfie_url', true);
            if (!is_string($stored_selfie_url)) {
                $stored_selfie_url = '';
            }
            $stored_selfie_url = trim($stored_selfie_url);
        }

        $selfie_src = '';
        $selfie_mime_type = '';
        $selfie_is_data_uri = false;

        if ($stored_selfie_url !== '') {
            $selfie_src = esc_url_raw($stored_selfie_url);
        }

        if ($selfie_src === '' && is_array($selfie_preview)) {
            $preview_data_uri = isset($selfie_preview['data_uri']) ? (string) $selfie_preview['data_uri'] : '';
            $preview_view_url = isset($selfie_preview['view_url']) ? (string) $selfie_preview['view_url'] : '';
            $preview_mime = isset($selfie_preview['type']) ? sanitize_mime_type($selfie_preview['type']) : '';

            if ($preview_data_uri !== '') {
                $selfie_src = $preview_data_uri;
                $selfie_is_data_uri = true;
            } elseif ($preview_view_url !== '') {
                $selfie_src = esc_url_raw($preview_view_url);
            }

            if ($preview_mime !== '') {
                $selfie_mime_type = $preview_mime;
            }
        }

        if ($selfie_src !== '' && strpos($selfie_src, 'data:') === 0) {
            $selfie_is_data_uri = true;
        }

        $stripe_session_id = '';
        $session_candidates = array();

        if (is_array($verification)) {
            if (!empty($verification['stripe_session_id'])) {
                $session_candidates[] = sanitize_text_field($verification['stripe_session_id']);
            }

            if (
                isset($verification['verification_data'])
                && is_array($verification['verification_data'])
                && !empty($verification['verification_data']['id'])
            ) {
                $session_candidates[] = sanitize_text_field($verification['verification_data']['id']);
            }
        }

        if (empty($session_candidates) && !empty($reservations)) {
            foreach ($reservations as $reservation) {
                $reservation_id = isset($reservation['id']) ? intval($reservation['id']) : 0;

                if ($reservation_id <= 0) {
                    continue;
                }

                $reservation_verification = GMS_Database::getVerificationByReservation($reservation_id);

                if (!is_array($reservation_verification)) {
                    continue;
                }

                if (!empty($reservation_verification['stripe_session_id'])) {
                    $session_candidates[] = sanitize_text_field($reservation_verification['stripe_session_id']);
                }

                if (
                    isset($reservation_verification['verification_data'])
                    && is_array($reservation_verification['verification_data'])
                    && !empty($reservation_verification['verification_data']['id'])
                ) {
                    $session_candidates[] = sanitize_text_field($reservation_verification['verification_data']['id']);
                }

                if (!empty($session_candidates)) {
                    break;
                }
            }
        }

        if (!empty($session_candidates)) {
            $stripe_session_id = reset($session_candidates);
        }

        $can_request_refresh = ($stripe_session_id !== '');

        $refresh_notice = isset($_GET['gms_verification_refresh']) ? sanitize_key(wp_unslash($_GET['gms_verification_refresh'])) : '';
        $refresh_error = '';
        if ($refresh_notice === 'error' && isset($_GET['gms_verification_error'])) {
            $refresh_error = sanitize_text_field(rawurldecode(wp_unslash($_GET['gms_verification_error'])));
        }

        ?>
        <div class="wrap gms-guest-profile">
            <h1 class="wp-heading-inline"><?php esc_html_e('Guest Profiles', 'guest-management-system'); ?></h1>
            <a href="<?php echo esc_url($list_url); ?>" class="page-title-action"><?php esc_html_e('Back to Guest Profiles', 'guest-management-system'); ?></a>
            <hr class="wp-header-end">

            <?php if ($refresh_notice === 'success') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Stripe verification assets were requested successfully. Stored media will appear once downloaded.', 'guest-management-system'); ?></p>
                </div>
            <?php elseif ($refresh_notice === 'error') : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo $refresh_error !== ''
                        ? esc_html($refresh_error)
                        : esc_html__('Unable to refresh the verification assets. Please try again.', 'guest-management-system'); ?></p>
                </div>
            <?php endif; ?>

            <style>
                .gms-guest-profile__section {
                    margin-bottom: 2em;
                }

                .gms-guest-profile__section h2 {
                    margin-top: 1.5em;
                }

                .gms-guest-profile__selfie-wrapper {
                    margin: 1em 0;
                    display: flex;
                    align-items: center;
                    justify-content: flex-start;
                }

                .gms-guest-profile__selfie {
                    max-width: 240px;
                    border-radius: 12px;
                    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.2);
                    width: 100%;
                    height: auto;
                }

                .gms-guest-profile__selfie-placeholder {
                    margin: 1em 0;
                    padding: 24px;
                    background: #f6f7f7;
                    border: 1px dashed #dcdcde;
                    border-radius: 8px;
                    color: #646970;
                    font-style: italic;
                }
            </style>

            <div class="gms-guest-profile__section">
                <h2><?php esc_html_e('Guest Details', 'guest-management-system'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Full Name', 'guest-management-system'); ?></th>
                            <td><?php echo esc_html($full_name); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Email', 'guest-management-system'); ?></th>
                            <td>
                                <?php if ($email !== '') : ?>
                                    <a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Phone', 'guest-management-system'); ?></th>
                            <td><?php echo $display_phone !== '' ? esc_html($display_phone) : '&mdash;'; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="gms-guest-profile__section">
                <h2><?php esc_html_e('Identity Verification', 'guest-management-system'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Status', 'guest-management-system'); ?></th>
                            <td><?php echo esc_html($verification_status); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Verified At', 'guest-management-system'); ?></th>
                            <td><?php echo $verified_at !== '' ? esc_html($verified_at) : '&mdash;'; ?></td>
                        </tr>
                    </tbody>
                </table>

                <?php
                $selfie_alt_text = sprintf(
                    /* translators: %s: guest name */
                    __('Selfie for %s', 'guest-management-system'),
                    $full_name !== '' ? $full_name : __('guest', 'guest-management-system')
                );
                ?>

                <?php if ($selfie_src !== '') :
                    $selfie_attr = $selfie_is_data_uri ? esc_attr($selfie_src) : esc_url($selfie_src);
                    ?>
                    <div class="gms-guest-profile__selfie-wrapper">
                        <img
                            src="<?php echo $selfie_attr; ?>"
                            class="gms-guest-profile__selfie"
                            alt="<?php echo esc_attr($selfie_alt_text); ?>"
                            <?php if ($selfie_mime_type !== '') : ?>
                                data-mime-type="<?php echo esc_attr($selfie_mime_type); ?>"
                            <?php endif; ?>
                            loading="lazy"
                        />
                    </div>
                <?php else : ?>
                    <div class="gms-guest-profile__selfie-placeholder">
                        <?php esc_html_e('No selfie has been stored for this guest yet.', 'guest-management-system'); ?>
                    </div>
                <?php endif; ?>

                <?php if ($can_request_refresh) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="gms-guest-profile__refresh-form">
                        <?php wp_nonce_field('gms_refresh_guest_verification_' . $guest_id); ?>
                        <input type="hidden" name="action" value="gms_refresh_guest_verification">
                        <input type="hidden" name="guest_id" value="<?php echo esc_attr($guest_id); ?>">
                        <input type="hidden" name="session_id" value="<?php echo esc_attr($stripe_session_id); ?>">
                        <?php submit_button(__('Request ID from Stripe', 'guest-management-system'), 'secondary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            </div>

            <div class="gms-guest-profile__section">
                <h2><?php esc_html_e('Reservations', 'guest-management-system'); ?></h2>
                <?php if (!empty($reservations)) : ?>
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Reservation', 'guest-management-system'); ?></th>
                                <th><?php esc_html_e('Check-In', 'guest-management-system'); ?></th>
                                <th><?php esc_html_e('Check-Out', 'guest-management-system'); ?></th>
                                <th><?php esc_html_e('Status', 'guest-management-system'); ?></th>
                                <th><?php esc_html_e('Actions', 'guest-management-system'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $reservation) :
                                $reservation_id = intval($reservation['id'] ?? 0);
                                $reservation_link = $reservation_id > 0
                                    ? add_query_arg(
                                        array(
                                            'page' => 'guest-management-reservations',
                                            'action' => 'edit',
                                            'reservation_id' => $reservation_id,
                                        ),
                                        admin_url('admin.php')
                                    )
                                    : '';
                                $booking_reference = isset($reservation['booking_reference']) ? $reservation['booking_reference'] : '';
                                $checkin = isset($reservation['checkin_date']) ? $reservation['checkin_date'] : '';
                                $checkout = isset($reservation['checkout_date']) ? $reservation['checkout_date'] : '';
                                if ($checkin !== '') {
                                    $checkin = function_exists('gms_format_datetime') ? gms_format_datetime($checkin) : date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($checkin));
                                }
                                if ($checkout !== '') {
                                    $checkout = function_exists('gms_format_datetime') ? gms_format_datetime($checkout) : date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($checkout));
                                }
                                $status = isset($reservation['status']) ? sanitize_key($reservation['status']) : '';
                                $status_label = $status !== '' ? ucwords(str_replace(['_', '-'], ' ', $status)) : esc_html__('Unknown', 'guest-management-system');
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($booking_reference !== '') : ?>
                                            <?php echo esc_html($booking_reference); ?>
                                        <?php else : ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $checkin !== '' ? esc_html($checkin) : '&mdash;'; ?></td>
                                    <td><?php echo $checkout !== '' ? esc_html($checkout) : '&mdash;'; ?></td>
                                    <td><?php echo esc_html($status_label); ?></td>
                                    <td>
                                        <?php if ($reservation_link) : ?>
                                            <a class="button" href="<?php echo esc_url($reservation_link); ?>"><?php esc_html_e('View Reservation', 'guest-management-system'); ?></a>
                                        <?php else : ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php esc_html_e('No reservations are linked to this guest profile.', 'guest-management-system'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function handle_refresh_guest_verification() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to refresh verification details.', 'guest-management-system'));
        }

        $guest_id = isset($_POST['guest_id']) ? absint(wp_unslash($_POST['guest_id'])) : 0;
        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';

        if ($guest_id <= 0) {
            wp_die(__('Invalid guest selected for verification refresh.', 'guest-management-system'));
        }

        check_admin_referer('gms_refresh_guest_verification_' . $guest_id);

        $redirect_url = add_query_arg(
            array(
                'page' => 'guest-management-guests',
                'action' => 'view',
                'guest_id' => $guest_id,
            ),
            admin_url('admin.php')
        );

        if ($session_id === '') {
            $redirect_url = add_query_arg(
                array(
                    'gms_verification_refresh' => 'error',
                    'gms_verification_error' => rawurlencode(__('Missing Stripe verification session identifier.', 'guest-management-system')),
                ),
                $redirect_url
            );
            wp_safe_redirect($redirect_url);
            exit;
        }

        if (!class_exists('GMS_Stripe_Integration')) {
            require_once plugin_dir_path(__FILE__) . 'class-stripe-integration.php';
        }

        $stripe = GMS_Stripe_Integration::instance();
        $result = $stripe->refreshVerificationForSession($session_id);

        if (is_wp_error($result)) {
            $redirect_url = add_query_arg(
                array(
                    'gms_verification_refresh' => 'error',
                    'gms_verification_error' => rawurlencode($result->get_error_message()),
                ),
                $redirect_url
            );
        } else {
            $redirect_url = add_query_arg('gms_verification_refresh', 'success', $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    public function handle_view_verification_asset() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access verification assets.', 'guest-management-system'));
        }

        $file_id = isset($_GET['file']) ? sanitize_text_field(wp_unslash($_GET['file'])) : '';

        if ($file_id === '') {
            wp_die(__('Missing Stripe file identifier.', 'guest-management-system'));
        }

        $nonce = isset($_GET['_wpnonce']) ? wp_unslash($_GET['_wpnonce']) : '';

        if (!wp_verify_nonce($nonce, 'gms_view_verification_asset_' . $file_id)) {
            wp_die(__('Invalid or expired verification asset request.', 'guest-management-system'));
        }

        if (!class_exists('GMS_Stripe_Integration')) {
            require_once plugin_dir_path(__FILE__) . 'class-stripe-integration.php';
        }

        $stripe = GMS_Stripe_Integration::instance();

        $context = isset($_GET['context']) ? sanitize_key(wp_unslash($_GET['context'])) : '';
        $prefer_stream = ($context === 'modal');

        $file_link = '';
        if (!$prefer_stream) {
            $file_link = $stripe->generateFileLink($file_id);
        }

        if (!$prefer_stream && is_string($file_link) && $file_link !== '') {
            wp_redirect(esc_url_raw($file_link));
            exit;
        }

        $stream = $stripe->getFileStreamData($file_id);

        if (is_wp_error($stream)) {
            wp_die(esc_html($stream->get_error_message()), __('Stripe File Error', 'guest-management-system'));
        }

        $mime_type = isset($stream['mime_type']) ? sanitize_mime_type($stream['mime_type']) : 'application/octet-stream';
        if ($mime_type === '') {
            $mime_type = 'application/octet-stream';
        }

        $filename = isset($stream['filename']) ? sanitize_file_name($stream['filename']) : ($file_id . '.bin');
        if ($filename === '') {
            $filename = sanitize_file_name($file_id . '.bin');
        }

        $contents = isset($stream['contents']) ? $stream['contents'] : '';

        if (!is_string($contents) || $contents === '') {
            wp_die(__('Unable to render the requested Stripe file.', 'guest-management-system'), __('Stripe File Error', 'guest-management-system'));
        }

        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: ' . $mime_type);
            header('Content-Length: ' . strlen($contents));
            header('Content-Disposition: inline; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
        }

        echo $contents;
        exit;
    }

    public function handle_housekeeper_ical_export() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to export this calendar.', 'guest-management-system'));
        }

        $nonce = isset($_GET['_wpnonce']) ? wp_unslash($_GET['_wpnonce']) : '';
        if (!wp_verify_nonce($nonce, 'gms_housekeeper_ical')) {
            wp_die(__('Invalid calendar export request.', 'guest-management-system'));
        }

        $reservations = $this->get_housekeeper_reservations();

        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: attachment; filename=housekeeping-schedule.ics');
        }

        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        if (empty($host)) {
            $host = 'calendar.local';
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        echo "BEGIN:VCALENDAR\r\n";
        echo "VERSION:2.0\r\n";
        echo "PRODID:-//Guest Management System//Housekeeper Calendar//EN\r\n";
        echo "CALSCALE:GREGORIAN\r\n";
        echo "METHOD:PUBLISH\r\n";

        foreach ($reservations as $reservation) {
            $reservation_id = isset($reservation['id']) ? (int) $reservation['id'] : 0;
            $uid = 'gms-housekeeper-' . $reservation_id . '@' . $host;

            $checkin_utc = $reservation['checkin']->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
            $checkout_utc = $reservation['checkout']->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');

            $summary_parts = array_filter(array(
                $reservation['property_name'],
                $reservation['guest_name'] !== '' ? $reservation['guest_name'] : '',
            ));
            $summary = !empty($summary_parts) ? implode(' - ', $summary_parts) : __('Reservation', 'guest-management-system');

            $description_lines = array(
                sprintf(__('Check-in: %s', 'guest-management-system'), $reservation['checkin_datetime_label']),
                sprintf(__('Check-out: %s', 'guest-management-system'), $reservation['checkout_datetime_label']),
            );

            if ($reservation['status'] !== '') {
                $description_lines[] = sprintf(__('Status: %s', 'guest-management-system'), ucwords(str_replace('_', ' ', $reservation['status'])));
            }

            $description = implode("\n", $description_lines);

            echo "BEGIN:VEVENT\r\n";
            echo 'UID:' . $this->escape_ical_text($uid) . "\r\n";
            echo 'DTSTAMP:' . $now->format('Ymd\THis\Z') . "\r\n";
            echo 'DTSTART:' . $checkin_utc . "\r\n";
            echo 'DTEND:' . $checkout_utc . "\r\n";
            echo 'SUMMARY:' . $this->escape_ical_text($summary) . "\r\n";
            if ($reservation['property_name'] !== '') {
                echo 'LOCATION:' . $this->escape_ical_text($reservation['property_name']) . "\r\n";
            }
            echo 'DESCRIPTION:' . $this->escape_ical_text($description) . "\r\n";
            echo "END:VEVENT\r\n";
        }

        echo "END:VCALENDAR\r\n";
        exit;
    }

    public function render_dashboard_page() {
        $total_reservations = GMS_Database::get_record_count('reservations');
        $total_guests = GMS_Database::get_record_count('guests');

        $upcoming_checkins = gms_get_upcoming_checkins(7);
        $pending_checkins = gms_get_pending_checkins();
        $recent_reservations = GMS_Database::get_reservations(5, 1);

        $upcoming_count = is_array($upcoming_checkins) ? count($upcoming_checkins) : 0;
        $pending_count = is_array($pending_checkins) ? count($pending_checkins) : 0;

        $reservations_list_url = add_query_arg(['page' => 'guest-management-reservations'], admin_url('admin.php'));
        $pending_link = add_query_arg([
            'page' => 'guest-management-reservations',
            'reservation_status' => 'pending_checkins',
        ], admin_url('admin.php'));
        $upcoming_link = add_query_arg([
            'page' => 'guest-management-reservations',
            'checkin_filter' => 'upcoming',
        ], admin_url('admin.php'));

        ?>
        <div class="wrap gms-dashboard">
            <h1 class="wp-heading-inline"><?php esc_html_e('Guest Management Dashboard', 'guest-management-system'); ?></h1>
            <hr class="wp-header-end">

            <div class="gms-dashboard-stats">
                <div class="gms-stat-box">
                    <h3><?php echo esc_html(number_format_i18n($total_reservations)); ?></h3>
                    <p><?php esc_html_e('Total Reservations', 'guest-management-system'); ?></p>
                </div>
                <a class="gms-stat-box gms-stat-box--link" href="<?php echo esc_url($upcoming_link); ?>">
                    <h3><?php echo esc_html(number_format_i18n($upcoming_count)); ?></h3>
                    <p><?php esc_html_e('Upcoming Check-ins (7 Days)', 'guest-management-system'); ?></p>
                </a>
                <a class="gms-stat-box gms-stat-box--link" href="<?php echo esc_url($pending_link); ?>">
                    <h3><?php echo esc_html(number_format_i18n($pending_count)); ?></h3>
                    <p><?php esc_html_e('Pending Check-ins', 'guest-management-system'); ?></p>
                </a>
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
                                <th><?php esc_html_e('Actions', 'guest-management-system'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_reservations as $reservation) :
                                $reservation_id = isset($reservation['id']) ? absint($reservation['id']) : 0;
                                $status = isset($reservation['status']) ? $reservation['status'] : '';
                                $status_slug = $status ? strtolower(str_replace([' ', '_'], '-', $status)) : 'default';
                                $checkin_display = !empty($reservation['checkin_date'])
                                    ? gms_format_datetime($reservation['checkin_date'])
                                    : __('—', 'guest-management-system');
                                $edit_link = $reservation_id ? add_query_arg([
                                    'page' => 'guest-management-reservations',
                                    'action' => 'edit',
                                    'reservation_id' => $reservation_id,
                                ], admin_url('admin.php')) : '';
                                $delete_link = $reservation_id ? wp_nonce_url(add_query_arg([
                                    'page' => 'guest-management-reservations',
                                    'action' => 'delete',
                                    'reservation_id' => $reservation_id,
                                ], admin_url('admin.php')), 'gms_delete_reservation_' . $reservation_id) : '';
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
                                    <td>
                                        <?php if ($reservation_id) : ?>
                                            <a href="<?php echo esc_url($edit_link); ?>"><?php esc_html_e('Edit', 'guest-management-system'); ?></a>
                                            <span aria-hidden="true">|</span>
                                            <a href="<?php echo esc_url($delete_link); ?>" class="submitdelete" onclick="return confirm('<?php echo esc_attr__('Are you sure you want to delete this reservation?', 'guest-management-system'); ?>');"><?php esc_html_e('Delete', 'guest-management-system'); ?></a>
                                        <?php else : ?>
                                            &mdash;
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
                <a class="button button-primary" href="<?php echo esc_url($reservations_list_url); ?>"><?php esc_html_e('Manage Reservations', 'guest-management-system'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=guest-management-guests')); ?>"><?php esc_html_e('View Guest Profiles', 'guest-management-system'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=guest-management-messaging')); ?>"><?php esc_html_e('Open Messaging Inbox', 'guest-management-system'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=guest-management-logs')); ?>"><?php esc_html_e('View Activity Logs', 'guest-management-system'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=guest-management-templates')); ?>"><?php esc_html_e('Edit Templates', 'guest-management-system'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=guest-management-settings')); ?>"><?php esc_html_e('Open Settings', 'guest-management-system'); ?></a>
            </div>
        </div>
        <?php
    }
    
    public function render_reservations_page() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $post_action = isset($_POST['gms_action']) ? sanitize_key(wp_unslash($_POST['gms_action'])) : '';

            if ($post_action === 'sync_platform_reservations') {
                $this->handle_platform_sync_request();
                return;
            }
        }

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';

        if ($action === 'new') {
            $this->render_reservation_creation_page();
            return;
        }

        if ($action === 'edit') {
            $reservation_id = isset($_GET['reservation_id']) ? absint(wp_unslash($_GET['reservation_id'])) : 0;
            $this->render_reservation_edit_page($reservation_id);
            return;
        }

        if ($action === 'delete') {
            $reservation_id = isset($_GET['reservation_id']) ? absint(wp_unslash($_GET['reservation_id'])) : 0;
            $nonce = isset($_GET['_wpnonce']) ? wp_unslash($_GET['_wpnonce']) : '';

            $redirect_args = ['page' => 'guest-management-reservations'];

            foreach (['reservation_status', 'checkin_filter', 'orderby', 'order'] as $key) {
                if (!isset($_GET[$key])) {
                    continue;
                }

                $value = sanitize_key(wp_unslash($_GET[$key]));
                if ($value !== '') {
                    $redirect_args[$key] = $value;
                }
            }

            if (isset($_GET['s']) && $_GET['s'] !== '') {
                $redirect_args['s'] = sanitize_text_field(wp_unslash($_GET['s']));
            }

            $redirect_url = add_query_arg($redirect_args, admin_url('admin.php'));

            if ($reservation_id > 0 && wp_verify_nonce($nonce, 'gms_delete_reservation_' . $reservation_id)) {
                $deleted = GMS_Database::delete_reservations([$reservation_id]);

                if ($deleted > 0) {
                    $redirect_url = add_query_arg('gms_reservation_deleted', $deleted, $redirect_url);
                } else {
                    $redirect_url = add_query_arg('gms_reservation_delete_error', 1, $redirect_url);
                }
            } else {
                $redirect_url = add_query_arg('gms_reservation_delete_error', 1, $redirect_url);
            }

            wp_safe_redirect($redirect_url);
            exit;
        }

        $reservations_table = new GMS_Reservations_List_Table();
        $reservations_table->prepare_items();

        $add_new_url = add_query_arg(
            array(
                'page' => 'guest-management-reservations',
                'action' => 'new',
            ),
            admin_url('admin.php')
        );

        ?>
        <div class="wrap gms-reservations-index">
            <h1 class="wp-heading-inline">Reservations</h1>
            <a href="<?php echo esc_url($add_new_url); ?>" class="page-title-action"><?php esc_html_e('Add New', 'guest-management-system'); ?></a>
            <hr class="wp-header-end">

            <p class="gms-reservations-index__intro"><?php esc_html_e('Centralize every reservation across your connected platforms.', 'guest-management-system'); ?></p>

            <?php
            $platform_sync_notice = $this->consume_platform_sync_notice();
            if ($platform_sync_notice) {
                $this->render_platform_sync_notice($platform_sync_notice);
            }

            $platform_configs = array();
            if (class_exists('GMS_OTA_Reservation_Sync')) {
                $platform_sync_handler = new GMS_OTA_Reservation_Sync();
                $platform_configs = $platform_sync_handler->get_platform_config();
            } else {
                /**
                 * Fallback configuration for OTA platforms when the sync handler is not available.
                 *
                 * @param array $config Default OTA configuration values.
                 */
                $platform_configs = apply_filters('gms_ota_reservation_config', array());
            }

            if (empty($platform_configs)) {
                $platform_configs = array(
                    'airbnb' => array('label' => __('Airbnb', 'guest-management-system')),
                    'vrbo' => array('label' => __('VRBO', 'guest-management-system')),
                    'booking_com' => array('label' => __('Booking.com', 'guest-management-system')),
                );
            }

            if (!empty($platform_configs)) :
                ?>
                <div class="gms-panel gms-reservations-sync">
                    <h2><?php esc_html_e('Sync Platform Reservations', 'guest-management-system'); ?></h2>
                    <p><?php esc_html_e('Import the latest reservations from connected platforms so you can manage every stay here.', 'guest-management-system'); ?></p>
                    <form method="post" class="gms-reservations-sync__form">
                        <?php wp_nonce_field('gms_sync_platform_reservations'); ?>
                        <input type="hidden" name="gms_action" value="sync_platform_reservations">
                        <div class="gms-reservations-sync__field">
                            <label for="gms_sync_platform"><?php esc_html_e('Platform', 'guest-management-system'); ?></label>
                            <select name="gms_sync_platform" id="gms_sync_platform">
                                <option value="all"><?php esc_html_e('All Connected Platforms', 'guest-management-system'); ?></option>
                                <?php foreach ($platform_configs as $platform_key => $platform_settings) :
                                    $label = isset($platform_settings['label']) ? $platform_settings['label'] : ucfirst(str_replace('_', ' ', $platform_key));
                                    ?>
                                    <option value="<?php echo esc_attr($platform_key); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="gms-reservations-sync__field">
                            <label for="gms_sync_since"><?php esc_html_e('Updated Since', 'guest-management-system'); ?></label>
                            <input type="date" name="gms_sync_since" id="gms_sync_since" value="">
                        </div>
                        <div class="gms-reservations-sync__field">
                            <label for="gms_sync_limit"><?php esc_html_e('Limit', 'guest-management-system'); ?></label>
                            <input type="number" name="gms_sync_limit" id="gms_sync_limit" min="1" max="200" value="50">
                            <p class="description"><?php esc_html_e('Optional cap to keep the import focused on recent reservations.', 'guest-management-system'); ?></p>
                        </div>
                        <div class="gms-reservations-sync__actions">
                            <button type="submit" class="button button-secondary"><?php esc_html_e('Sync Reservations', 'guest-management-system'); ?></button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['gms_reservation_created'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Reservation created successfully.', 'guest-management-system'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['gms_reservation_updated'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Reservation updated successfully.', 'guest-management-system'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['gms_reservation_deleted'])) :
                $deleted = max(1, absint($_GET['gms_reservation_deleted']));
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php printf(
                            esc_html(_n('%d reservation deleted.', '%d reservations deleted.', $deleted, 'guest-management-system')),
                            $deleted
                        ); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['gms_reservation_delete_error'])) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e('Unable to delete the reservation. Please try again.', 'guest-management-system'); ?></p>
                </div>
            <?php endif; ?>

            <div class="gms-reservations-table">
                <form method="get" class="gms-reservations-table__form">
                    <input type="hidden" name="page" value="guest-management-reservations" />
                    <?php $reservations_table->search_box(__('Search Reservations', 'guest-management-system'), 'gms-reservations'); ?>
                    <?php $reservations_table->display(); ?>
                </form>
            </div>
        </div>
        <?php
    }

    protected function render_reservation_creation_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $list_url = add_query_arg(array('page' => 'guest-management-reservations'), admin_url('admin.php'));

        $form_values = array(
            'guest_name' => '',
            'guest_email' => '',
            'guest_phone' => '',
            'property_name' => '',
            'property_id' => '',
            'booking_reference' => '',
            'door_code' => '',
            'checkin_date' => '',
            'checkout_date' => '',
            'status' => 'pending',
        );

        $errors = array();

        $success_notice = '';

        $status_options = function_exists('gms_get_reservation_status_options')
            ? gms_get_reservation_status_options()
            : array(
                'pending' => __('Pending Approval', 'guest-management-system'),
                'approved' => __('Approved', 'guest-management-system'),
                'awaiting_signature' => __('Awaiting Signature', 'guest-management-system'),
                'awaiting_id_verification' => __('Awaiting ID Verification', 'guest-management-system'),
                'confirmed' => __('Confirmed', 'guest-management-system'),
                'cancelled' => __('Cancelled', 'guest-management-system'),
            );

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('gms_create_reservation');

            foreach ($form_values as $key => $default) {
                if (isset($_POST[$key])) {
                    $value = wp_unslash($_POST[$key]);
                    switch ($key) {
                        case 'guest_email':
                            $form_values[$key] = sanitize_email($value);
                            break;
                        case 'door_code':
                            $form_values[$key] = GMS_Database::sanitizeDoorCode($value);
                            break;
                        case 'checkin_date':
                        case 'checkout_date':
                            $form_values[$key] = $this->format_datetime_for_input($value);
                            break;
                        default:
                            $form_values[$key] = sanitize_text_field($value);
                            break;
                    }
                }
            }

            $allowed_statuses = array_keys($status_options);
            if (!in_array($form_values['status'], $allowed_statuses, true)) {
                $form_values['status'] = 'pending';
            }

            if (empty($form_values['guest_name'])) {
                $errors[] = __('Guest name is required.', 'guest-management-system');
            }

            if (!empty($form_values['guest_email']) && !is_email($form_values['guest_email'])) {
                $errors[] = __('Please enter a valid email address.', 'guest-management-system');
            }

            if ($form_values['door_code'] !== '' && !preg_match('/^\d{4}$/', $form_values['door_code'])) {
                $errors[] = __('Door code must be a 4-digit number.', 'guest-management-system');
            }

            $guest_id = 0;
            $guest_record_id = 0;

            if (empty($errors)) {
                $guest_record_id = GMS_Database::upsert_guest(array(
                    'name' => $form_values['guest_name'],
                    'email' => $form_values['guest_email'],
                    'phone' => $form_values['guest_phone'],
                ), array(
                    'force_user_creation' => !empty($form_values['guest_email']) && is_email($form_values['guest_email']),
                ));

                if (!$guest_record_id) {
                    $errors[] = __('Unable to save guest details. Please try again.', 'guest-management-system');
                }
            }

            if (empty($errors)) {
                $guest_id = GMS_Database::ensure_guest_user($guest_record_id, array(
                    'full_name' => $form_values['guest_name'],
                    'email' => $form_values['guest_email'],
                    'phone' => $form_values['guest_phone'],
                ), !empty($form_values['guest_email']) && is_email($form_values['guest_email']));

                $reservation_data = array(
                    'guest_id' => $guest_id,
                    'guest_record_id' => $guest_record_id,
                    'guest_name' => $form_values['guest_name'],
                    'guest_email' => $form_values['guest_email'],
                    'guest_phone' => $form_values['guest_phone'],
                    'property_name' => $form_values['property_name'],
                    'property_id' => $form_values['property_id'],
                    'booking_reference' => $form_values['booking_reference'],
                    'door_code' => $form_values['door_code'],
                    'checkin_date' => $this->format_datetime_for_database($form_values['checkin_date']),
                    'checkout_date' => $this->format_datetime_for_database($form_values['checkout_date']),
                    'status' => $form_values['status'],
                );

                $result = GMS_Database::createReservation($reservation_data);

                if ($result) {
                    if ($form_values['door_code'] !== '') {
                        $reservation_for_sms = GMS_Database::getReservationById($result);
                        if ($reservation_for_sms) {
                            $sms_handler = new GMS_SMS_Handler();
                            $sms_handler->sendDoorCodeSMS($reservation_for_sms, $form_values['door_code']);
                        }
                    }

                    $redirect_url = add_query_arg(
                        array(
                            'page' => 'guest-management-reservations',
                            'gms_reservation_created' => 1,
                        ),
                        admin_url('admin.php')
                    );

                    $redirected = false;

                    if (!headers_sent()) {
                        $redirected = wp_safe_redirect($redirect_url);
                    }

                    if ($redirected) {
                        exit;
                    }

                    $success_notice = sprintf(
                        /* translators: 1: opening anchor tag, 2: closing anchor tag */
                        __('Reservation created successfully. %1$sReturn to Reservations%2$s.', 'guest-management-system'),
                        '<a href="' . esc_url($list_url) . '">',
                        '</a>'
                    );
                } else {
                    $errors[] = __('Unable to create reservation. Please try again.', 'guest-management-system');
                }
            }
        }

        $cancel_url = $list_url;

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Add New Reservation', 'guest-management-system'); ?></h1>
            <a href="<?php echo esc_url($cancel_url); ?>" class="page-title-action"><?php esc_html_e('Back to Reservations', 'guest-management-system'); ?></a>
            <hr class="wp-header-end">

            <?php if ($success_notice) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo wp_kses_post($success_notice); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)) : ?>
                <div class="notice notice-error">
                    <ul>
                        <?php foreach ($errors as $error) : ?>
                            <li><?php echo esc_html($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('gms_create_reservation'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="gms_guest_name"><?php esc_html_e('Guest Name', 'guest-management-system'); ?></label></th>
                            <td><input name="guest_name" type="text" id="gms_guest_name" value="<?php echo esc_attr($form_values['guest_name']); ?>" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_guest_email"><?php esc_html_e('Guest Email', 'guest-management-system'); ?></label></th>
                            <td><input name="guest_email" type="email" id="gms_guest_email" value="<?php echo esc_attr($form_values['guest_email']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_guest_phone"><?php esc_html_e('Guest Phone', 'guest-management-system'); ?></label></th>
                            <td><input name="guest_phone" type="text" id="gms_guest_phone" value="<?php echo esc_attr($form_values['guest_phone']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_property_name"><?php esc_html_e('Property Name', 'guest-management-system'); ?></label></th>
                            <td><input name="property_name" type="text" id="gms_property_name" value="<?php echo esc_attr($form_values['property_name']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_property_id"><?php esc_html_e('Property ID', 'guest-management-system'); ?></label></th>
                            <td><input name="property_id" type="text" id="gms_property_id" value="<?php echo esc_attr($form_values['property_id']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_booking_reference"><?php esc_html_e('Booking Reference', 'guest-management-system'); ?></label></th>
                            <td><input name="booking_reference" type="text" id="gms_booking_reference" value="<?php echo esc_attr($form_values['booking_reference']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_door_code"><?php esc_html_e('Door Code', 'guest-management-system'); ?></label></th>
                            <td>
                                <input name="door_code" type="text" id="gms_door_code" value="<?php echo esc_attr($form_values['door_code']); ?>" class="regular-text" maxlength="4" pattern="\d{4}" inputmode="numeric">
                                <p class="description"><?php esc_html_e('Provide the 4-digit entry code for the guest.', 'guest-management-system'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_checkin_date"><?php esc_html_e('Check-in Date', 'guest-management-system'); ?></label></th>
                            <td><input name="checkin_date" type="datetime-local" id="gms_checkin_date" value="<?php echo esc_attr($form_values['checkin_date']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_checkout_date"><?php esc_html_e('Check-out Date', 'guest-management-system'); ?></label></th>
                            <td><input name="checkout_date" type="datetime-local" id="gms_checkout_date" value="<?php echo esc_attr($form_values['checkout_date']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_status"><?php esc_html_e('Status', 'guest-management-system'); ?></label></th>
                            <td>
                                <select name="status" id="gms_status">
                                    <?php
                                    foreach ($status_options as $status_key => $status_label) :
                                        ?>
                                        <option value="<?php echo esc_attr($status_key); ?>"<?php selected($form_values['status'], $status_key); ?>><?php echo esc_html($status_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Create Reservation', 'guest-management-system')); ?>
            </form>
        </div>
        <?php
    }

    protected function render_reservation_edit_page($reservation_id) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $reservation_id = absint($reservation_id);

        if (!$reservation_id) {
            $this->render_missing_reservation_notice();
            return;
        }

        $reservation = GMS_Database::getReservationById($reservation_id);

        if (!$reservation) {
            $this->render_missing_reservation_notice();
            return;
        }

        $list_url = add_query_arg(array('page' => 'guest-management-reservations'), admin_url('admin.php'));

        $form_values = $this->map_reservation_to_form_values($reservation);
        $original_door_code = isset($reservation['door_code']) ? (string) $reservation['door_code'] : '';

        $errors = array();
        $success_notice = '';

        $status_options = function_exists('gms_get_reservation_status_options')
            ? gms_get_reservation_status_options()
            : array(
                'pending' => __('Pending Approval', 'guest-management-system'),
                'approved' => __('Approved', 'guest-management-system'),
                'awaiting_signature' => __('Awaiting Signature', 'guest-management-system'),
                'awaiting_id_verification' => __('Awaiting ID Verification', 'guest-management-system'),
                'confirmed' => __('Confirmed', 'guest-management-system'),
                'cancelled' => __('Cancelled', 'guest-management-system'),
            );

        $step_feedback = array(
            'portal' => array('type' => '', 'messages' => array()),
            'door_code' => array('type' => '', 'messages' => array()),
            'welcome' => array('type' => '', 'messages' => array()),
        );

        $portal_card_feedback = array('type' => '', 'messages' => array());

        $platform_refresh_feedback = array('type' => '', 'messages' => array());

        $manual_action_processed = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = isset($_POST['gms_action']) ? sanitize_key(wp_unslash($_POST['gms_action'])) : '';
            $posted_id = isset($_POST['reservation_id']) ? absint(wp_unslash($_POST['reservation_id'])) : 0;

            if (in_array($action, array('send_portal_link', 'send_door_code_bundle', 'send_welcome_sequence', 'refresh_platform_reservation', 'create_portal_card', 'delete_portal_card'), true)) {
                if ($posted_id !== $reservation_id) {
                    if ($action === 'refresh_platform_reservation') {
                        $platform_refresh_feedback = array(
                            'type' => 'error',
                            'messages' => array(__('Invalid reservation request. Please refresh and try again.', 'guest-management-system')),
                        );
                    } elseif (in_array($action, array('create_portal_card', 'delete_portal_card'), true)) {
                        $portal_card_feedback = array(
                            'type' => 'error',
                            'messages' => array(__('Invalid reservation request. Please refresh and try again.', 'guest-management-system')),
                        );
                    } else {
                        $step_feedback_key = $action === 'send_portal_link' ? 'portal' : ($action === 'send_door_code_bundle' ? 'door_code' : 'welcome');
                        $step_feedback[$step_feedback_key] = array(
                            'type' => 'error',
                            'messages' => array(__('Invalid reservation request. Please refresh and try again.', 'guest-management-system')),
                        );
                    }
                } else {
                    $manual_action_processed = true;

                    switch ($action) {
                        case 'send_portal_link':
                            check_admin_referer('gms_send_portal_link_' . $reservation_id);
                            $step_feedback['portal'] = $this->trigger_portal_link_delivery($reservation_id);
                            break;

                        case 'send_door_code_bundle':
                            check_admin_referer('gms_send_door_code_' . $reservation_id);
                            $step_feedback['door_code'] = $this->trigger_door_code_delivery($reservation_id);
                            break;

                        case 'send_welcome_sequence':
                            check_admin_referer('gms_send_welcome_' . $reservation_id);
                            $step_feedback['welcome'] = $this->trigger_welcome_delivery($reservation_id);
                            break;
                        case 'create_portal_card':
                            check_admin_referer('gms_create_portal_card_' . $reservation_id);
                            $portal_card_feedback = $this->create_portal_card_from_request($reservation_id);
                            break;
                        case 'delete_portal_card':
                            check_admin_referer('gms_delete_portal_card_' . $reservation_id);
                            $portal_card_feedback = $this->delete_portal_card_from_request($reservation_id);
                            break;
                        case 'refresh_platform_reservation':
                            check_admin_referer('gms_refresh_platform_' . $reservation_id);
                            $platform_refresh_feedback = $this->refresh_reservation_from_platform($reservation);
                            break;
                    }
                }
            }

            if (!$manual_action_processed) {
                check_admin_referer('gms_edit_reservation_' . $reservation_id);

                if ($posted_id !== $reservation_id) {
                    $errors[] = __('Invalid reservation request. Please try again.', 'guest-management-system');
                }

                $fields = array_keys($form_values);

                foreach ($fields as $field) {
                    $value = isset($_POST[$field]) ? wp_unslash($_POST[$field]) : '';

                    switch ($field) {
                        case 'guest_email':
                            $form_values[$field] = sanitize_email($value);
                            break;
                        case 'door_code':
                            $form_values[$field] = GMS_Database::sanitizeDoorCode($value);
                            break;
                        case 'checkin_date':
                        case 'checkout_date':
                            $form_values[$field] = $this->format_datetime_for_input($value);
                            break;
                        default:
                            $form_values[$field] = sanitize_text_field($value);
                            break;
                    }
                }

                if (empty($form_values['guest_name'])) {
                    $errors[] = __('Guest name is required.', 'guest-management-system');
                }

                if ($form_values['guest_email'] !== '' && !is_email($form_values['guest_email'])) {
                    $errors[] = __('Please enter a valid email address.', 'guest-management-system');
                }

                if ($form_values['door_code'] !== '' && !preg_match('/^\d{4}$/', $form_values['door_code'])) {
                    $errors[] = __('Door code must be a 4-digit number.', 'guest-management-system');
                }

                $allowed_statuses = array_keys($status_options);
                if (!in_array($form_values['status'], $allowed_statuses, true)) {
                    $form_values['status'] = 'pending';
                }

                if (empty($errors)) {
                    $normalized_name = trim(sanitize_text_field($form_values['guest_name']));
                    $normalized_email = sanitize_email($form_values['guest_email']);
                    $normalized_phone = $form_values['guest_phone'];

                    if (function_exists('gms_sanitize_phone')) {
                        $normalized_phone = gms_sanitize_phone($normalized_phone);
                    } else {
                        $normalized_phone = sanitize_text_field($normalized_phone);
                    }

                    $form_values['guest_name'] = $normalized_name;
                    $form_values['guest_email'] = $normalized_email;
                    $form_values['guest_phone'] = $normalized_phone;

                    $guest_record_id = GMS_Database::upsert_guest(array(
                        'name' => $form_values['guest_name'],
                        'email' => $form_values['guest_email'],
                        'phone' => $form_values['guest_phone'],
                    ), array(
                        'force_user_creation' => !empty($form_values['guest_email']) && is_email($form_values['guest_email']),
                    ));

                    if ($guest_record_id <= 0) {
                        $errors[] = __('Unable to save guest details. Please try again.', 'guest-management-system');
                    } else {
                        $guest_id = GMS_Database::ensure_guest_user($guest_record_id, array(
                            'full_name' => $form_values['guest_name'],
                            'email' => $form_values['guest_email'],
                            'phone' => $form_values['guest_phone'],
                        ), !empty($form_values['guest_email']) && is_email($form_values['guest_email']));

                        $update_data = array(
                            'guest_id' => $guest_id,
                            'guest_record_id' => $guest_record_id,
                            'guest_name' => $form_values['guest_name'],
                            'guest_email' => $form_values['guest_email'],
                            'guest_phone' => $form_values['guest_phone'],
                            'property_name' => $form_values['property_name'],
                            'property_id' => $form_values['property_id'],
                            'booking_reference' => $form_values['booking_reference'],
                            'door_code' => $form_values['door_code'],
                            'checkin_date' => $this->format_datetime_for_database($form_values['checkin_date']),
                            'checkout_date' => $this->format_datetime_for_database($form_values['checkout_date']),
                            'status' => $form_values['status'],
                        );

                        $updated = GMS_Database::updateReservation($reservation_id, $update_data);

                        if ($updated) {
                            $door_code_changed = ($form_values['door_code'] !== $original_door_code);

                            if ($door_code_changed && $form_values['door_code'] !== '') {
                                $reservation_for_notifications = GMS_Database::getReservationById($reservation_id);

                                if ($reservation_for_notifications) {
                                    $sms_handler = new GMS_SMS_Handler();
                                    $sms_handler->sendDoorCodeSMS($reservation_for_notifications, $form_values['door_code']);

                                    $email_handler = new GMS_Email_Handler();
                                    $email_handler->sendDoorCodeEmail($reservation_for_notifications, $form_values['door_code']);

                                    $this->log_portal_door_code_update(
                                        $reservation_id,
                                        intval($reservation_for_notifications['guest_id'] ?? 0),
                                        $form_values['door_code']
                                    );
                                }
                            }

                            $reservation = GMS_Database::getReservationById($reservation_id);
                            if ($reservation) {
                                $form_values = $this->map_reservation_to_form_values($reservation);
                                $original_door_code = isset($reservation['door_code']) ? (string) $reservation['door_code'] : '';
                            }

                            $redirect_url = add_query_arg(
                                array(
                                    'page' => 'guest-management-reservations',
                                    'action' => 'edit',
                                    'reservation_id' => $reservation_id,
                                    'gms_reservation_updated' => 1,
                                ),
                                admin_url('admin.php')
                            );

                            $redirected = false;

                            if (!headers_sent()) {
                                $redirected = wp_safe_redirect($redirect_url);
                            }

                            if ($redirected) {
                                exit;
                            }

                            $success_notice = sprintf(
                                /* translators: 1: opening anchor tag, 2: closing anchor tag */
                                __('Reservation updated successfully. %1$sReturn to Reservations%2$s.', 'guest-management-system'),
                                '<a href="' . esc_url($list_url) . '">',
                                '</a>'
                            );
                        } else {
                            $errors[] = __('Unable to update reservation. Please try again.', 'guest-management-system');
                        }
                    }
                }
            }
        }

        $portal_cards = GMS_Database::get_portal_cards($reservation_id);

        if ($manual_action_processed) {
            $reservation = GMS_Database::getReservationById($reservation_id);
            if ($reservation) {
                $form_values = $this->map_reservation_to_form_values($reservation);
                $original_door_code = isset($reservation['door_code']) ? (string) $reservation['door_code'] : '';
            }
        }

        $communications = GMS_Database::getCommunicationsForReservation($reservation_id, array(
            'limit' => 150,
            'order' => 'DESC',
        ));

        $day_in_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $hour_in_seconds = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;

        $checkin_timestamp = isset($reservation['checkin_date']) && $reservation['checkin_date'] !== ''
            ? strtotime($reservation['checkin_date'])
            : 0;

        $checkout_timestamp = isset($reservation['checkout_date']) && $reservation['checkout_date'] !== ''
            ? strtotime($reservation['checkout_date'])
            : 0;

        $portal_schedule_timestamp = $checkin_timestamp ? $checkin_timestamp - (14 * $day_in_seconds) : 0;
        $door_code_schedule_timestamp = $checkin_timestamp ? $checkin_timestamp - (7 * $day_in_seconds) : 0;
        $welcome_schedule_timestamp = $checkin_timestamp ? $checkin_timestamp - (2 * $hour_in_seconds) : 0;

        $portal_logs = $this->filter_communications_by_context($communications, array('portal_link_sequence'));
        $door_code_logs = $this->filter_communications_by_context($communications, array('door_code_sequence'), array('portal_update'));
        $welcome_logs = $this->filter_communications_by_context($communications, array('welcome_sequence'));

        $activity_total = is_array($communications) ? count($communications) : 0;
        $activity_logs = $activity_total > 0 ? array_slice($communications, 0, 30) : array();
        $latest_activity_display = ($activity_total > 0 && isset($communications[0]['sent_at']))
            ? $this->format_admin_datetime($communications[0]['sent_at'])
            : '';

        $now = current_time('timestamp');

        $portal_status = $this->determine_step_status($portal_logs, $portal_schedule_timestamp, $now);
        $door_code_status = $this->determine_step_status($door_code_logs, $door_code_schedule_timestamp, $now);
        $welcome_status = $this->determine_step_status($welcome_logs, $welcome_schedule_timestamp, $now);

        $portal_status_meta = $this->get_step_status_meta($portal_status);
        $door_code_status_meta = $this->get_step_status_meta($door_code_status);
        $welcome_status_meta = $this->get_step_status_meta($welcome_status);

        $portal_schedule_text = $this->format_schedule_description($checkin_timestamp, $portal_schedule_timestamp, __('14 days before check-in', 'guest-management-system'), $now);
        $door_code_schedule_text = $this->format_schedule_description($checkin_timestamp, $door_code_schedule_timestamp, __('7 days before check-in', 'guest-management-system'), $now);
        $welcome_schedule_text = $this->format_schedule_description($checkin_timestamp, $welcome_schedule_timestamp, __('2 hours before check-in', 'guest-management-system'), $now);

        $checkin_countdown_text = '';
        if ($checkin_timestamp) {
            if ($checkin_timestamp > $now) {
                $diff = $checkin_timestamp - $now;
                if ($diff >= $day_in_seconds) {
                    $days_remaining = (int) ceil($diff / $day_in_seconds);
                    $checkin_countdown_text = sprintf(
                        _n('%s day until check-in', '%s days until check-in', $days_remaining, 'guest-management-system'),
                        number_format_i18n($days_remaining)
                    );
                } else {
                    $hours_remaining = max(1, (int) ceil($diff / $hour_in_seconds));
                    $checkin_countdown_text = sprintf(
                        _n('%s hour until check-in', '%s hours until check-in', $hours_remaining, 'guest-management-system'),
                        number_format_i18n($hours_remaining)
                    );
                }
            } else {
                if ($checkout_timestamp && $checkout_timestamp > $now) {
                    $checkin_countdown_text = __('Guest is currently checked in.', 'guest-management-system');
                } else {
                    $checkin_countdown_text = __('Stay has ended.', 'guest-management-system');
                }
            }
        }

        $portal_last_sent = !empty($portal_logs) ? $this->format_admin_datetime($portal_logs[0]['sent_at'] ?? '') : '';
        $door_code_last_sent = !empty($door_code_logs) ? $this->format_admin_datetime($door_code_logs[0]['sent_at'] ?? '') : '';
        $welcome_last_sent = !empty($welcome_logs) ? $this->format_admin_datetime($welcome_logs[0]['sent_at'] ?? '') : '';

        $portal_url = gms_get_portal_url($reservation_id);
        $door_code_display = $form_values['door_code'] !== '' ? $form_values['door_code'] : '';
        $checkin_display = $this->format_admin_datetime($reservation['checkin_date'] ?? '');
        $checkout_display = $this->format_admin_datetime($reservation['checkout_date'] ?? '');
        $status_label = isset($status_options[$form_values['status']]) ? $status_options[$form_values['status']] : ucfirst($form_values['status']);

        $platform_key = $this->normalize_platform_key($reservation['platform'] ?? '');
        $platform_label = $this->describe_platform_label($platform_key);
        $platform_credentials_ready = $this->platform_credentials_ready($platform_key);
        $platform_meta = $this->extract_platform_sync_snapshot($reservation, $platform_key);
        $platform_button_label = $platform_label !== '' ? $platform_label : __('Platform', 'guest-management-system');

        $cancel_url = $list_url;

        ?>
        <div class="wrap gms-reservation-detail">
            <h1 class="wp-heading-inline"><?php esc_html_e('Edit Reservation', 'guest-management-system'); ?></h1>
            <a href="<?php echo esc_url($cancel_url); ?>" class="page-title-action"><?php esc_html_e('Back to Reservations', 'guest-management-system'); ?></a>
            <hr class="wp-header-end">

            <?php if (isset($_GET['gms_reservation_updated'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Reservation updated successfully.', 'guest-management-system'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($success_notice) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo wp_kses_post($success_notice); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)) : ?>
                <div class="notice notice-error">
                    <ul>
                        <?php foreach ($errors as $error) : ?>
                            <li><?php echo esc_html($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="gms-reservation-hero">
                <div class="gms-reservation-hero__intro">
                    <p class="gms-reservation-hero__eyebrow"><?php esc_html_e('Guest journey automation', 'guest-management-system'); ?></p>
                    <h2 class="gms-reservation-hero__headline"><?php esc_html_e('Reservation snapshot', 'guest-management-system'); ?></h2>
                    <p class="gms-reservation-hero__copy"><?php esc_html_e('Review the stay at a glance and keep each milestone on schedule.', 'guest-management-system'); ?></p>
                    <?php if ($checkin_countdown_text !== '') : ?>
                        <span class="gms-reservation-hero__countdown"><?php echo esc_html($checkin_countdown_text); ?></span>
                    <?php endif; ?>
                </div>
                <div class="gms-reservation-hero__cards">
                    <div class="gms-reservation-overview">
                        <div class="gms-reservation-overview__item">
                            <span class="gms-reservation-overview__label"><?php esc_html_e('Guest', 'guest-management-system'); ?></span>
                            <span class="gms-reservation-overview__value"><?php echo esc_html($form_values['guest_name'] !== '' ? $form_values['guest_name'] : __('Unknown Guest', 'guest-management-system')); ?></span>
                            <?php if ($form_values['guest_email'] !== '') : ?>
                                <span class="gms-reservation-overview__meta"><?php echo esc_html($form_values['guest_email']); ?></span>
                            <?php endif; ?>
                            <?php if ($form_values['guest_phone'] !== '') : ?>
                                <span class="gms-reservation-overview__meta"><?php echo esc_html($form_values['guest_phone']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="gms-reservation-overview__item">
                            <span class="gms-reservation-overview__label"><?php esc_html_e('Property', 'guest-management-system'); ?></span>
                            <span class="gms-reservation-overview__value"><?php echo esc_html($form_values['property_name'] !== '' ? $form_values['property_name'] : __('Not set', 'guest-management-system')); ?></span>
                            <?php if ($form_values['booking_reference'] !== '') : ?>
                                <span class="gms-reservation-overview__meta"><?php printf(esc_html__('Booking Ref: %s', 'guest-management-system'), esc_html($form_values['booking_reference'])); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="gms-reservation-overview__item">
                            <span class="gms-reservation-overview__label"><?php esc_html_e('Stay', 'guest-management-system'); ?></span>
                            <span class="gms-reservation-overview__value"><?php echo esc_html($checkin_display !== '' ? $checkin_display : __('Check-in not scheduled', 'guest-management-system')); ?></span>
                            <?php if ($checkout_display !== '') : ?>
                                <span class="gms-reservation-overview__meta"><?php echo esc_html(sprintf(__('Check-out: %s', 'guest-management-system'), $checkout_display)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="gms-reservation-overview__item">
                            <span class="gms-reservation-overview__label"><?php esc_html_e('Status', 'guest-management-system'); ?></span>
                            <span class="gms-reservation-overview__badge"><?php echo esc_html($status_label); ?></span>
                            <?php if ($portal_url) : ?>
                                <a class="gms-reservation-overview__link" href="<?php echo esc_url($portal_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open guest portal', 'guest-management-system'); ?></a>
                            <?php else : ?>
                                <span class="gms-reservation-overview__meta"><?php esc_html_e('Portal link will appear after activation.', 'guest-management-system'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="gms-reservation-toolbar" role="navigation" aria-label="<?php esc_attr_e('Reservation workflow shortcuts', 'guest-management-system'); ?>">
                <div class="gms-reservation-toolbar__nav">
                    <a class="gms-reservation-toolbar__step gms-reservation-toolbar__step--<?php echo esc_attr($portal_status); ?>" href="#gms-step-portal" aria-label="<?php echo esc_attr(sprintf(__('Jump to portal invitation step. Status: %s.', 'guest-management-system'), $portal_status_meta['label'])); ?>">
                        <span class="gms-reservation-toolbar__step-index" aria-hidden="true">1</span>
                        <span class="gms-reservation-toolbar__step-content">
                            <span class="gms-reservation-toolbar__step-label"><?php esc_html_e('Portal Invite', 'guest-management-system'); ?></span>
                            <span class="gms-reservation-toolbar__step-status"><?php echo esc_html($portal_status_meta['label']); ?></span>
                        </span>
                    </a>
                    <a class="gms-reservation-toolbar__step gms-reservation-toolbar__step--<?php echo esc_attr($door_code_status); ?>" href="#gms-step-door-code" aria-label="<?php echo esc_attr(sprintf(__('Jump to door code delivery step. Status: %s.', 'guest-management-system'), $door_code_status_meta['label'])); ?>">
                        <span class="gms-reservation-toolbar__step-index" aria-hidden="true">2</span>
                        <span class="gms-reservation-toolbar__step-content">
                            <span class="gms-reservation-toolbar__step-label"><?php esc_html_e('Door Code', 'guest-management-system'); ?></span>
                            <span class="gms-reservation-toolbar__step-status"><?php echo esc_html($door_code_status_meta['label']); ?></span>
                        </span>
                    </a>
                    <a class="gms-reservation-toolbar__step gms-reservation-toolbar__step--<?php echo esc_attr($welcome_status); ?>" href="#gms-step-welcome" aria-label="<?php echo esc_attr(sprintf(__('Jump to welcome touchpoint step. Status: %s.', 'guest-management-system'), $welcome_status_meta['label'])); ?>">
                        <span class="gms-reservation-toolbar__step-index" aria-hidden="true">3</span>
                        <span class="gms-reservation-toolbar__step-content">
                            <span class="gms-reservation-toolbar__step-label"><?php esc_html_e('Welcome Touchpoint', 'guest-management-system'); ?></span>
                            <span class="gms-reservation-toolbar__step-status"><?php echo esc_html($welcome_status_meta['label']); ?></span>
                        </span>
                    </a>
                </div>
                <div class="gms-reservation-toolbar__meta">
                    <?php if ($checkin_display !== '') : ?>
                        <span class="gms-reservation-toolbar__pill">
                            <span class="gms-reservation-toolbar__pill-label"><?php esc_html_e('Check-in', 'guest-management-system'); ?></span>
                            <span class="gms-reservation-toolbar__pill-value"><?php echo esc_html($checkin_display); ?></span>
                        </span>
                    <?php endif; ?>
                    <?php if ($checkout_display !== '') : ?>
                        <span class="gms-reservation-toolbar__pill">
                            <span class="gms-reservation-toolbar__pill-label"><?php esc_html_e('Check-out', 'guest-management-system'); ?></span>
                            <span class="gms-reservation-toolbar__pill-value"><?php echo esc_html($checkout_display); ?></span>
                        </span>
                    <?php endif; ?>
                    <?php if ($door_code_display !== '') : ?>
                        <button type="button" class="gms-reservation-toolbar__pill gms-reservation-toolbar__pill--interactive gms-copy-trigger" data-copy-value="<?php echo esc_attr($door_code_display); ?>" data-copy-success="<?php esc_attr_e('Door code copied to clipboard.', 'guest-management-system'); ?>" data-copy-error="<?php esc_attr_e('Unable to copy door code.', 'guest-management-system'); ?>">
                            <span class="gms-reservation-toolbar__pill-label"><?php esc_html_e('Door Code', 'guest-management-system'); ?></span>
                            <span class="gms-reservation-toolbar__pill-value"><?php echo esc_html($door_code_display); ?></span>
                        </button>
                    <?php endif; ?>
                    <?php if ($portal_url) : ?>
                        <a class="gms-reservation-toolbar__pill gms-reservation-toolbar__pill--action gms-open-portal" href="<?php echo esc_url($portal_url); ?>" target="_blank" rel="noopener noreferrer" data-copy-url="<?php echo esc_attr($portal_url); ?>"><?php esc_html_e('Open Guest Portal', 'guest-management-system'); ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="gms-reservation-detail__grid">
                <section id="gms-step-portal" class="gms-reservation-step gms-reservation-step--<?php echo esc_attr($portal_status); ?>">
                        <div class="gms-reservation-step__header">
                            <div class="gms-reservation-step__title">
                                <span class="gms-reservation-step__icon" aria-hidden="true">
                                    <span class="gms-reservation-step__number">1</span>
                                </span>
                                <div>
                                    <h2><?php esc_html_e('Guest Portal Invitation', 'guest-management-system'); ?></h2>
                                    <p><?php esc_html_e('Send the secure guest portal link 14 days before arrival so the guest can complete their tasks.', 'guest-management-system'); ?></p>
                                </div>
                            </div>
                            <div class="gms-reservation-step__meta">
                                <span class="gms-status-badge <?php echo esc_attr($portal_status_meta['class']); ?>"><?php echo esc_html($portal_status_meta['label']); ?></span>
                                <span class="gms-reservation-step__schedule"><?php echo esc_html($portal_schedule_text); ?></span>
                                <?php if ($portal_last_sent !== '') : ?>
                                    <span class="gms-reservation-step__timestamp"><?php printf(esc_html__('Last activity: %s', 'guest-management-system'), esc_html($portal_last_sent)); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="gms-reservation-step__layout">
                            <div class="gms-reservation-step__actions">
                                <?php if (!empty($step_feedback['portal']['messages'])) : ?>
                                    <div class="gms-step-feedback <?php echo esc_attr($this->map_feedback_class($step_feedback['portal']['type'])); ?>">
                                        <ul>
                                            <?php foreach ($step_feedback['portal']['messages'] as $message) : ?>
                                                <li><?php echo esc_html($message); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <form method="post" class="gms-reservation-step__form">
                                    <?php wp_nonce_field('gms_send_portal_link_' . $reservation_id); ?>
                                    <input type="hidden" name="gms_action" value="send_portal_link">
                                    <input type="hidden" name="reservation_id" value="<?php echo esc_attr($reservation_id); ?>">
                                    <button type="submit" class="button button-primary"><?php esc_html_e('Send Portal Link Now', 'guest-management-system'); ?></button>
                                    <span class="gms-reservation-step__hint"><?php esc_html_e('Delivers email/SMS when contact info is available and posts to connected OTA inboxes.', 'guest-management-system'); ?></span>
                                </form>

                            </div>
                            <div class="gms-reservation-step__history">
                                <h3 class="gms-reservation-step__history-title"><?php esc_html_e('Portal link activity', 'guest-management-system'); ?></h3>
                                <?php $this->render_step_timeline($portal_logs); ?>
                            </div>
                        </div>
                </section>

                <section id="gms-step-door-code" class="gms-reservation-step gms-reservation-step--<?php echo esc_attr($door_code_status); ?>">
                        <div class="gms-reservation-step__header">
                            <div class="gms-reservation-step__title">
                                <span class="gms-reservation-step__icon" aria-hidden="true">
                                    <span class="gms-reservation-step__number">2</span>
                                </span>
                                <div>
                                    <h2><?php esc_html_e('Access Code Delivery', 'guest-management-system'); ?></h2>
                                    <p><?php esc_html_e('Share the door code and sync it to the portal 7 days before check-in.', 'guest-management-system'); ?></p>
                                </div>
                            </div>
                            <div class="gms-reservation-step__meta">
                                <span class="gms-status-badge <?php echo esc_attr($door_code_status_meta['class']); ?>"><?php echo esc_html($door_code_status_meta['label']); ?></span>
                                <span class="gms-reservation-step__schedule"><?php echo esc_html($door_code_schedule_text); ?></span>
                                <?php if ($door_code_last_sent !== '') : ?>
                                    <span class="gms-reservation-step__timestamp"><?php printf(esc_html__('Last activity: %s', 'guest-management-system'), esc_html($door_code_last_sent)); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="gms-reservation-step__layout">
                            <div class="gms-reservation-step__actions">
                                <?php if (!empty($step_feedback['door_code']['messages'])) : ?>
                                    <div class="gms-step-feedback <?php echo esc_attr($this->map_feedback_class($step_feedback['door_code']['type'])); ?>">
                                        <ul>
                                            <?php foreach ($step_feedback['door_code']['messages'] as $message) : ?>
                                                <li><?php echo esc_html($message); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <form method="post" class="gms-reservation-step__form">
                                    <?php wp_nonce_field('gms_send_door_code_' . $reservation_id); ?>
                                    <input type="hidden" name="gms_action" value="send_door_code_bundle">
                                    <input type="hidden" name="reservation_id" value="<?php echo esc_attr($reservation_id); ?>">
                                    <button type="submit" class="button button-primary"><?php esc_html_e('Send Door Code Package', 'guest-management-system'); ?></button>
                                    <span class="gms-reservation-step__hint"><?php esc_html_e('Sends email, SMS, records a portal update, and posts to OTA inboxes when configured.', 'guest-management-system'); ?></span>
                                </form>
                            </div>
                            <div class="gms-reservation-step__history">
                                <h3 class="gms-reservation-step__history-title"><?php esc_html_e('Access package activity', 'guest-management-system'); ?></h3>
                                <?php $this->render_step_timeline($door_code_logs); ?>
                            </div>
                        </div>
                </section>

                <section id="gms-step-welcome" class="gms-reservation-step gms-reservation-step--<?php echo esc_attr($welcome_status); ?>">
                        <div class="gms-reservation-step__header">
                            <div class="gms-reservation-step__title">
                                <span class="gms-reservation-step__icon" aria-hidden="true">
                                    <span class="gms-reservation-step__number">3</span>
                                </span>
                                <div>
                                    <h2><?php esc_html_e('Welcome Touchpoint', 'guest-management-system'); ?></h2>
                                    <p><?php esc_html_e('Send a warm welcome message two hours before arrival to confirm everything is ready.', 'guest-management-system'); ?></p>
                                </div>
                            </div>
                            <div class="gms-reservation-step__meta">
                                <span class="gms-status-badge <?php echo esc_attr($welcome_status_meta['class']); ?>"><?php echo esc_html($welcome_status_meta['label']); ?></span>
                                <span class="gms-reservation-step__schedule"><?php echo esc_html($welcome_schedule_text); ?></span>
                                <?php if ($welcome_last_sent !== '') : ?>
                                    <span class="gms-reservation-step__timestamp"><?php printf(esc_html__('Last activity: %s', 'guest-management-system'), esc_html($welcome_last_sent)); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="gms-reservation-step__layout">
                            <div class="gms-reservation-step__actions">
                                <?php if (!empty($step_feedback['welcome']['messages'])) : ?>
                                    <div class="gms-step-feedback <?php echo esc_attr($this->map_feedback_class($step_feedback['welcome']['type'])); ?>">
                                        <ul>
                                            <?php foreach ($step_feedback['welcome']['messages'] as $message) : ?>
                                                <li><?php echo esc_html($message); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <form method="post" class="gms-reservation-step__form">
                                    <?php wp_nonce_field('gms_send_welcome_' . $reservation_id); ?>
                                    <input type="hidden" name="gms_action" value="send_welcome_sequence">
                                    <input type="hidden" name="reservation_id" value="<?php echo esc_attr($reservation_id); ?>">
                                    <button type="submit" class="button button-primary"><?php esc_html_e('Send Welcome Message', 'guest-management-system'); ?></button>
                                    <span class="gms-reservation-step__hint"><?php esc_html_e('Delivers email/SMS greetings and posts to OTA inboxes when configured.', 'guest-management-system'); ?></span>
                                </form>
                            </div>
                            <div class="gms-reservation-step__history">
                                <h3 class="gms-reservation-step__history-title"><?php esc_html_e('Welcome message activity', 'guest-management-system'); ?></h3>
                                <?php $this->render_step_timeline($welcome_logs); ?>
                            </div>
                        </div>
                </section>

                <div class="gms-panel gms-contact-panel">
                        <div class="gms-contact-panel__header">
                            <h2><?php esc_html_e('Guest Directory', 'guest-management-system'); ?></h2>
                            <?php if ($portal_url) : ?>
                                <a class="button button-secondary gms-open-portal" href="<?php echo esc_url($portal_url); ?>" target="_blank" rel="noopener noreferrer" data-copy-url="<?php echo esc_attr($portal_url); ?>"><?php esc_html_e('Launch Portal', 'guest-management-system'); ?></a>
                            <?php endif; ?>
                        </div>
                        <p class="gms-contact-panel__intro"><?php esc_html_e('Reach guests instantly and keep reservation essentials handy.', 'guest-management-system'); ?></p>
                        <dl class="gms-contact-panel__list">
                            <div class="gms-contact-panel__item">
                                <dt><?php esc_html_e('Guest', 'guest-management-system'); ?></dt>
                                <dd><?php echo esc_html($form_values['guest_name'] !== '' ? $form_values['guest_name'] : __('Unknown Guest', 'guest-management-system')); ?></dd>
                            </div>
                            <div class="gms-contact-panel__item">
                                <dt><?php esc_html_e('Email', 'guest-management-system'); ?></dt>
                                <dd><?php echo $form_values['guest_email'] !== '' ? esc_html($form_values['guest_email']) : '<span class="gms-contact-panel__muted">' . esc_html__('Not provided', 'guest-management-system') . '</span>'; ?></dd>
                            </div>
                            <div class="gms-contact-panel__item">
                                <dt><?php esc_html_e('Phone', 'guest-management-system'); ?></dt>
                                <dd><?php echo $form_values['guest_phone'] !== '' ? esc_html($form_values['guest_phone']) : '<span class="gms-contact-panel__muted">' . esc_html__('Not provided', 'guest-management-system') . '</span>'; ?></dd>
                            </div>
                            <div class="gms-contact-panel__item">
                                <dt><?php esc_html_e('Property', 'guest-management-system'); ?></dt>
                                <dd><?php echo esc_html($form_values['property_name'] !== '' ? $form_values['property_name'] : __('Not set', 'guest-management-system')); ?></dd>
                            </div>
                            <div class="gms-contact-panel__item">
                                <dt><?php esc_html_e('Status', 'guest-management-system'); ?></dt>
                                <dd><span class="gms-contact-panel__badge"><?php echo esc_html($status_label); ?></span></dd>
                            </div>
                            <?php if ($door_code_display !== '') : ?>
                                <div class="gms-contact-panel__item">
                                    <dt><?php esc_html_e('Door Code', 'guest-management-system'); ?></dt>
                                    <dd><?php echo esc_html($door_code_display); ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if ($checkin_display !== '' || $checkout_display !== '') : ?>
                                <div class="gms-contact-panel__item">
                                    <dt><?php esc_html_e('Stay Window', 'guest-management-system'); ?></dt>
                                    <dd>
                                        <?php if ($checkin_display !== '') : ?>
                                            <span class="gms-contact-panel__meta"><?php printf(esc_html__('Check-in: %s', 'guest-management-system'), esc_html($checkin_display)); ?></span>
                                        <?php endif; ?>
                                        <?php if ($checkout_display !== '') : ?>
                                            <span class="gms-contact-panel__meta"><?php printf(esc_html__('Check-out: %s', 'guest-management-system'), esc_html($checkout_display)); ?></span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                    </div>
                <div class="gms-panel gms-portal-card-admin">
                        <h2><?php esc_html_e('Guest Portal Promotions', 'guest-management-system'); ?></h2>
                        <p class="description"><?php esc_html_e('Publish optional cards that appear in the guest portal alongside the door code, handbook, and other stay resources.', 'guest-management-system'); ?></p>

                        <?php if (!empty($portal_card_feedback['messages'])) : ?>
                            <div class="gms-step-feedback <?php echo esc_attr($this->map_feedback_class($portal_card_feedback['type'])); ?>">
                                <ul>
                                    <?php foreach ($portal_card_feedback['messages'] as $message) : ?>
                                        <li><?php echo esc_html($message); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($portal_cards)) : ?>
                            <ul class="gms-portal-card-admin__list">
                                <?php foreach ($portal_cards as $card) : ?>
                                    <li class="gms-portal-card-admin__item">
                                        <div class="gms-portal-card-admin__item-meta">
                                            <?php if (!empty($card['icon'])) : ?>
                                                <span class="gms-portal-card-admin__icon" aria-hidden="true"><?php echo esc_html($card['icon']); ?></span>
                                            <?php endif; ?>
                                            <strong><?php echo esc_html($card['title']); ?></strong>
                                        </div>
                                        <?php if (!empty($card['body'])) : ?>
                                            <div class="gms-portal-card-admin__body"><?php echo wp_kses_post(wpautop($card['body'])); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($card['cta_label']) && !empty($card['cta_url'])) : ?>
                                            <p class="gms-portal-card-admin__cta">
                                                <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                                                <a href="<?php echo esc_url($card['cta_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($card['cta_label']); ?></a>
                                            </p>
                                        <?php endif; ?>
                                        <form method="post" class="gms-portal-card-admin__delete">
                                            <?php wp_nonce_field('gms_delete_portal_card_' . $reservation_id); ?>
                                            <input type="hidden" name="gms_action" value="delete_portal_card">
                                            <input type="hidden" name="reservation_id" value="<?php echo esc_attr($reservation_id); ?>">
                                            <input type="hidden" name="portal_card_id" value="<?php echo esc_attr($card['id']); ?>">
                                            <button type="submit" class="button button-link-delete"><?php esc_html_e('Remove from portal', 'guest-management-system'); ?></button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e('No promotional cards have been published yet.', 'guest-management-system'); ?></p>
                        <?php endif; ?>

                        <form method="post" class="gms-portal-card-admin__form">
                            <?php wp_nonce_field('gms_create_portal_card_' . $reservation_id); ?>
                            <input type="hidden" name="gms_action" value="create_portal_card">
                            <input type="hidden" name="reservation_id" value="<?php echo esc_attr($reservation_id); ?>">

                            <div class="gms-field">
                                <label for="gms-portal-card-title"><?php esc_html_e('Card headline', 'guest-management-system'); ?></label>
                                <input type="text" id="gms-portal-card-title" name="portal_card_title" class="regular-text" required>
                            </div>

                            <div class="gms-field">
                                <label for="gms-portal-card-body"><?php esc_html_e('Description (optional)', 'guest-management-system'); ?></label>
                                <textarea id="gms-portal-card-body" name="portal_card_body" rows="4" class="large-text"></textarea>
                            </div>

                            <div class="gms-field gms-field--inline">
                                <div>
                                    <label for="gms-portal-card-icon"><?php esc_html_e('Icon (emoji or short text)', 'guest-management-system'); ?></label>
                                    <input type="text" id="gms-portal-card-icon" name="portal_card_icon" class="regular-text" maxlength="10">
                                </div>
                                <div>
                                    <label for="gms-portal-card-cta-label"><?php esc_html_e('Button label', 'guest-management-system'); ?></label>
                                    <input type="text" id="gms-portal-card-cta-label" name="portal_card_cta_label" class="regular-text">
                                </div>
                                <div>
                                    <label for="gms-portal-card-cta-url"><?php esc_html_e('Button link', 'guest-management-system'); ?></label>
                                    <input type="url" id="gms-portal-card-cta-url" name="portal_card_cta_url" class="regular-text" placeholder="https://">
                                </div>
                            </div>

                            <?php submit_button(__('Publish card to portal', 'guest-management-system'), 'secondary', 'submit', false); ?>
                        </form>
                    </div>
                <div class="gms-panel gms-platform-panel">
                        <h2><?php esc_html_e('Platform Reservation', 'guest-management-system'); ?></h2>
                        <p><?php esc_html_e('Stay aligned with Airbnb, VRBO, and Booking.com by keeping this reservation in sync.', 'guest-management-system'); ?></p>

                        <div class="gms-platform-sync__summary">
                            <div class="gms-platform-sync__summary-item">
                                <span class="gms-platform-sync__label"><?php esc_html_e('Platform', 'guest-management-system'); ?></span>
                                <span class="gms-platform-sync__value"><?php echo $platform_label !== '' ? esc_html($platform_label) : esc_html__('Not set', 'guest-management-system'); ?></span>
                            </div>
                            <div class="gms-platform-sync__summary-item">
                                <span class="gms-platform-sync__label"><?php esc_html_e('Booking Reference', 'guest-management-system'); ?></span>
                                <span class="gms-platform-sync__value<?php echo $form_values['booking_reference'] === '' ? ' gms-platform-sync__value--muted' : ''; ?>"><?php echo $form_values['booking_reference'] !== '' ? esc_html($form_values['booking_reference']) : esc_html__('Not provided', 'guest-management-system'); ?></span>
                            </div>
                            <div class="gms-platform-sync__summary-item">
                                <span class="gms-platform-sync__label"><?php esc_html_e('Connection', 'guest-management-system'); ?></span>
                                <span class="gms-platform-sync__value<?php echo $platform_credentials_ready ? '' : ' gms-platform-sync__value--muted'; ?>"><?php echo $platform_credentials_ready ? esc_html__('Connected', 'guest-management-system') : esc_html__('Credentials missing', 'guest-management-system'); ?></span>
                            </div>
                            <div class="gms-platform-sync__summary-item">
                                <span class="gms-platform-sync__label"><?php esc_html_e('Last Synced', 'guest-management-system'); ?></span>
                                <?php
                                $last_synced_display = !empty($platform_meta['last_synced']) ? $this->format_admin_datetime($platform_meta['last_synced']) : '';
                                ?>
                                <span class="gms-platform-sync__value<?php echo $last_synced_display === '' ? ' gms-platform-sync__value--muted' : ''; ?>"><?php echo $last_synced_display !== '' ? esc_html($last_synced_display) : esc_html__('Not yet synced', 'guest-management-system'); ?></span>
                            </div>
                        </div>

                        <?php if (!empty($platform_meta['snapshot'])) : ?>
                            <ul class="gms-platform-sync__snapshot-list">
                                <?php if (!empty($platform_meta['snapshot']['status'])) : ?>
                                    <li><?php printf(esc_html__('Latest status: %s', 'guest-management-system'), esc_html(ucwords(str_replace('_', ' ', $platform_meta['snapshot']['status'])))); ?></li>
                                <?php elseif (!empty($platform_meta['snapshot']['status_raw'])) : ?>
                                    <li><?php printf(esc_html__('Latest status: %s', 'guest-management-system'), esc_html($platform_meta['snapshot']['status_raw'])); ?></li>
                                <?php endif; ?>
                                <?php if (!empty($platform_meta['snapshot']['checkin_date'])) : ?>
                                    <li><?php printf(esc_html__('Check-in synced: %s', 'guest-management-system'), esc_html($this->format_admin_datetime($platform_meta['snapshot']['checkin_date']))); ?></li>
                                <?php endif; ?>
                                <?php if (!empty($platform_meta['snapshot']['checkout_date'])) : ?>
                                    <li><?php printf(esc_html__('Check-out synced: %s', 'guest-management-system'), esc_html($this->format_admin_datetime($platform_meta['snapshot']['checkout_date']))); ?></li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if (!empty($platform_refresh_feedback['messages'])) : ?>
                            <div class="gms-step-feedback <?php echo esc_attr($this->map_feedback_class($platform_refresh_feedback['type'])); ?>">
                                <ul>
                                    <?php foreach ($platform_refresh_feedback['messages'] as $message) : ?>
                                        <li><?php echo esc_html($message); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if ($platform_key !== '' && $form_values['booking_reference'] !== '' && $platform_credentials_ready) : ?>
                            <form method="post" class="gms-platform-sync__form">
                                <?php wp_nonce_field('gms_refresh_platform_' . $reservation_id); ?>
                                <input type="hidden" name="gms_action" value="refresh_platform_reservation">
                                <input type="hidden" name="reservation_id" value="<?php echo esc_attr($reservation_id); ?>">
                                <button type="submit" class="button button-secondary"><?php printf(esc_html__('Refresh from %s', 'guest-management-system'), esc_html($platform_button_label)); ?></button>
                                <span class="gms-platform-sync__hint"><?php esc_html_e('Pull the latest guest and stay details from the connected platform.', 'guest-management-system'); ?></span>
                            </form>
                        <?php else : ?>
                            <p class="gms-platform-sync__hint"><?php esc_html_e('Add a platform, booking reference, and credentials to enable one-click sync.', 'guest-management-system'); ?></p>
                        <?php endif; ?>
                    </div>
                <div class="gms-panel">
                        <h2><?php esc_html_e('Reservation Details', 'guest-management-system'); ?></h2>
                        <form method="post" class="gms-reservation-form">
                            <?php wp_nonce_field('gms_edit_reservation_' . $reservation_id); ?>
                            <input type="hidden" name="gms_action" value="">
                            <input type="hidden" name="reservation_id" value="<?php echo esc_attr($reservation_id); ?>">
                            <div class="gms-reservation-form__grid">
                                <div class="gms-reservation-form__field">
                                    <label for="gms_guest_name_edit"><?php esc_html_e('Guest Name', 'guest-management-system'); ?></label>
                                    <input name="guest_name" type="text" id="gms_guest_name_edit" value="<?php echo esc_attr($form_values['guest_name']); ?>" required>
                                </div>
                                <div class="gms-reservation-form__field">
                                    <label for="gms_guest_email_edit"><?php esc_html_e('Guest Email', 'guest-management-system'); ?></label>
                                    <input name="guest_email" type="email" id="gms_guest_email_edit" value="<?php echo esc_attr($form_values['guest_email']); ?>">
                                </div>
                                <div class="gms-reservation-form__field">
                                    <label for="gms_guest_phone_edit"><?php esc_html_e('Guest Phone', 'guest-management-system'); ?></label>
                                    <input name="guest_phone" type="text" id="gms_guest_phone_edit" value="<?php echo esc_attr($form_values['guest_phone']); ?>">
                                </div>
                                <div class="gms-reservation-form__field">
                                    <label for="gms_property_name_edit"><?php esc_html_e('Property Name', 'guest-management-system'); ?></label>
                                    <input name="property_name" type="text" id="gms_property_name_edit" value="<?php echo esc_attr($form_values['property_name']); ?>">
                                </div>
                                <div class="gms-reservation-form__field">
                                    <label for="gms_property_id_edit"><?php esc_html_e('Property ID', 'guest-management-system'); ?></label>
                                    <input name="property_id" type="text" id="gms_property_id_edit" value="<?php echo esc_attr($form_values['property_id']); ?>">
                                </div>
                                <div class="gms-reservation-form__field">
                                    <label for="gms_booking_reference_edit"><?php esc_html_e('Booking Reference', 'guest-management-system'); ?></label>
                                    <input name="booking_reference" type="text" id="gms_booking_reference_edit" value="<?php echo esc_attr($form_values['booking_reference']); ?>">
                                </div>
                                <div class="gms-reservation-form__field">
                                    <label for="gms_door_code_edit"><?php esc_html_e('Door Code', 'guest-management-system'); ?></label>
                                    <input name="door_code" type="text" id="gms_door_code_edit" value="<?php echo esc_attr($form_values['door_code']); ?>" maxlength="4" pattern="\d{4}" inputmode="numeric">
                                    <p class="description"><?php esc_html_e('Provide the 4-digit entry code for the guest.', 'guest-management-system'); ?></p>
                                </div>
                                <div class="gms-reservation-form__field">
                                    <label for="gms_checkin_date_edit"><?php esc_html_e('Check-in Date', 'guest-management-system'); ?></label>
                                    <input name="checkin_date" type="datetime-local" id="gms_checkin_date_edit" value="<?php echo esc_attr($form_values['checkin_date']); ?>">
                                </div>
                                <div class="gms-reservation-form__field">
                                    <label for="gms_checkout_date_edit"><?php esc_html_e('Check-out Date', 'guest-management-system'); ?></label>
                                    <input name="checkout_date" type="datetime-local" id="gms_checkout_date_edit" value="<?php echo esc_attr($form_values['checkout_date']); ?>">
                                </div>
                                <div class="gms-reservation-form__field">
                                    <label for="gms_status_edit"><?php esc_html_e('Status', 'guest-management-system'); ?></label>
                                    <select name="status" id="gms_status_edit">
                                        <?php foreach ($status_options as $status_key => $status_text) : ?>
                                            <option value="<?php echo esc_attr($status_key); ?>"<?php selected($form_values['status'], $status_key); ?>><?php echo esc_html($status_text); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <?php submit_button(__('Update Reservation', 'guest-management-system')); ?>
                        </form>
                    </div>
                <div class="gms-panel gms-activity-panel">
                        <div class="gms-activity-panel__header">
                            <h2><?php esc_html_e('Communication Feed', 'guest-management-system'); ?></h2>
                            <?php if ($activity_total > 0) : ?>
                                <span class="gms-activity-panel__count"><?php printf(esc_html__('%d logged', 'guest-management-system'), absint($activity_total)); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="gms-activity-panel__intro"><?php esc_html_e('Most recent messages across email, SMS, OTA, and portal updates.', 'guest-management-system'); ?></p>
                        <?php if ($latest_activity_display !== '') : ?>
                            <p class="gms-activity-panel__meta"><?php printf(esc_html__('Last logged: %s', 'guest-management-system'), esc_html($latest_activity_display)); ?></p>
                        <?php endif; ?>
                        <?php $this->render_activity_timeline($activity_logs); ?>
                        <?php if ($activity_total > count($activity_logs)) : ?>
                            <p class="gms-activity-panel__hint"><?php esc_html_e('Showing the 30 most recent communications. View older activity in the messaging history.', 'guest-management-system'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function map_reservation_to_form_values($reservation) {
        return array(
            'guest_name' => isset($reservation['guest_name']) ? $reservation['guest_name'] : '',
            'guest_email' => isset($reservation['guest_email']) ? $reservation['guest_email'] : '',
            'guest_phone' => isset($reservation['guest_phone']) ? $reservation['guest_phone'] : '',
            'property_name' => isset($reservation['property_name']) ? $reservation['property_name'] : '',
            'property_id' => isset($reservation['property_id']) ? $reservation['property_id'] : '',
            'booking_reference' => isset($reservation['booking_reference']) ? $reservation['booking_reference'] : '',
            'door_code' => isset($reservation['door_code']) ? GMS_Database::sanitizeDoorCode($reservation['door_code']) : '',
            'checkin_date' => $this->format_datetime_for_input(isset($reservation['checkin_date']) ? $reservation['checkin_date'] : ''),
            'checkout_date' => $this->format_datetime_for_input(isset($reservation['checkout_date']) ? $reservation['checkout_date'] : ''),
            'status' => isset($reservation['status']) ? $reservation['status'] : 'pending',
        );
    }

    private function filter_communications_by_context($communications, $contexts, $include_types = array()) {
        $filtered = array();

        if (empty($communications) || !is_array($communications)) {
            return $filtered;
        }

        $contexts = array_filter(array_map('sanitize_key', (array) $contexts));
        $include_types = array_filter(array_map('sanitize_key', (array) $include_types));

        foreach ($communications as $communication) {
            if (!is_array($communication)) {
                continue;
            }

            $context_value = '';
            if (isset($communication['response_data']) && is_array($communication['response_data']) && isset($communication['response_data']['context'])) {
                $context_value = sanitize_key($communication['response_data']['context']);
            }

            $type_value = isset($communication['communication_type']) ? sanitize_key($communication['communication_type']) : '';

            $matches_context = (!empty($contexts) && $context_value !== '' && in_array($context_value, $contexts, true));

            if (!$matches_context && !empty($include_types)) {
                $matches_context = ($type_value !== '' && in_array($type_value, $include_types, true));
            }

            if ($matches_context) {
                $filtered[] = $communication;
            }
        }

        usort($filtered, function ($a, $b) {
            $a_time = isset($a['sent_at']) ? strtotime($a['sent_at']) : 0;
            $b_time = isset($b['sent_at']) ? strtotime($b['sent_at']) : 0;

            if ($a_time === $b_time) {
                $a_id = isset($a['id']) ? intval($a['id']) : 0;
                $b_id = isset($b['id']) ? intval($b['id']) : 0;
                return $b_id <=> $a_id;
            }

            return $b_time <=> $a_time;
        });

        return $filtered;
    }

    private function determine_step_status($logs, $scheduled_timestamp, $now) {
        if (!empty($logs)) {
            return 'complete';
        }

        if (empty($scheduled_timestamp)) {
            return 'upcoming';
        }

        if ($scheduled_timestamp <= $now) {
            return 'due';
        }

        return 'upcoming';
    }

    private function get_step_status_meta($status) {
        switch ($status) {
            case 'complete':
                return array(
                    'label' => __('Completed', 'guest-management-system'),
                    'class' => 'is-complete',
                );
            case 'due':
                return array(
                    'label' => __('Action recommended', 'guest-management-system'),
                    'class' => 'is-due',
                );
            default:
                return array(
                    'label' => __('Scheduled', 'guest-management-system'),
                    'class' => 'is-upcoming',
                );
        }
    }

    private function format_schedule_description($checkin_timestamp, $target_timestamp, $window_label, $now) {
        if (empty($checkin_timestamp)) {
            return __('Add a check-in date to calculate this automation.', 'guest-management-system');
        }

        if (empty($target_timestamp)) {
            return sprintf(__('Scheduled %s.', 'guest-management-system'), $window_label);
        }

        $formatted_target = $this->format_timestamp_for_admin($target_timestamp);

        if ($target_timestamp <= $now) {
            return sprintf(__('Would have sent on %1$s (%2$s). Ready whenever you are.', 'guest-management-system'), $formatted_target, $window_label);
        }

        return sprintf(__('Scheduled for %1$s (%2$s).', 'guest-management-system'), $formatted_target, $window_label);
    }

    private function get_housekeeper_reservations() {
        $query_args = apply_filters('gms_housekeeper_reservations_query_args', array(
            'per_page' => 500,
            'page' => 1,
            'orderby' => 'checkin_date',
            'order' => 'ASC',
        ));

        $raw_reservations = GMS_Database::get_reservations($query_args);

        $timezone = wp_timezone();
        $today = new \DateTimeImmutable('today', $timezone);
        $today_start = $today->getTimestamp();

        $window_days = (int) apply_filters('gms_housekeeper_schedule_window', 180);
        if ($window_days < 1) {
            $window_days = 180;
        }
        $future_limit = $today->modify('+' . $window_days . ' days')->getTimestamp();

        $excluded_statuses = apply_filters('gms_housekeeper_excluded_statuses', array('cancelled'));

        $reservations = array();

        foreach ($raw_reservations as $reservation) {
            $status = isset($reservation['status']) ? sanitize_key($reservation['status']) : '';
            if ($status !== '' && in_array($status, $excluded_statuses, true)) {
                continue;
            }

            $checkin_dt = $this->create_wp_datetime($reservation['checkin_date'] ?? '');
            if (!$checkin_dt) {
                continue;
            }

            $checkout_dt = $this->create_wp_datetime($reservation['checkout_date'] ?? '');
            if (!$checkout_dt || $checkout_dt <= $checkin_dt) {
                $checkout_dt = $checkin_dt->modify('+1 day');
            }

            $checkin_ts = $checkin_dt->getTimestamp();
            $checkout_ts = $checkout_dt->getTimestamp();

            if ($checkout_ts < $today_start) {
                continue;
            }

            if ($checkin_ts > $future_limit) {
                continue;
            }

            $guest_name = isset($reservation['guest_name']) ? trim((string) $reservation['guest_name']) : '';
            $property_name = isset($reservation['property_name']) ? trim((string) $reservation['property_name']) : '';

            $checkin_day = $checkin_dt->setTime(0, 0);
            $checkout_day = $checkout_dt->setTime(0, 0);

            $checkin_day_ts = $checkin_day->getTimestamp();
            $checkout_day_ts = $checkout_day->getTimestamp();
            $night_seconds = max(0, $checkout_day_ts - $checkin_day_ts);
            $nights = max(1, (int) round($night_seconds / DAY_IN_SECONDS));

            $reservations[] = array(
                'id' => isset($reservation['id']) ? absint($reservation['id']) : 0,
                'guest_name' => $guest_name,
                'property_name' => $property_name,
                'status' => $status,
                'housekeeper_url' => isset($reservation['housekeeper_url']) && is_string($reservation['housekeeper_url'])
                    ? trim($reservation['housekeeper_url'])
                    : '',
                'housekeeper_token' => isset($reservation['housekeeper_token'])
                    ? trim((string) $reservation['housekeeper_token'])
                    : '',
                'checkin' => $checkin_dt,
                'checkout' => $checkout_dt,
                'checkin_ts' => $checkin_ts,
                'checkout_ts' => $checkout_ts,
                'checkin_day_ts' => $checkin_day_ts,
                'checkout_day_ts' => $checkout_day_ts,
                'checkin_date_label' => $this->format_housekeeper_datetime($checkin_dt, 'date'),
                'checkout_date_label' => $this->format_housekeeper_datetime($checkout_dt, 'date'),
                'checkin_time_label' => $this->format_housekeeper_datetime($checkin_dt, 'time'),
                'checkout_time_label' => $this->format_housekeeper_datetime($checkout_dt, 'time'),
                'checkin_datetime_label' => $this->format_housekeeper_datetime($checkin_dt, 'datetime'),
                'checkout_datetime_label' => $this->format_housekeeper_datetime($checkout_dt, 'datetime'),
                'nights' => $nights,
            );
        }

        return apply_filters('gms_housekeeper_reservations', $reservations);
    }

    private function create_wp_datetime($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return new \DateTimeImmutable($datetime, wp_timezone());
        } catch (\Exception $exception) {
            return null;
        }
    }

    private function format_housekeeper_datetime(\DateTimeInterface $datetime, string $mode = 'datetime') {
        $date_format = get_option('date_format', 'M j, Y');
        $time_format = get_option('time_format', 'g:i a');

        switch ($mode) {
            case 'date':
                $format = $date_format;
                break;
            case 'time':
                $format = $time_format;
                break;
            default:
                $format = $date_format . ' ' . $time_format;
                break;
        }

        return wp_date($format, $datetime->getTimestamp(), wp_timezone());
    }

    private function escape_ical_text($text) {
        $text = wp_strip_all_tags((string) $text);
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $text = strtr($text, array(
            '\\' => '\\\\',
            ';' => '\\;',
            ',' => '\\,',
        ));
        $text = str_replace("\n", '\\n', $text);

        return trim($text);
    }

    private function format_admin_datetime($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return '';
        }

        $wp_datetime = $this->create_wp_datetime($datetime);
        if ($wp_datetime instanceof \DateTimeImmutable) {
            $timestamp = $wp_datetime->getTimestamp();
        } else {
            $timestamp = strtotime($datetime);
            if ($timestamp === false) {
                return '';
            }
        }

        return $this->format_timestamp_for_admin($timestamp);
    }

    private function format_timestamp_for_admin($timestamp) {
        if (empty($timestamp)) {
            return '';
        }

        $format = get_option('date_format', 'M j, Y') . ' ' . get_option('time_format', 'g:i a');

        return wp_date($format, $timestamp);
    }

    private function render_step_timeline($logs) {
        $this->render_timeline($logs, __('No activity recorded yet for this step.', 'guest-management-system'), 'step');
    }

    private function render_activity_timeline($logs) {
        $this->render_timeline($logs, __('No communications logged yet for this reservation.', 'guest-management-system'), 'activity');
    }

    private function render_timeline($logs, $empty_message, $context = 'step') {
        $empty_class = $context === 'activity' ? 'gms-reservation-activity__empty' : 'gms-reservation-step__empty';

        if (empty($logs)) {
            echo '<p class="' . esc_attr($empty_class) . '">' . esc_html($empty_message) . '</p>';
            return;
        }

        $classes = array('gms-reservation-timeline');
        if ($context !== '') {
            $classes[] = 'gms-reservation-timeline--' . sanitize_html_class($context);
        }

        echo '<ul class="' . esc_attr(implode(' ', $classes)) . '">';
        foreach ($logs as $log) {
            if (!is_array($log)) {
                continue;
            }

            $type_label = $this->describe_communication_type($log);
            $timestamp = $this->format_admin_datetime($log['sent_at'] ?? '');

            $summary_parts = array();

            $recipient = isset($log['recipient']) ? trim((string) $log['recipient']) : '';
            if ($recipient !== '') {
                $summary_parts[] = array(
                    'text' => __('Recipient: %s', 'guest-management-system'),
                    'value' => $recipient,
                );
            }

            $status = isset($log['delivery_status']) ? trim((string) $log['delivery_status']) : '';
            if ($status !== '') {
                $status_label = ucwords(str_replace('_', ' ', $status));
                $summary_parts[] = array(
                    'text' => __('Status: %s', 'guest-management-system'),
                    'value' => $status_label,
                );
            }

            $subject = isset($log['subject']) ? trim((string) $log['subject']) : '';
            $message = isset($log['message']) ? trim(wp_strip_all_tags((string) $log['message'])) : '';

            if ($subject !== '') {
                $summary_parts[] = array(
                    'text' => __('Subject: %s', 'guest-management-system'),
                    'value' => $subject,
                );
            } elseif ($message !== '') {
                $summary_parts[] = array(
                    'text' => __('Message: %s', 'guest-management-system'),
                    'value' => wp_trim_words($message, 16, '…'),
                );
            }

            $response_data = isset($log['response_data']) && is_array($log['response_data']) ? $log['response_data'] : array();
            if (isset($response_data['door_code'])) {
                $door_code = GMS_Database::sanitizeDoorCode($response_data['door_code']);
                if ($door_code !== '') {
                    $summary_parts[] = array(
                        'text' => __('Door code %s', 'guest-management-system'),
                        'value' => $door_code,
                    );
                }
            }

            if (isset($response_data['platform'])) {
                $platform_key = sanitize_key($response_data['platform']);
                $platform_label = '';

                if ($platform_key !== '' && function_exists('gms_get_platform_display')) {
                    $platform_lookup = str_replace('_', '.', $platform_key);
                    $platform_info = gms_get_platform_display($platform_lookup);
                    if (is_array($platform_info) && isset($platform_info['name'])) {
                        $platform_label = $platform_info['name'];
                    }
                }

                if ($platform_label === '' && $platform_key !== '') {
                    $platform_label = ucwords(str_replace('_', ' ', $platform_key));
                }

                if ($platform_label !== '') {
                    $summary_parts[] = array(
                        'text' => __('Platform: %s', 'guest-management-system'),
                        'value' => $platform_label,
                    );
                }
            }

            if (isset($response_data['thread_id'])) {
                $thread_id = trim((string) $response_data['thread_id']);
                if ($thread_id !== '') {
                    $summary_parts[] = array(
                        'text' => __('Thread: %s', 'guest-management-system'),
                        'value' => $thread_id,
                    );
                }
            }

            $provider_reference = isset($log['provider_reference']) ? trim((string) $log['provider_reference']) : '';
            if ($provider_reference !== '') {
                $summary_parts[] = array(
                    'text' => __('Reference: %s', 'guest-management-system'),
                    'value' => $provider_reference,
                );
            }

            echo '<li class="gms-reservation-timeline__item">';
            echo '<div class="gms-reservation-timeline__type">' . esc_html($type_label) . '</div>';
            if ($timestamp !== '') {
                echo '<div class="gms-reservation-timeline__timestamp">' . esc_html($timestamp) . '</div>';
            }

            if (!empty($summary_parts)) {
                $formatted_parts = array();

                foreach ($summary_parts as $part) {
                    if (!is_array($part) || !isset($part['text'])) {
                        continue;
                    }

                    $value = isset($part['value']) ? $part['value'] : '';
                    $formatted_parts[] = sprintf($part['text'], esc_html($value));
                }

                if (!empty($formatted_parts)) {
                    echo '<div class="gms-reservation-timeline__summary">' . implode(' <span class="gms-reservation-timeline__separator">&middot;</span> ', $formatted_parts) . '</div>';
                }
            }

            echo '</li>';
        }
        echo '</ul>';
    }

    private function describe_communication_type($entry) {
        $type = isset($entry['communication_type']) ? sanitize_key($entry['communication_type']) : '';
        $channel = isset($entry['channel']) ? sanitize_key($entry['channel']) : '';

        if ($type === 'sms' || $channel === 'sms') {
            return __('SMS', 'guest-management-system');
        }

        if ($type === 'portal_update' || $channel === 'portal') {
            return __('Portal Update', 'guest-management-system');
        }

        if ($type === 'whatsapp' || $channel === 'whatsapp') {
            return __('WhatsApp', 'guest-management-system');
        }

        $platform_channels = array(
            'airbnb' => __('Airbnb Message', 'guest-management-system'),
            'vrbo' => __('VRBO Message', 'guest-management-system'),
            'booking_com' => __('Booking.com Message', 'guest-management-system'),
            'bookingcom' => __('Booking.com Message', 'guest-management-system'),
        );

        if (isset($platform_channels[$channel])) {
            return $platform_channels[$channel];
        }

        if ($type === 'platform_message') {
            return __('OTA Message', 'guest-management-system');
        }

        return __('Email', 'guest-management-system');
    }

    protected function create_portal_card_from_request($reservation_id) {
        $title = isset($_POST['portal_card_title']) ? sanitize_text_field(wp_unslash($_POST['portal_card_title'])) : '';
        $body = isset($_POST['portal_card_body']) ? wp_kses_post(wp_unslash($_POST['portal_card_body'])) : '';
        $icon = isset($_POST['portal_card_icon']) ? sanitize_text_field(wp_unslash($_POST['portal_card_icon'])) : '';
        $cta_label = isset($_POST['portal_card_cta_label']) ? sanitize_text_field(wp_unslash($_POST['portal_card_cta_label'])) : '';
        $cta_url_raw = isset($_POST['portal_card_cta_url']) ? wp_unslash($_POST['portal_card_cta_url']) : '';
        $cta_url = $cta_url_raw !== '' ? esc_url_raw($cta_url_raw) : '';

        if ($title === '') {
            return array(
                'type' => 'error',
                'messages' => array(__('Please provide a headline for the card before publishing.', 'guest-management-system')),
            );
        }

        if (($cta_label !== '' && $cta_url_raw === '') || ($cta_label === '' && $cta_url_raw !== '')) {
            return array(
                'type' => 'error',
                'messages' => array(__('To add a button, include both the label and a valid link.', 'guest-management-system')),
            );
        }

        if ($cta_url_raw !== '' && $cta_url === '') {
            return array(
                'type' => 'error',
                'messages' => array(__('Please enter a valid URL that begins with http:// or https://.', 'guest-management-system')),
            );
        }

        $result = GMS_Database::add_portal_card($reservation_id, array(
            'title' => $title,
            'body' => $body,
            'icon' => $icon,
            'cta_label' => $cta_label,
            'cta_url' => $cta_url,
        ));

        if (is_wp_error($result)) {
            return array(
                'type' => 'error',
                'messages' => array($result->get_error_message()),
            );
        }

        return array(
            'type' => 'success',
            'messages' => array(__('Promotional card published to the guest portal.', 'guest-management-system')),
        );
    }

    protected function delete_portal_card_from_request($reservation_id) {
        $card_id = isset($_POST['portal_card_id']) ? absint(wp_unslash($_POST['portal_card_id'])) : 0;

        if ($card_id <= 0) {
            return array(
                'type' => 'error',
                'messages' => array(__('Unable to determine which card to remove.', 'guest-management-system')),
            );
        }

        $deleted = GMS_Database::delete_portal_card($card_id, $reservation_id);

        if (!$deleted) {
            return array(
                'type' => 'error',
                'messages' => array(__('We could not remove the card. Please try again.', 'guest-management-system')),
            );
        }

        return array(
            'type' => 'success',
            'messages' => array(__('Card removed from the guest portal.', 'guest-management-system')),
        );
    }

    private function map_feedback_class($type) {
        switch ($type) {
            case 'success':
                return 'is-success';
            case 'warning':
                return 'is-warning';
            case 'error':
                return 'is-error';
            default:
                return '';
        }
    }

    private function resolve_feedback_type($successes, $attempts) {
        if ($successes <= 0) {
            return 'error';
        }

        if ($successes >= $attempts) {
            return 'success';
        }

        return 'warning';
    }

    private function trigger_portal_link_delivery($reservation_id) {
        $reservation = GMS_Database::getReservationById($reservation_id);

        if (!$reservation) {
            return array(
                'type' => 'error',
                'messages' => array(__('Unable to load reservation details. Please try again.', 'guest-management-system')),
            );
        }

        $messages = array();
        $successes = 0;
        $attempts = 0;

        $guest_email = isset($reservation['guest_email']) ? sanitize_email($reservation['guest_email']) : '';
        if ($guest_email !== '' && is_email($guest_email)) {
            $attempts++;
            $email_handler = new GMS_Email_Handler();
            $email_sent = $email_handler->sendReservationApprovedEmail($reservation);
            if ($email_sent) {
                $successes++;
                $messages[] = __('Portal invitation email sent successfully.', 'guest-management-system');
            } else {
                $messages[] = __('Portal invitation email failed to send.', 'guest-management-system');
            }
        } else {
            $messages[] = __('Portal email skipped—add a valid guest email to send automatically.', 'guest-management-system');
        }

        $guest_phone = isset($reservation['guest_phone']) ? $reservation['guest_phone'] : '';
        if ($guest_phone !== '') {
            $attempts++;
            $sms_handler = new GMS_SMS_Handler();
            $sms_sent = $sms_handler->sendReservationApprovedSMS($reservation);
            if ($sms_sent) {
                $successes++;
                $messages[] = __('Portal SMS sent successfully.', 'guest-management-system');
            } else {
                $messages[] = __('Portal SMS failed to send.', 'guest-management-system');
            }
        } else {
            $messages[] = __('Portal SMS skipped—add a guest phone number to enable SMS delivery.', 'guest-management-system');
        }

        if (class_exists('GMS_OTA_Messaging_Handler')) {
            $ota_handler = new GMS_OTA_Messaging_Handler();
            $ota_result = $ota_handler->sendPortalInvitation($reservation);

            if (!empty($ota_result['message'])) {
                $messages[] = $ota_result['message'];
            }

            if (isset($ota_result['status']) && $ota_result['status'] !== 'skipped') {
                $attempts++;
                if (!empty($ota_result['success'])) {
                    $successes++;
                }
            }
        }

        $type = $this->resolve_feedback_type($successes, max($attempts, 1));

        return array(
            'type' => $type,
            'messages' => $messages,
        );
    }

    private function trigger_door_code_delivery($reservation_id) {
        $reservation = GMS_Database::getReservationById($reservation_id);

        if (!$reservation) {
            return array(
                'type' => 'error',
                'messages' => array(__('Unable to load reservation details. Please try again.', 'guest-management-system')),
            );
        }

        $door_code = GMS_Database::sanitizeDoorCode($reservation['door_code'] ?? '');
        if ($door_code === '') {
            return array(
                'type' => 'error',
                'messages' => array(__('Add a 4-digit door code before sending the access package.', 'guest-management-system')),
            );
        }

        $messages = array();
        $successes = 0;
        $attempts = 0;

        $guest_email = isset($reservation['guest_email']) ? sanitize_email($reservation['guest_email']) : '';
        if ($guest_email !== '' && is_email($guest_email)) {
            $attempts++;
            $email_handler = new GMS_Email_Handler();
            $email_sent = $email_handler->sendDoorCodeEmail($reservation, $door_code);
            if ($email_sent) {
                $successes++;
                $messages[] = __('Door code email sent successfully.', 'guest-management-system');
            } else {
                $messages[] = __('Door code email failed to send.', 'guest-management-system');
            }
        } else {
            $messages[] = __('Door code email skipped—add a valid guest email to enable delivery.', 'guest-management-system');
        }

        $guest_phone = isset($reservation['guest_phone']) ? $reservation['guest_phone'] : '';
        if ($guest_phone !== '') {
            $attempts++;
            $sms_handler = new GMS_SMS_Handler();
            $sms_sent = $sms_handler->sendDoorCodeSMS($reservation, $door_code);
            if ($sms_sent) {
                $successes++;
                $messages[] = __('Door code SMS sent successfully.', 'guest-management-system');
            } else {
                $messages[] = __('Door code SMS failed to send.', 'guest-management-system');
            }
        } else {
            $messages[] = __('Door code SMS skipped—add a guest phone number to enable SMS delivery.', 'guest-management-system');
        }

        if (class_exists('GMS_OTA_Messaging_Handler')) {
            $ota_handler = new GMS_OTA_Messaging_Handler();
            $ota_result = $ota_handler->sendDoorCodeMessage($reservation, $door_code);

            if (!empty($ota_result['message'])) {
                $messages[] = $ota_result['message'];
            }

            if (isset($ota_result['status']) && $ota_result['status'] !== 'skipped') {
                $attempts++;
                if (!empty($ota_result['success'])) {
                    $successes++;
                }
            }
        }

        $attempts++;
        $portal_logged = $this->log_portal_door_code_update($reservation_id, intval($reservation['guest_id'] ?? 0), $door_code);
        if ($portal_logged) {
            $successes++;
            $messages[] = __('Guest portal updated with the latest door code.', 'guest-management-system');
        } else {
            $messages[] = __('Unable to record a portal update. Check the activity log.', 'guest-management-system');
        }

        $type = $this->resolve_feedback_type($successes, $attempts);

        return array(
            'type' => $type,
            'messages' => $messages,
        );
    }

    private function trigger_welcome_delivery($reservation_id) {
        $reservation = GMS_Database::getReservationById($reservation_id);

        if (!$reservation) {
            return array(
                'type' => 'error',
                'messages' => array(__('Unable to load reservation details. Please try again.', 'guest-management-system')),
            );
        }

        $messages = array();
        $successes = 0;
        $attempts = 0;

        $guest_email = isset($reservation['guest_email']) ? sanitize_email($reservation['guest_email']) : '';
        if ($guest_email !== '' && is_email($guest_email)) {
            $attempts++;
            $email_handler = new GMS_Email_Handler();
            $email_sent = $email_handler->sendWelcomeEmail($reservation);
            if ($email_sent) {
                $successes++;
                $messages[] = __('Welcome email sent successfully.', 'guest-management-system');
            } else {
                $messages[] = __('Welcome email failed to send.', 'guest-management-system');
            }
        } else {
            $messages[] = __('Welcome email skipped—add a valid guest email to enable delivery.', 'guest-management-system');
        }

        $guest_phone = isset($reservation['guest_phone']) ? $reservation['guest_phone'] : '';
        if ($guest_phone !== '') {
            $attempts++;
            $sms_handler = new GMS_SMS_Handler();
            $sms_sent = $sms_handler->sendWelcomeSMS($reservation);
            if ($sms_sent) {
                $successes++;
                $messages[] = __('Welcome SMS sent successfully.', 'guest-management-system');
            } else {
                $messages[] = __('Welcome SMS failed to send.', 'guest-management-system');
            }
        } else {
            $messages[] = __('Welcome SMS skipped—add a guest phone number to enable SMS delivery.', 'guest-management-system');
        }

        if (class_exists('GMS_OTA_Messaging_Handler')) {
            $ota_handler = new GMS_OTA_Messaging_Handler();
            $ota_result = $ota_handler->sendWelcomeMessage($reservation);

            if (!empty($ota_result['message'])) {
                $messages[] = $ota_result['message'];
            }

            if (isset($ota_result['status']) && $ota_result['status'] !== 'skipped') {
                $attempts++;
                if (!empty($ota_result['success'])) {
                    $successes++;
                }
            }
        }

        $type = $this->resolve_feedback_type($successes, max($attempts, 1));

        return array(
            'type' => $type,
            'messages' => $messages,
        );
    }

    private function log_portal_door_code_update($reservation_id, $guest_id, $door_code) {
        $sanitized_code = GMS_Database::sanitizeDoorCode($door_code);

        if ($sanitized_code === '') {
            return 0;
        }

        return GMS_Database::logCommunication(array(
            'reservation_id' => intval($reservation_id),
            'guest_id' => intval($guest_id),
            'type' => 'portal_update',
            'channel' => 'portal',
            'message' => sprintf(__('Door code %s synced to the guest portal.', 'guest-management-system'), $sanitized_code),
            'status' => 'completed',
            'response_data' => array(
                'context' => 'door_code_sequence',
                'door_code' => $sanitized_code,
            ),
            'sent_at' => current_time('mysql'),
        ));
    }

    private function handle_platform_sync_request() {
        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('gms_sync_platform_reservations');

        $platform = isset($_POST['gms_sync_platform']) ? sanitize_key(wp_unslash($_POST['gms_sync_platform'])) : 'all';
        $since_raw = isset($_POST['gms_sync_since']) ? wp_unslash($_POST['gms_sync_since']) : '';
        $limit = isset($_POST['gms_sync_limit']) ? absint(wp_unslash($_POST['gms_sync_limit'])) : 0;

        $args = array();

        if ($since_raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since_raw)) {
            $args['since'] = $since_raw;
        }

        if ($limit > 0) {
            $args['limit'] = $limit;
        }

        if (!class_exists('GMS_OTA_Reservation_Sync')) {
            $result = array(
                'success' => false,
                'created' => 0,
                'updated' => 0,
                'synced' => 0,
                'skipped' => 0,
                'errors' => array(__('OTA reservation sync handler is not available.', 'guest-management-system')),
                'messages' => array(),
            );
        } else {
            $handler = new GMS_OTA_Reservation_Sync();
            if ($platform === '' || $platform === 'all') {
                $result = $handler->import_all_platforms($args);
                $platform = 'all';
            } else {
                $result = $handler->import_platform_reservations($platform, $args);
            }
        }

        set_transient($this->get_user_notice_key('platform_sync'), array(
            'platform' => $platform,
            'result' => $result,
        ), MINUTE_IN_SECONDS * 10);

        $redirect_url = add_query_arg(array('page' => 'guest-management-reservations'), admin_url('admin.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    private function consume_platform_sync_notice() {
        $notice_key = $this->get_user_notice_key('platform_sync');
        $notice = get_transient($notice_key);

        if ($notice !== false) {
            delete_transient($notice_key);
        }

        return $notice;
    }

    private function render_platform_sync_notice($notice) {
        if (!is_array($notice)) {
            return;
        }

        $result = isset($notice['result']) && is_array($notice['result']) ? $notice['result'] : array();
        $platform = $this->normalize_platform_key($notice['platform'] ?? '');

        $label = $platform === 'all' || $platform === ''
            ? __('All Platforms', 'guest-management-system')
            : $this->describe_platform_label($platform);

        if ($label === '') {
            $label = __('Selected Platform', 'guest-management-system');
        }

        $created = intval($result['created'] ?? 0);
        $updated = intval($result['updated'] ?? 0);
        $synced = intval($result['synced'] ?? 0);
        $skipped = intval($result['skipped'] ?? 0);
        $errors = isset($result['errors']) ? array_filter(array_map('trim', (array) $result['errors'])) : array();
        $messages = isset($result['messages']) ? array_filter(array_map('trim', (array) $result['messages'])) : array();

        $success = !empty($result['success']);
        $notice_class = $success ? 'notice-success' : 'notice-warning';

        echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible">';
        echo '<p>';

        if ($success) {
            printf(
                esc_html__('Synced reservations from %1$s. %2$d created, %3$d updated, %4$d already current.', 'guest-management-system'),
                esc_html($label),
                $created,
                $updated,
                $synced
            );

            if ($skipped > 0) {
                echo ' ';
                printf(
                    esc_html(_n('%d record skipped.', '%d records skipped.', $skipped, 'guest-management-system')),
                    $skipped
                );
            }
        } else {
            if (!empty($errors)) {
                esc_html_e('Unable to complete the platform sync.', 'guest-management-system');
            } else {
                printf(
                    esc_html__('No reservations were returned from %s.', 'guest-management-system'),
                    esc_html($label)
                );
            }
        }

        echo '</p>';

        if (!empty($messages)) {
            echo '<ul class="gms-platform-sync__notice-list">';
            foreach ($messages as $message) {
                echo '<li>' . esc_html($message) . '</li>';
            }
            echo '</ul>';
        }

        if (!empty($errors)) {
            echo '<ul class="gms-platform-sync__notice-errors">';
            foreach ($errors as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul>';
        }

        echo '</div>';
    }

    private function get_user_notice_key($suffix) {
        $suffix = sanitize_key($suffix);
        $user_id = get_current_user_id();

        if (!$user_id) {
            $user_id = 0;
        }

        return sprintf('gms_%s_notice_%d', $suffix, $user_id);
    }

    private function normalize_platform_key($platform) {
        $platform = strtolower(trim((string) $platform));

        if ($platform === '') {
            return '';
        }

        $platform = str_replace(array('.', ' ', '-'), '_', $platform);

        if ($platform === 'bookingcom') {
            $platform = 'booking_com';
        }

        return $platform;
    }

    private function describe_platform_label($platform_key) {
        $platform_key = $this->normalize_platform_key($platform_key);

        if ($platform_key === '') {
            return '';
        }

        if (class_exists('GMS_OTA_Reservation_Sync')) {
            $sync = new GMS_OTA_Reservation_Sync();
            $config = $sync->get_platform_config();
        } else {
            $config = apply_filters('gms_ota_reservation_config', array());
        }

        if (isset($config[$platform_key]['label'])) {
            return wp_strip_all_tags((string) $config[$platform_key]['label']);
        }

        return ucwords(str_replace('_', ' ', $platform_key));
    }

    private function platform_credentials_ready($platform_key) {
        $platform_key = $this->normalize_platform_key($platform_key);

        if ($platform_key === '') {
            return false;
        }

        if (class_exists('GMS_OTA_Reservation_Sync')) {
            $sync = new GMS_OTA_Reservation_Sync();
            $config = $sync->get_platform_config();
        } else {
            $config = apply_filters('gms_ota_reservation_config', array());
        }

        if (!isset($config[$platform_key]['option'])) {
            return false;
        }

        $token = trim((string) get_option($config[$platform_key]['option'], ''));

        return $token !== '';
    }

    private function extract_platform_sync_snapshot($reservation, $platform_key) {
        $platform_key = $this->normalize_platform_key($platform_key);
        $webhook = isset($reservation['webhook_data']) && is_array($reservation['webhook_data']) ? $reservation['webhook_data'] : array();
        $sync_data = array();

        if (isset($webhook['ota_sync']) && is_array($webhook['ota_sync']) && isset($webhook['ota_sync'][$platform_key]) && is_array($webhook['ota_sync'][$platform_key])) {
            $sync_data = $webhook['ota_sync'][$platform_key];
        }

        $snapshot = isset($sync_data['snapshot']) && is_array($sync_data['snapshot']) ? $sync_data['snapshot'] : array();
        $synced_fields = isset($sync_data['synced_fields']) ? array_filter(array_map('sanitize_key', (array) $sync_data['synced_fields'])) : array();
        $last_synced = isset($sync_data['last_synced']) ? trim((string) $sync_data['last_synced']) : '';

        return array(
            'snapshot' => $snapshot,
            'synced_fields' => $synced_fields,
            'last_synced' => $last_synced,
        );
    }

    private function refresh_reservation_from_platform($reservation) {
        if (!is_array($reservation) || empty($reservation)) {
            return array(
                'type' => 'error',
                'messages' => array(__('Unable to load reservation details. Please try again.', 'guest-management-system')),
            );
        }

        $platform_key = $this->normalize_platform_key($reservation['platform'] ?? '');
        if ($platform_key === '') {
            return array(
                'type' => 'error',
                'messages' => array(__('Set the reservation platform before refreshing.', 'guest-management-system')),
            );
        }

        $booking_reference = sanitize_text_field($reservation['booking_reference'] ?? '');
        if ($booking_reference === '') {
            return array(
                'type' => 'error',
                'messages' => array(__('Add the platform booking reference before refreshing.', 'guest-management-system')),
            );
        }

        if (!$this->platform_credentials_ready($platform_key)) {
            return array(
                'type' => 'error',
                'messages' => array(__('Add the platform credentials in settings to sync reservation details.', 'guest-management-system')),
            );
        }

        if (!class_exists('GMS_OTA_Reservation_Sync')) {
            return array(
                'type' => 'error',
                'messages' => array(__('OTA reservation sync handler is not available.', 'guest-management-system')),
            );
        }

        $handler = new GMS_OTA_Reservation_Sync();
        $result = $handler->sync_reservation($reservation);

        $messages = array();

        if (!empty($result['message'])) {
            $messages[] = $result['message'];
        }

        if (!empty($result['errors'])) {
            $messages = array_merge($messages, array_filter(array_map('trim', (array) $result['errors'])));
        }

        if (empty($messages)) {
            if (!empty($result['success'])) {
                $messages[] = __('Reservation is already up to date with the platform.', 'guest-management-system');
            } else {
                $messages[] = __('Platform sync failed. Review the connection settings and try again.', 'guest-management-system');
            }
        }

        $type = !empty($result['success']) ? 'success' : 'error';

        return array(
            'type' => $type,
            'messages' => $messages,
        );
    }

    protected function render_missing_reservation_notice() {
        $cancel_url = add_query_arg(array('page' => 'guest-management-reservations'), admin_url('admin.php'));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Edit Reservation', 'guest-management-system'); ?></h1>
            <a href="<?php echo esc_url($cancel_url); ?>" class="page-title-action"><?php esc_html_e('Back to Reservations', 'guest-management-system'); ?></a>
            <hr class="wp-header-end">

            <div class="notice notice-error">
                <p><?php esc_html_e('The requested reservation could not be found.', 'guest-management-system'); ?></p>
            </div>
        </div>
        <?php
    }

    protected function format_datetime_for_input($value) {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d\TH:i', $timestamp);
    }

    protected function format_datetime_for_database($value) {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    public function render_guests_page() {
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';

        if ($action === 'view') {
            $guest_id = isset($_GET['guest_id']) ? absint(wp_unslash($_GET['guest_id'])) : 0;
            $this->render_guest_profile_page($guest_id);
            return;
        }

        if ($action === 'edit') {
            $guest_id = isset($_GET['guest_id']) ? absint(wp_unslash($_GET['guest_id'])) : 0;
            $this->render_guest_edit_page($guest_id);
            return;
        }

        $guests_table = new GMS_Guests_List_Table();
        $guests_table->prepare_items();

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Guest Profiles', 'guest-management-system'); ?></h1>
            <hr class="wp-header-end">
            <p><?php esc_html_e('Review guest profiles, confirm identity documentation, and keep sensitive guest information separated and secure.', 'guest-management-system'); ?></p>

            <?php if (isset($_GET['gms_guest_updated'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Guest updated successfully.', 'guest-management-system'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['gms_guest_deleted'])) :
                $deleted = max(1, absint($_GET['gms_guest_deleted']));
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php printf(
                            esc_html(_n('%d guest deleted.', '%d guests deleted.', $deleted, 'guest-management-system')),
                            $deleted
                        ); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['gms_guest_delete_error'])) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e('Unable to delete the guest. Please try again.', 'guest-management-system'); ?></p>
                </div>
            <?php endif; ?>

            <form method="get">
                <input type="hidden" name="page" value="guest-management-guests" />
                <?php $guests_table->search_box(__('Search Guest Profiles', 'guest-management-system'), 'gms-guests'); ?>
                <?php $guests_table->display(); ?>
            </form>
        </div>
        <?php
    }

    protected function render_guest_edit_page($guest_id) {
        $list_url = add_query_arg(['page' => 'guest-management-guests'], admin_url('admin.php'));

        if ($guest_id <= 0) {
            ?>
            <div class="wrap">
                <h1 class="wp-heading-inline"><?php esc_html_e('Edit Guest', 'guest-management-system'); ?></h1>
                <a href="<?php echo esc_url($list_url); ?>" class="page-title-action"><?php esc_html_e('Back to Guest Profiles', 'guest-management-system'); ?></a>
                <hr class="wp-header-end">
                <div class="notice notice-error"><p><?php esc_html_e('The requested guest could not be found.', 'guest-management-system'); ?></p></div>
            </div>
            <?php
            return;
        }

        $guest = GMS_Database::get_guest_by_id($guest_id);

        if (!$guest) {
            ?>
            <div class="wrap">
                <h1 class="wp-heading-inline"><?php esc_html_e('Edit Guest', 'guest-management-system'); ?></h1>
                <a href="<?php echo esc_url($list_url); ?>" class="page-title-action"><?php esc_html_e('Back to Guest Profiles', 'guest-management-system'); ?></a>
                <hr class="wp-header-end">
                <div class="notice notice-error"><p><?php esc_html_e('The requested guest could not be found.', 'guest-management-system'); ?></p></div>
            </div>
            <?php
            return;
        }

        $form_values = [
            'first_name' => $guest['first_name'] ?? '',
            'last_name' => $guest['last_name'] ?? '',
            'email' => $guest['email'] ?? '',
            'phone' => $guest['phone'] ?? '',
        ];

        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('gms_update_guest');

            $form_values['first_name'] = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
            $form_values['last_name'] = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
            $form_values['email'] = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
            $form_values['phone'] = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';

            if ($form_values['email'] !== '' && !is_email($form_values['email'])) {
                $errors[] = __('Please enter a valid email address.', 'guest-management-system');
            }

            if (empty($errors)) {
                $updated = GMS_Database::update_guest($guest_id, [
                    'first_name' => $form_values['first_name'],
                    'last_name' => $form_values['last_name'],
                    'email' => $form_values['email'],
                    'phone' => $form_values['phone'],
                ]);

                if ($updated) {
                    $redirect_url = add_query_arg('gms_guest_updated', 1, $list_url);
                    wp_safe_redirect($redirect_url);
                    exit;
                }

                $errors[] = __('Unable to update the guest record. Please try again.', 'guest-management-system');
            }
        }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Edit Guest', 'guest-management-system'); ?></h1>
            <a href="<?php echo esc_url($list_url); ?>" class="page-title-action"><?php esc_html_e('Back to Guest Profiles', 'guest-management-system'); ?></a>
            <hr class="wp-header-end">

            <?php if (!empty($errors)) : ?>
                <div class="notice notice-error">
                    <ul>
                        <?php foreach ($errors as $error) : ?>
                            <li><?php echo esc_html($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('gms_update_guest'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="gms_guest_first_name"><?php esc_html_e('First Name', 'guest-management-system'); ?></label></th>
                            <td><input name="first_name" type="text" id="gms_guest_first_name" value="<?php echo esc_attr($form_values['first_name']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_guest_last_name"><?php esc_html_e('Last Name', 'guest-management-system'); ?></label></th>
                            <td><input name="last_name" type="text" id="gms_guest_last_name" value="<?php echo esc_attr($form_values['last_name']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_guest_email"><?php esc_html_e('Email', 'guest-management-system'); ?></label></th>
                            <td><input name="email" type="email" id="gms_guest_email" value="<?php echo esc_attr($form_values['email']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gms_guest_phone"><?php esc_html_e('Phone', 'guest-management-system'); ?></label></th>
                            <td><input name="phone" type="text" id="gms_guest_phone" value="<?php echo esc_attr($form_values['phone']); ?>" class="regular-text"></td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Update Guest', 'guest-management-system')); ?>
                <a class="button button-secondary" href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('Cancel', 'guest-management-system'); ?></a>
            </form>
        </div>
        <?php
    }

    protected function format_guest_phone_display($phone) {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return '';
        }

        if (function_exists('gms_format_phone')) {
            $formatted = gms_format_phone($phone);
            if (!empty($formatted)) {
                return $formatted;
            }
        }

        return $phone;
    }

    private function build_verification_media_preview($relative_path, $mime_type, $file_id = '') {
        $relative_path = (string) $relative_path;
        $file_id = sanitize_text_field($file_id);

        if (strpos($relative_path, 'stripe-file:') === 0) {
            $file_id = sanitize_text_field(substr($relative_path, strlen('stripe-file:')));
            $relative_path = '';
        }

        $mime = sanitize_mime_type($mime_type);

        if ($relative_path !== '') {
            $trimmed_path = ltrim($relative_path, '/');

            $upload_dir = wp_upload_dir();
            if (!empty($upload_dir['error'])) {
                return null;
            }

            $base_dir = wp_normalize_path(trailingslashit($upload_dir['basedir']));
            $full_path = wp_normalize_path($base_dir . $trimmed_path);

            if (!str_starts_with($full_path, $base_dir)) {
                return null;
            }

            if (!file_exists($full_path) || !is_file($full_path)) {
                return null;
            }

            if ($mime === '') {
                $filetype = wp_check_filetype($full_path);
                if (!empty($filetype['type'])) {
                    $mime = sanitize_mime_type($filetype['type']);
                }
            }

            $contents = @file_get_contents($full_path);
            if ($contents === false) {
                return null;
            }

            if ($mime === '' || strpos($mime, 'image/') !== 0) {
                return array(
                    'type' => $mime !== '' ? $mime : 'application/octet-stream',
                    'data_uri' => '',
                );
            }

            $data_uri = 'data:' . $mime . ';base64,' . base64_encode($contents);

            return array(
                'type' => $mime,
                'data_uri' => $data_uri,
            );
        }

        if ($file_id === '') {
            return null;
        }

        $view_url = $this->build_verification_asset_view_url($file_id);

        if ($view_url === '') {
            return null;
        }

        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        return array(
            'type' => $mime,
            'data_uri' => '',
            'view_url' => $view_url,
        );
    }

    private function build_verification_asset_view_url($file_id) {
        $file_id = sanitize_text_field($file_id);

        if ($file_id === '') {
            return '';
        }

        $nonce = wp_create_nonce('gms_view_verification_asset_' . $file_id);

        return add_query_arg(
            array(
                'action' => 'gms_view_verification_asset',
                'file' => $file_id,
                '_wpnonce' => $nonce,
                'context' => 'modal',
            ),
            admin_url('admin-post.php')
        );
    }

    public function render_communications_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap gms-messaging-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Messaging Inbox', 'guest-management-system'); ?></h1>
            <hr class="wp-header-end">
            <p class="description"><?php esc_html_e('Review guest conversations, reply in real time, and keep your team in sync across channels.', 'guest-management-system'); ?></p>

            <div id="gms-messaging-app" class="gms-messaging-app" data-loading-text="<?php echo esc_attr__('Loading conversations…', 'guest-management-system'); ?>">
                <div class="gms-messaging" aria-live="polite">
                    <aside class="gms-messaging__sidebar" aria-label="<?php esc_attr_e('Conversation list', 'guest-management-system'); ?>">
                        <form class="gms-messaging__search" role="search">
                            <label class="screen-reader-text" for="gms-messaging-search"><?php esc_html_e('Search conversations', 'guest-management-system'); ?></label>
                            <input id="gms-messaging-search" type="search" class="gms-messaging__search-input" placeholder="<?php echo esc_attr__('Search guests, properties, or numbers…', 'guest-management-system'); ?>">
                            <button type="submit" class="button gms-messaging__search-button"><?php esc_html_e('Search', 'guest-management-system'); ?></button>
                        </form>

                        <nav class="gms-messaging__channels" aria-label="<?php esc_attr_e('Message channels', 'guest-management-system'); ?>"></nav>

                        <div class="gms-messaging__threads" role="list" aria-live="polite"></div>

                        <div class="gms-messaging__pagination">
                            <button type="button" class="button gms-messaging__pagination-button" data-direction="prev" aria-label="<?php esc_attr_e('Previous conversations', 'guest-management-system'); ?>">‹</button>
                            <span class="gms-messaging__pagination-status" aria-live="polite"></span>
                            <button type="button" class="button gms-messaging__pagination-button" data-direction="next" aria-label="<?php esc_attr_e('Next conversations', 'guest-management-system'); ?>">›</button>
                        </div>
                    </aside>

                    <section class="gms-messaging__conversation gms-messaging__panel" aria-label="<?php esc_attr_e('Conversation detail', 'guest-management-system'); ?>">
                        <header class="gms-messaging__conversation-header gms-messaging__panel-header">
                            <div class="gms-messaging__conversation-titles gms-messaging__panel-titles">
                                <h2 class="gms-messaging__thread-title"></h2>
                                <p class="gms-messaging__thread-subtitle"></p>
                            </div>
                            <button type="button" class="button button-secondary gms-messaging__mark-read" disabled><?php esc_html_e('Mark all as read', 'guest-management-system'); ?></button>
                        </header>

                        <div class="gms-messaging__conversation-meta gms-messaging__thread-meta"></div>

                        <div class="gms-messaging__conversation-scroll gms-messaging__messages-wrapper">
                            <div class="gms-messaging__messages" role="log" aria-live="polite"></div>
                        </div>

                        <form class="gms-messaging__composer" novalidate>
                            <div class="gms-messaging__composer-row gms-messaging__template-row">
                                <label class="screen-reader-text" for="gms-template-search"><?php esc_html_e('Search templates', 'guest-management-system'); ?></label>
                                <input id="gms-template-search" type="search" class="gms-messaging__template-search" placeholder="<?php echo esc_attr__('Search templates…', 'guest-management-system'); ?>">

                                <label class="screen-reader-text" for="gms-template-select"><?php esc_html_e('Choose a template', 'guest-management-system'); ?></label>
                                <select id="gms-template-select" class="gms-messaging__template" aria-label="<?php esc_attr_e('Templates', 'guest-management-system'); ?>">
                                    <option value=""><?php esc_html_e('Insert a template…', 'guest-management-system'); ?></option>
                                </select>
                            </div>

                            <label class="screen-reader-text" for="gms-message-input"><?php esc_html_e('Message body', 'guest-management-system'); ?></label>
                            <textarea id="gms-message-input" class="gms-messaging__input" rows="4" placeholder="<?php echo esc_attr__('Type your reply…', 'guest-management-system'); ?>"></textarea>

                            <div class="gms-messaging__composer-footer">
                                <span class="gms-messaging__status" aria-live="polite"></span>
                                <button type="submit" class="button button-primary gms-messaging__send" disabled><?php esc_html_e('Send Reply', 'guest-management-system'); ?></button>
                            </div>
                        </form>
                    </section>
                </div>

                <div class="gms-messaging-app__placeholder">
                    <span class="spinner is-active" aria-hidden="true"></span>
                    <p><?php esc_html_e('Loading conversations…', 'guest-management-system'); ?></p>
                    <noscript>
                        <p><?php esc_html_e('Enable JavaScript to use the messaging inbox.', 'guest-management-system'); ?></p>
                    </noscript>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_logs_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap gms-messaging-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Operational Logs', 'guest-management-system'); ?></h1>
            <hr class="wp-header-end">
            <p class="description"><?php esc_html_e('Audit automations, deliveries, and system activity without leaving the dashboard.', 'guest-management-system'); ?></p>

            <div id="gms-logs-app" class="gms-logs-app" data-loading-text="<?php echo esc_attr__('Loading logs…', 'guest-management-system'); ?>">
                <div class="gms-logs" aria-live="polite">
                    <form class="gms-logs__search" role="search">
                        <label class="screen-reader-text" for="gms-logs-search"><?php esc_html_e('Search logs', 'guest-management-system'); ?></label>
                        <input id="gms-logs-search" type="search" class="gms-logs__search-input" placeholder="<?php echo esc_attr__('Search logs…', 'guest-management-system'); ?>">
                        <button type="submit" class="button gms-logs__search-button"><?php esc_html_e('Search Logs', 'guest-management-system'); ?></button>
                    </form>

                    <div class="gms-logs__layout">
                        <aside class="gms-logs__list" aria-label="<?php esc_attr_e('Log entries', 'guest-management-system'); ?>" role="list"></aside>

                        <section class="gms-logs__detail" aria-label="<?php esc_attr_e('Log details', 'guest-management-system'); ?>">
                            <div class="gms-logs__detail-body"></div>
                        </section>
                    </div>

                    <div class="gms-logs__pagination">
                        <button type="button" class="button gms-logs__pagination-button" data-direction="prev" aria-label="<?php esc_attr_e('Previous logs', 'guest-management-system'); ?>">‹</button>
                        <span class="gms-logs__pagination-status" aria-live="polite"></span>
                        <button type="button" class="button gms-logs__pagination-button" data-direction="next" aria-label="<?php esc_attr_e('Next logs', 'guest-management-system'); ?>">›</button>
                    </div>
                </div>

                <div class="gms-messaging-app__placeholder">
                    <span class="spinner is-active" aria-hidden="true"></span>
                    <p><?php esc_html_e('Loading logs…', 'guest-management-system'); ?></p>
                    <noscript>
                        <p><?php esc_html_e('Enable JavaScript to review operational logs.', 'guest-management-system'); ?></p>
                    </noscript>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_templates_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['settings-updated'])) {
            add_settings_error('gms_settings_messages', 'gms_settings_updated', __('Settings saved.', 'guest-management-system'), 'updated');
        }

        $templates_table = new GMS_Message_Templates_List_Table();
        $templates_table->prepare_items();

        $editing_template = null;
        $editing_id = 0;

        if (isset($_GET['action']) && sanitize_key($_GET['action']) === 'edit_template') {
            $editing_id = isset($_GET['template_id']) ? absint($_GET['template_id']) : 0;

            if ($editing_id > 0) {
                $editing_template = GMS_Database::getMessageTemplateById($editing_id);

                if (!$editing_template) {
                    add_settings_error('gms_quick_templates', 'gms_template_missing', __('The selected template could not be found.', 'guest-management-system'), 'error');
                }
            }
        }

        if (isset($_GET['gms_template_notice'])) {
            $notice_key = sanitize_key($_GET['gms_template_notice']);
            $message_param = isset($_GET['gms_template_message']) ? wp_unslash($_GET['gms_template_message']) : '';
            $message_param = $message_param !== '' ? rawurldecode($message_param) : '';
            $message_param = sanitize_text_field($message_param);

            if ($message_param === '') {
                switch ($notice_key) {
                    case 'created':
                        $message_param = __('Template created successfully.', 'guest-management-system');
                        break;
                    case 'updated':
                        $message_param = __('Template updated successfully.', 'guest-management-system');
                        break;
                    case 'deleted':
                        $message_param = __('Template deleted successfully.', 'guest-management-system');
                        break;
                    case 'error':
                        $message_param = __('There was a problem saving the template.', 'guest-management-system');
                        break;
                }
            }

            $notice_type = $notice_key === 'error' ? 'error' : 'updated';

            if ($message_param !== '') {
                add_settings_error('gms_quick_templates', 'gms_quick_templates_notice', $message_param, $notice_type);
            }
        }

        $base_templates_url = admin_url('admin.php?page=guest-management-templates');

        ?>
        <div class="wrap gms-settings">
            <h1 class="wp-heading-inline"><?php esc_html_e('Templates', 'guest-management-system'); ?></h1>
            <hr class="wp-header-end">
            <p><?php esc_html_e('Customize notification and agreement templates used throughout the guest journey.', 'guest-management-system'); ?></p>

            <?php settings_errors('gms_settings_messages'); ?>

            <form action="options.php" method="post">
                <?php
                settings_fields('gms_settings_templates');
                do_settings_sections('gms_settings_templates');
                submit_button();
                ?>
            </form>

            <hr class="gms-settings__divider">

            <h2><?php esc_html_e('Quick Reply Templates', 'guest-management-system'); ?></h2>
            <p><?php esc_html_e('Create reusable SMS and WhatsApp snippets that your team can quickly insert into conversations.', 'guest-management-system'); ?></p>

            <?php settings_errors('gms_quick_templates'); ?>

            <div class="gms-quick-templates">
                <div class="gms-quick-templates__list">
                    <form method="get">
                        <input type="hidden" name="page" value="guest-management-templates" />
                        <?php $templates_table->search_box(__('Search templates', 'guest-management-system'), 'gms-message-templates'); ?>
                        <?php $templates_table->display(); ?>
                    </form>
                </div>
                <div class="gms-quick-templates__form">
                    <h3><?php echo $editing_template ? esc_html__('Edit Template', 'guest-management-system') : esc_html__('Add New Template', 'guest-management-system'); ?></h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('gms_save_message_template'); ?>
                        <input type="hidden" name="action" value="gms_save_message_template">
                        <?php if ($editing_template) : ?>
                            <input type="hidden" name="template_id" value="<?php echo esc_attr($editing_template['id']); ?>">
                        <?php endif; ?>

                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="gms-template-label"><?php esc_html_e('Label', 'guest-management-system'); ?></label></th>
                                    <td><input name="label" type="text" id="gms-template-label" value="<?php echo esc_attr($editing_template['label'] ?? ''); ?>" class="regular-text" required></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="gms-template-channel"><?php esc_html_e('Channel', 'guest-management-system'); ?></label></th>
                                    <td>
                                        <?php $channel_value = sanitize_key($editing_template['channel'] ?? 'sms'); ?>
                                        <select name="channel" id="gms-template-channel">
                                            <option value="sms" <?php selected($channel_value, 'sms'); ?>><?php esc_html_e('SMS', 'guest-management-system'); ?></option>
                                            <option value="whatsapp" <?php selected($channel_value, 'whatsapp'); ?>><?php esc_html_e('WhatsApp', 'guest-management-system'); ?></option>
                                            <option value="all" <?php selected($channel_value, 'all'); ?>><?php esc_html_e('All Channels', 'guest-management-system'); ?></option>
                                        </select>
                                        <p class="description"><?php esc_html_e('Select which messaging channel(s) this template applies to.', 'guest-management-system'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="gms-template-content"><?php esc_html_e('Message', 'guest-management-system'); ?></label></th>
                                    <td>
                                        <textarea name="content" id="gms-template-content" rows="6" class="large-text code" required><?php echo esc_textarea($editing_template['content'] ?? ''); ?></textarea>
                                        <p class="description"><?php esc_html_e('Use merge tags like {guest_name} or {property_name} to personalize messages.', 'guest-management-system'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Status', 'guest-management-system'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="is_active" value="1" <?php checked($editing_template ? !empty($editing_template['is_active']) : true); ?>>
                                            <?php esc_html_e('Active', 'guest-management-system'); ?>
                                        </label>
                                        <p class="description"><?php esc_html_e('Inactive templates are hidden from the messaging inbox.', 'guest-management-system'); ?></p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <?php submit_button($editing_template ? __('Update Template', 'guest-management-system') : __('Add Template', 'guest-management-system')); ?>
                        <?php if ($editing_template) : ?>
                            <a class="button button-secondary" href="<?php echo esc_url($base_templates_url); ?>"><?php esc_html_e('Cancel', 'guest-management-system'); ?></a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tabs = $this->get_settings_tabs();
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        if (!array_key_exists($current_tab, $tabs)) {
            $current_tab = 'general';
        }

        if (isset($_GET['settings-updated'])) {
            add_settings_error('gms_settings_messages', 'gms_settings_updated', __('Settings saved.', 'guest-management-system'), 'updated');
        }

        ?>
        <div class="wrap gms-settings">
            <h1><?php esc_html_e('Guest Management Settings', 'guest-management-system'); ?></h1>
            <?php settings_errors('gms_settings_messages'); ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab => $label) :
                    $tab_url = add_query_arg(array('tab' => $tab));
                    $active_class = $tab === $current_tab ? ' nav-tab-active' : '';
                    ?>
                    <a href="<?php echo esc_url($tab_url); ?>" class="nav-tab<?php echo esc_attr($active_class); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </h2>

            <form action="options.php" method="post">
                <?php
                switch ($current_tab) {
                    case 'integrations':
                        settings_fields('gms_settings_integrations');
                        do_settings_sections('gms_settings_integrations');
                        break;

                    case 'branding':
                        settings_fields('gms_settings_branding');
                        do_settings_sections('gms_settings_branding');
                        break;

                    case 'templates':
                        settings_fields('gms_settings_templates');
                        do_settings_sections('gms_settings_templates');
                        break;

                    case 'general':
                    default:
                        settings_fields('gms_settings_general');
                        do_settings_sections('gms_settings_general');
                        break;
                }

                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
