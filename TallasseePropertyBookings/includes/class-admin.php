<?php
/**
 * Admin Class - Guest Management System
 * File: /wp-content/plugins/guest-management-system/includes/class-admin.php
 * 
 * Handles admin interface including Templates menu with rich text editor
 */

class GMS_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'addMenuPages'));
        add_action('admin_init', array($this, 'registerSettings'));
        add_action('admin_post_gms_save_template', array($this, 'saveTemplate'));
    }
    
    public function addMenuPages() {
        // Main menu
        add_menu_page(
            'Guest Management',
            'Guest Management',
            'manage_options',
            'guest-management',
            array($this, 'dashboardPage'),
            'dashicons-groups',
            30
        );
        
        // Reservations submenu
        add_submenu_page(
            'guest-management',
            'Reservations',
            'Reservations',
            'manage_options',
            'gms-reservations',
            array($this, 'reservationsPage')
        );
        
        // Templates submenu - NEW
        add_submenu_page(
            'guest-management',
            'Templates',
            'Templates',
            'manage_options',
            'gms-templates',
            array($this, 'templatesPage')
        );
        
        // Settings submenu
        add_submenu_page(
            'guest-management',
            'Settings',
            'Settings',
            'manage_options',
            'gms-settings',
            array($this, 'settingsPage')
        );
    }
    
    public function registerSettings() {
        register_setting('gms_settings', 'gms_agreement_template');
        register_setting('gms_settings', 'gms_email_template');
        register_setting('gms_settings', 'gms_sms_template');
        register_setting('gms_settings', 'gms_stripe_publishable_key');
        register_setting('gms_settings', 'gms_stripe_secret_key');
        register_setting('gms_settings', 'gms_voipms_username');
        register_setting('gms_settings', 'gms_voipms_password');
        register_setting('gms_settings', 'gms_voipms_did');
        register_setting('gms_settings', 'gms_company_name');
        register_setting('gms_settings', 'gms_company_logo');
    }
    
    public function templatesPage() {
        // Load current template
        $agreement_template = get_option('gms_agreement_template', $this->getDefaultAgreementTemplate());
        
        ?>
        <div class="wrap">
            <h1>Agreement Template</h1>
            
            <div class="notice notice-info">
                <p><strong>Available Variables:</strong> Use these in your template and they'll be replaced with actual data:</p>
                <p>
                    <code>{guest_name}</code>, 
                    <code>{guest_email}</code>, 
                    <code>{guest_phone}</code>, 
                    <code>{property_name}</code>, 
                    <code>{booking_reference}</code>, 
                    <code>{checkin_date}</code>, 
                    <code>{checkout_date}</code>, 
                    <code>{checkin_time}</code>, 
                    <code>{checkout_time}</code>, 
                    <code>{company_name}</code>
                </p>
            </div>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('gms_save_template', 'gms_template_nonce'); ?>
                <input type="hidden" name="action" value="gms_save_template">
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="gms_agreement_template">Agreement Template</label>
                        </th>
                        <td>
                            <?php
                            // Rich text editor
                            wp_editor(
                                $agreement_template,
                                'gms_agreement_template',
                                array(
                                    'textarea_name' => 'gms_agreement_template',
                                    'textarea_rows' => 20,
                                    'media_buttons' => false,
                                    'teeny' => false,
                                    'quicktags' => true,
                                    'tinymce' => array(
                                        'toolbar1' => 'formatselect,bold,italic,underline,strikethrough,bullist,numlist,blockquote,hr,alignleft,aligncenter,alignright,link,unlink,removeformat,undo,redo',
                                        'toolbar2' => ''
                                    )
                                )
                            );
                            ?>
                            <p class="description">
                                This template will be shown to guests before they sign. 
                                Use the variables above to personalize the agreement.
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Template'); ?>
            </form>
            
            <hr>
            
            <h2>Preview</h2>
            <div style="border: 1px solid #ccc; padding: 20px; background: #fff; max-width: 800px;">
                <?php echo wpautop($this->replaceTemplatePlaceholders($agreement_template)); ?>
            </div>
        </div>
        <?php
    }
    
    public function saveTemplate() {
        // Verify nonce
        if (!isset($_POST['gms_template_nonce']) || !wp_verify_nonce($_POST['gms_template_nonce'], 'gms_save_template')) {
            wp_die('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }
        
        // Save template
        $template = wp_kses_post($_POST['gms_agreement_template']);
        update_option('gms_agreement_template', $template);
        
        // Redirect with success message
        wp_redirect(add_query_arg(
            array(
                'page' => 'gms-templates',
                'updated' => 'true'
            ),
            admin_url('admin.php')
        ));
        exit;
    }
    
    private function getDefaultAgreementTemplate() {
        return '<h2>Guest Agreement for {property_name}</h2>

<p><strong>Guest Information:</strong></p>
<ul>
    <li>Name: {guest_name}</li>
    <li>Email: {guest_email}</li>
    <li>Phone: {guest_phone}</li>
    <li>Booking Reference: {booking_reference}</li>
    <li>Check-in: {checkin_date} at {checkin_time}</li>
    <li>Check-out: {checkout_date} at {checkout_time}</li>
</ul>

<h3>House Rules</h3>
<ol>
    <li><strong>Maximum Occupancy:</strong> The property may not be occupied by more than the number of guests specified in the reservation.</li>
    <li><strong>Quiet Hours:</strong> Please observe quiet hours between 10 PM and 8 AM.</li>
    <li><strong>No Smoking:</strong> This is a non-smoking property. Smoking is not permitted anywhere on the premises.</li>
    <li><strong>No Pets:</strong> Pets are not allowed unless explicitly authorized in your reservation.</li>
    <li><strong>No Parties:</strong> No parties or events are permitted without prior written approval.</li>
    <li><strong>Property Care:</strong> Please treat the property with respect and report any damages immediately.</li>
    <li><strong>Check-out:</strong> Please ensure the property is left in good condition at check-out.</li>
</ol>

<h3>Terms and Conditions</h3>
<p>By signing this agreement, you acknowledge that you have read and agree to:</p>
<ul>
    <li>Comply with all house rules stated above</li>
    <li>Be responsible for any damages caused during your stay</li>
    <li>Understand that violation of these rules may result in immediate eviction without refund</li>
    <li>Agree to allow property inspection if deemed necessary</li>
    <li>Accept liability for any additional cleaning fees if property is left in unacceptable condition</li>
</ul>

<p><strong>I agree to the terms and conditions stated above.</strong></p>';
    }
    
    private function replaceTemplatePlaceholders($template, $data = null) {
        // Sample data for preview
        if (!$data) {
            $data = array(
                'guest_name' => 'John Doe',
                'guest_email' => 'john@example.com',
                'guest_phone' => '+1 (555) 123-4567',
                'property_name' => 'Sunset Beach House',
                'booking_reference' => 'DEMO-123456',
                'checkin_date' => 'June 15, 2025',
                'checkout_date' => 'June 20, 2025',
                'checkin_time' => '3:00 PM',
                'checkout_time' => '11:00 AM',
                'company_name' => get_option('gms_company_name', 'Your Company')
            );
        }
        
        foreach ($data as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }
        
        return $template;
    }
    
    public function dashboardPage() {
        // Dashboard content (keep existing)
        echo '<div class="wrap"><h1>Guest Management Dashboard</h1></div>';
    }
    
    public function reservationsPage() {
        // Reservations content (keep existing)
        echo '<div class="wrap"><h1>Reservations</h1></div>';
    }
    
    public function settingsPage() {
        // Settings content (keep existing)
        echo '<div class="wrap"><h1>Settings</h1></div>';
    }
}