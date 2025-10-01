<?php
/**
 * File: class-email-handler.php
 * Location: /wp-content/plugins/guest-management-system/includes/class-email-handler.php
 * 
 * Email Handler for Guest Management System
 */

class GMS_Email_Handler {
    
    public function __construct() {
        add_filter('wp_mail_from', array($this, 'customMailFrom'));
        add_filter('wp_mail_from_name', array($this, 'customMailFromName'));
    }
    
    public function customMailFrom($email) {
        $from_email = get_option('gms_email_from');
        return !empty($from_email) ? $from_email : $email;
    }
    
    public function customMailFromName($name) {
        $from_name = get_option('gms_email_from_name');
        return !empty($from_name) ? $from_name : $name;
    }
    
    public function sendWelcomeEmail($reservation) {
        $template = get_option('gms_email_template');
        $subject = 'Complete Your Check-in for ' . $reservation['property_name'];

        $portal_url = gms_build_portal_url($reservation['portal_token']);
        if ($portal_url === false) {
            $portal_url = '';
        }
        
        $replacements = array(
            '{guest_name}' => $reservation['guest_name'],
            '{property_name}' => $reservation['property_name'],
            '{booking_reference}' => $reservation['booking_reference'],
            '{checkin_date}' => date('l, F j, Y', strtotime($reservation['checkin_date'])),
            '{checkout_date}' => date('l, F j, Y', strtotime($reservation['checkout_date'])),
            '{checkin_time}' => date('g:i A', strtotime($reservation['checkin_date'])),
            '{checkout_time}' => date('g:i A', strtotime($reservation['checkout_date'])),
            '{portal_link}' => $portal_url,
            '{company_name}' => get_option('gms_company_name', get_option('blogname'))
        );
        
        $message = str_replace(array_keys($replacements), array_values($replacements), $template);
        
        // Create HTML email
        $html_message = $this->wrapInEmailTemplate($message, $reservation);
        
        // Set headers for HTML email
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $result = wp_mail($reservation['guest_email'], $subject, $html_message, $headers);
        
        // Log communication
        GMS_Database::logCommunication(array(
            'reservation_id' => $reservation['id'],
            'guest_id' => $reservation['guest_id'],
            'type' => 'email',
            'recipient' => $reservation['guest_email'],
            'subject' => $subject,
            'message' => $message,
            'status' => $result ? 'sent' : 'failed',
            'response_data' => array('result' => $result)
        ));
        
        return $result;
    }

    public function sendReservationApprovedEmail($reservation) {
        $template = get_option('gms_approved_email_template');

        if (empty($template)) {
            $template = "Hi {guest_name},\n\nYour reservation at {property_name} has been approved. Visit your guest portal to complete any remaining steps: {portal_link}\n\nCheck-in: {checkin_date} at {checkin_time}\nCheck-out: {checkout_date} at {checkout_time}\nBooking Reference: {booking_reference}\n\nWe look forward to hosting you!\n{company_name}";
        }

        $subject = sprintf(
            /* translators: %s: property name */
            __('Reservation Approved for %s', 'guest-management-system'),
            $reservation['property_name']
        );

        $portal_url = gms_build_portal_url($reservation['portal_token']);
        if ($portal_url === false) {
            $portal_url = '';
        }

        $replacements = array(
            '{guest_name}' => $reservation['guest_name'],
            '{property_name}' => $reservation['property_name'],
            '{booking_reference}' => $reservation['booking_reference'],
            '{checkin_date}' => date('l, F j, Y', strtotime($reservation['checkin_date'])),
            '{checkout_date}' => date('l, F j, Y', strtotime($reservation['checkout_date'])),
            '{checkin_time}' => date('g:i A', strtotime($reservation['checkin_date'])),
            '{checkout_time}' => date('g:i A', strtotime($reservation['checkout_date'])),
            '{portal_link}' => $portal_url,
            '{company_name}' => get_option('gms_company_name', get_option('blogname')),
        );

        $message = str_replace(array_keys($replacements), array_values($replacements), $template);

        $html_message = $this->wrapInEmailTemplate($message, $reservation);

        $headers = array('Content-Type: text/html; charset=UTF-8');

        $result = wp_mail($reservation['guest_email'], $subject, $html_message, $headers);

        GMS_Database::logCommunication(array(
            'reservation_id' => $reservation['id'],
            'guest_id' => $reservation['guest_id'],
            'type' => 'email',
            'recipient' => $reservation['guest_email'],
            'subject' => $subject,
            'message' => $message,
            'status' => $result ? 'sent' : 'failed',
            'response_data' => array('result' => $result)
        ));

        return $result;
    }
    
    public function sendEmail($to, $subject, $message, $headers = array()) {
        if (empty($headers)) {
            $headers = array('Content-Type: text/html; charset=UTF-8');
        }
        
        $html_message = $this->wrapInEmailTemplate($message);
        
        return wp_mail($to, $subject, $html_message, $headers);
    }
    
    public function sendReminderEmail($reservation) {
        $subject = 'Reminder: Complete Your Check-in';
        
        $portal_url = gms_build_portal_url($reservation['portal_token']);
        if ($portal_url === false) {
            $portal_url = '';
        }
        
        $message = "Hi {$reservation['guest_name']},\n\n";
        $message .= "This is a friendly reminder to complete your check-in process for {$reservation['property_name']}.\n\n";
        $message .= "Check-in date: " . date('l, F j, Y', strtotime($reservation['checkin_date'])) . "\n\n";
        $message .= "Please visit your guest portal to:\n";
        $message .= "• Sign the guest agreement\n";
        $message .= "• Complete identity verification\n\n";
        $message .= "Portal link: {$portal_url}\n\n";
        $message .= "We look forward to hosting you!\n\n";
        $message .= get_option('gms_company_name', get_option('blogname'));
        
        $html_message = $this->wrapInEmailTemplate($message, $reservation);
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $result = wp_mail($reservation['guest_email'], $subject, $html_message, $headers);
        
        GMS_Database::logCommunication(array(
            'reservation_id' => $reservation['id'],
            'guest_id' => $reservation['guest_id'],
            'type' => 'email',
            'recipient' => $reservation['guest_email'],
            'subject' => $subject,
            'message' => $message,
            'status' => $result ? 'sent' : 'failed'
        ));
        
        return $result;
    }
    
    public function sendCompletionEmail($reservation) {
        $subject = 'Check-in Complete - Welcome to ' . $reservation['property_name'];
        
        $message = "Hi {$reservation['guest_name']},\n\n";
        $message .= "Great news! Your check-in process is complete.\n\n";
        $message .= "Here are your reservation details:\n\n";
        $message .= "Property: {$reservation['property_name']}\n";
        $message .= "Check-in: " . date('l, F j, Y \a\t g:i A', strtotime($reservation['checkin_date'])) . "\n";
        $message .= "Check-out: " . date('l, F j, Y \a\t g:i A', strtotime($reservation['checkout_date'])) . "\n";
        $message .= "Booking Reference: {$reservation['booking_reference']}\n\n";
        $message .= "You will receive property access instructions 24 hours before your check-in.\n\n";
        $message .= "If you have any questions, please don't hesitate to contact us.\n\n";
        $message .= "We look forward to hosting you!\n\n";
        $message .= get_option('gms_company_name', get_option('blogname'));
        
        $html_message = $this->wrapInEmailTemplate($message, $reservation);
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $result = wp_mail($reservation['guest_email'], $subject, $html_message, $headers);
        
        GMS_Database::logCommunication(array(
            'reservation_id' => $reservation['id'],
            'guest_id' => $reservation['guest_id'],
            'type' => 'email',
            'recipient' => $reservation['guest_email'],
            'subject' => $subject,
            'message' => $message,
            'status' => $result ? 'sent' : 'failed'
        ));
        
        return $result;
    }
    
    private function wrapInEmailTemplate($content, $reservation = null) {
        $company_name = get_option('gms_company_name', get_option('blogname'));
        $company_logo = get_option('gms_company_logo', '');
        $primary_color = get_option('gms_portal_primary_color', '#0073aa');
        
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html($company_name) . '</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; background-color: #f8f9fa;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f8f9fa;">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td align="center" style="background-color: ' . esc_attr($primary_color) . '; padding: 40px 20px;">
                            ' . ($company_logo ? '<img src="' . esc_url($company_logo) . '" alt="' . esc_attr($company_name) . '" style="max-width: 200px; height: auto; margin-bottom: 10px;">' : '') . '
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px;">' . esc_html($company_name) . '</h1>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <div style="color: #333333; line-height: 1.6; font-size: 16px;">
                                ' . nl2br(esc_html($content)) . '
                            </div>
                        </td>
                    </tr>';
        
        // Add portal button if reservation provided
        if ($reservation) {
            $portal_url = gms_build_portal_url($reservation['portal_token']);
            if ($portal_url === false) {
                $portal_url = '';
            }
            $html .= '
                    <tr>
                        <td align="center" style="padding: 0 30px 40px 30px;">
                            <a href="' . esc_url($portal_url) . '" style="display: inline-block; background-color: ' . esc_attr($primary_color) . '; color: #ffffff; text-decoration: none; padding: 15px 40px; border-radius: 4px; font-weight: 600; font-size: 16px;">Access Guest Portal</a>
                        </td>
                    </tr>';
        }
        
        $html .= '
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8f9fa; padding: 30px; text-align: center; border-top: 1px solid #e0e0e0;">
                            <p style="margin: 0 0 10px 0; color: #666666; font-size: 14px;">
                                © ' . date('Y') . ' ' . esc_html($company_name) . '. All rights reserved.
                            </p>
                            <p style="margin: 0; color: #999999; font-size: 12px;">
                                You received this email because you have an upcoming reservation with us.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        
        return $html;
    }
    
    public function sendAdminNotification($reservation, $type = 'new_booking') {
        $admin_email = get_option('admin_email');
        
        switch ($type) {
            case 'new_booking':
                $subject = 'New Booking Received - ' . $reservation['booking_reference'];
                $message = "A new booking has been received:\n\n";
                $message .= "Guest: {$reservation['guest_name']}\n";
                $message .= "Email: {$reservation['guest_email']}\n";
                $message .= "Property: {$reservation['property_name']}\n";
                $message .= "Check-in: " . date('M j, Y g:i A', strtotime($reservation['checkin_date'])) . "\n";
                $message .= "Check-out: " . date('M j, Y g:i A', strtotime($reservation['checkout_date'])) . "\n";
                $message .= "Booking Reference: {$reservation['booking_reference']}\n";
                $message .= "Platform: {$reservation['platform']}\n\n";
                $portal_url = gms_build_portal_url($reservation['portal_token']);
                if ($portal_url === false) {
                    $portal_url = '';
                }
                $message .= "Guest Portal: " . $portal_url;
                break;
                
            case 'check_in_complete':
                $subject = 'Check-in Completed - ' . $reservation['booking_reference'];
                $message = "Guest check-in process completed:\n\n";
                $message .= "Guest: {$reservation['guest_name']}\n";
                $message .= "Property: {$reservation['property_name']}\n";
                $message .= "Booking Reference: {$reservation['booking_reference']}\n";
                $message .= "Check-in Date: " . date('M j, Y', strtotime($reservation['checkin_date']));
                break;
                
            default:
                return false;
        }
        
        return $this->sendEmail($admin_email, $subject, $message);
    }
}