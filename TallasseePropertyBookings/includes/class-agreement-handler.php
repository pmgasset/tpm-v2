<?php
/**
 * Agreement Handler with PDF Generation
 * File: /wp-content/plugins/guest-management-system/includes/class-agreement-handler.php
 * 
 * Handles agreement submission and PDF generation using mPDF
 */

// Require mPDF library
require_once plugin_dir_path(__FILE__) . '../vendor/autoload.php';

class GMS_Agreement_Handler {
    
    public function __construct() {
        add_action('wp_ajax_gms_submit_agreement', array($this, 'submitAgreement'));
        add_action('wp_ajax_nopriv_gms_submit_agreement', array($this, 'submitAgreement'));
    }
    
    public function submitAgreement() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gms_guest_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        global $wpdb;
        $reservation_id = intval($_POST['reservation_id']);
        $signature_data = sanitize_text_field($_POST['signature_data']);
        
        // Get reservation data
        $reservations_table = $wpdb->prefix . 'gms_reservations';
        $reservation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $reservations_table WHERE id = %d",
            $reservation_id
        ), ARRAY_A);
        
        if (!$reservation) {
            wp_send_json_error('Reservation not found');
        }
        
        // Get user IP and agent
        $ip_address = $this->getUserIP();
        $user_agent = sanitize_text_field($_SERVER['HTTP_USER_AGENT']);
        
        // Save agreement to database
        $agreements_table = $wpdb->prefix . 'gms_guest_agreements';
        $insert = $wpdb->insert(
            $agreements_table,
            array(
                'reservation_id' => $reservation_id,
                'guest_id' => $reservation['guest_id'],
                'agreement_text' => get_option('gms_agreement_template'),
                'signature_data' => $signature_data,
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'signed_at' => current_time('mysql'),
                'status' => 'signed'
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if (!$insert) {
            wp_send_json_error('Failed to save agreement');
        }
        
        $agreement_id = $wpdb->insert_id;
        
        // Generate PDF
        $pdf_result = $this->generatePDF($reservation, $signature_data, $ip_address, $user_agent);
        
        if (is_wp_error($pdf_result)) {
            wp_send_json_error($pdf_result->get_error_message());
        }
        
        // Update reservation status
        $wpdb->update(
            $reservations_table,
            array('status' => 'agreement_signed'),
            array('id' => $reservation_id),
            array('%s'),
            array('%d')
        );
        
        // Send SMS notification with portal link
        $this->sendSMSNotification($reservation, $pdf_result['url']);
        
        wp_send_json_success(array(
            'message' => 'Agreement signed successfully',
            'pdf_url' => $pdf_result['url']
        ));
    }
    
    private function generatePDF($reservation, $signature_data, $ip_address, $user_agent) {
        try {
            // Initialize mPDF
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8',
                'format' => 'Letter',
                'margin_left' => 15,
                'margin_right' => 15,
                'margin_top' => 20,
                'margin_bottom' => 20,
                'margin_header' => 10,
                'margin_footer' => 10
            ]);
            
            // Get agreement template
            $agreement_template = get_option('gms_agreement_template');
            
            // Replace placeholders with actual data
            $data = array(
                'guest_name' => $reservation['guest_name'],
                'guest_email' => $reservation['guest_email'],
                'guest_phone' => $reservation['guest_phone'],
                'property_name' => $reservation['property_name'],
                'booking_reference' => $reservation['booking_reference'],
                'checkin_date' => date('F j, Y', strtotime($reservation['checkin_date'])),
                'checkout_date' => date('F j, Y', strtotime($reservation['checkout_date'])),
                'checkin_time' => $reservation['checkin_time'],
                'checkout_time' => $reservation['checkout_time'],
                'company_name' => get_option('gms_company_name', 'Property Management')
            );
            
            foreach ($data as $key => $value) {
                $agreement_template = str_replace('{' . $key . '}', $value, $agreement_template);
            }
            
            // Build HTML for PDF
            $html = $this->buildPDFHTML($agreement_template, $reservation, $signature_data, $ip_address, $user_agent);
            
            // Write HTML to PDF
            $mpdf->WriteHTML($html);
            
            // Generate filename: booking-reference.pdf
            $filename = sanitize_file_name($reservation['booking_reference']) . '.pdf';
            
            // Save to WordPress uploads directory
            $upload_dir = wp_upload_dir();
            $pdf_dir = $upload_dir['basedir'] . '/guest-agreements/';
            
            // Create directory if it doesn't exist
            if (!file_exists($pdf_dir)) {
                wp_mkdir_p($pdf_dir);
            }
            
            $pdf_path = $pdf_dir . $filename;
            $mpdf->Output($pdf_path, \Mpdf\Output\Destination::FILE);
            
            // Add to WordPress media library
            $attachment_id = $this->addToMediaLibrary($pdf_path, $filename, $reservation);
            
            if (is_wp_error($attachment_id)) {
                return $attachment_id;
            }
            
            // Get PDF URL
            $pdf_url = wp_get_attachment_url($attachment_id);
            
            // Update agreement record with PDF info
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'gms_guest_agreements',
                array(
                    'pdf_url' => $pdf_url,
                    'pdf_attachment_id' => $attachment_id
                ),
                array('reservation_id' => $reservation['id']),
                array('%s', '%d'),
                array('%d')
            );
            
            return array(
                'url' => $pdf_url,
                'path' => $pdf_path,
                'attachment_id' => $attachment_id
            );
            
        } catch (Exception $e) {
            return new WP_Error('pdf_generation_failed', $e->getMessage());
        }
    }
    
    private function buildPDFHTML($agreement_text, $reservation, $signature_data, $ip_address, $user_agent) {
        $company_name = get_option('gms_company_name', 'Property Management');
        $company_logo = get_option('gms_company_logo', '');
        $signed_at = current_time('F j, Y \a\t g:i A T');
        
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    font-size: 11pt;
                    line-height: 1.6;
                    color: #333;
                }
                .header {
                    text-align: center;
                    margin-bottom: 30px;
                    padding-bottom: 20px;
                    border-bottom: 3px solid #0073aa;
                }
                .header h1 {
                    color: #0073aa;
                    margin: 10px 0;
                }
                .logo {
                    max-width: 200px;
                    margin-bottom: 10px;
                }
                .info-box {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 5px;
                    margin: 20px 0;
                }
                .info-row {
                    margin: 5px 0;
                }
                .label {
                    font-weight: bold;
                    display: inline-block;
                    width: 150px;
                }
                .agreement-content {
                    margin: 20px 0;
                    line-height: 1.8;
                }
                .signature-section {
                    margin-top: 40px;
                    padding: 20px;
                    background: #f8f9fa;
                    border-radius: 5px;
                }
                .signature-image {
                    border: 2px solid #333;
                    padding: 10px;
                    background: #fff;
                    max-width: 400px;
                    margin: 10px 0;
                }
                .verification-section {
                    margin-top: 30px;
                    padding: 15px;
                    background: #fff3cd;
                    border: 1px solid #ffc107;
                    border-radius: 5px;
                    font-size: 9pt;
                }
                .verification-title {
                    font-weight: bold;
                    color: #856404;
                    margin-bottom: 10px;
                }
                .footer {
                    margin-top: 40px;
                    padding-top: 20px;
                    border-top: 1px solid #ddd;
                    text-align: center;
                    font-size: 9pt;
                    color: #666;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                }
                th, td {
                    padding: 8px;
                    text-align: left;
                }
            </style>
        </head>
        <body>
            <div class="header">';
        
        if ($company_logo) {
            $html .= '<img src="' . esc_url($company_logo) . '" class="logo" alt="' . esc_attr($company_name) . '">';
        }
        
        $html .= '
                <h1>' . esc_html($company_name) . '</h1>
                <h2>Guest Agreement</h2>
            </div>
            
            <div class="info-box">
                <h3 style="margin-top: 0;">Reservation Details</h3>
                <div class="info-row"><span class="label">Guest Name:</span> ' . esc_html($reservation['guest_name']) . '</div>
                <div class="info-row"><span class="label">Email:</span> ' . esc_html($reservation['guest_email']) . '</div>
                <div class="info-row"><span class="label">Phone:</span> ' . esc_html($reservation['guest_phone']) . '</div>
                <div class="info-row"><span class="label">Property:</span> ' . esc_html($reservation['property_name']) . '</div>
                <div class="info-row"><span class="label">Booking Reference:</span> <strong>' . esc_html($reservation['booking_reference']) . '</strong></div>
                <div class="info-row"><span class="label">Check-in:</span> ' . date('F j, Y', strtotime($reservation['checkin_date'])) . ' at ' . esc_html($reservation['checkin_time']) . '</div>
                <div class="info-row"><span class="label">Check-out:</span> ' . date('F j, Y', strtotime($reservation['checkout_date'])) . ' at ' . esc_html($reservation['checkout_time']) . '</div>
            </div>
            
            <div class="agreement-content">
                ' . $agreement_text . '
            </div>
            
            <div class="signature-section">
                <h3 style="margin-top: 0;">Electronic Signature</h3>
                <p><strong>Signed by:</strong> ' . esc_html($reservation['guest_name']) . '</p>
                <p><strong>Date & Time:</strong> ' . $signed_at . '</p>
                <div class="signature-image">
                    <img src="' . esc_attr($signature_data) . '" style="max-width: 100%; height: auto;" alt="Guest Signature">
                </div>
            </div>
            
            <div class="verification-section">
                <div class="verification-title">⚠️ VERIFICATION INFORMATION (For Legal & Chargeback Protection)</div>
                <table>
                    <tr>
                        <td><strong>IP Address:</strong></td>
                        <td>' . esc_html($ip_address) . '</td>
                    </tr>
                    <tr>
                        <td><strong>User Agent:</strong></td>
                        <td style="font-size: 8pt;">' . esc_html($user_agent) . '</td>
                    </tr>
                    <tr>
                        <td><strong>Signature Method:</strong></td>
                        <td>Electronic Signature (HTML5 Canvas)</td>
                    </tr>
                    <tr>
                        <td><strong>Document ID:</strong></td>
                        <td>' . esc_html($reservation['booking_reference']) . '</td>
                    </tr>
                    <tr>
                        <td><strong>Timestamp:</strong></td>
                        <td>' . $signed_at . '</td>
                    </tr>
                </table>
                <p style="margin-top: 10px; font-size: 8pt; font-style: italic;">
                    This document was electronically signed and timestamped. The signature, IP address, and user agent 
                    information have been recorded for verification purposes and legal protection.
                </p>
            </div>
            
            <div class="footer">
                <p>This is a legally binding electronic agreement.</p>
                <p>' . esc_html($company_name) . ' | Document Reference: ' . esc_html($reservation['booking_reference']) . '</p>
            </div>
        </body>
        </html>';
        
        return $html;
    }
    
    private function addToMediaLibrary($file_path, $filename, $reservation) {
        // Check if file exists
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', 'PDF file not found');
        }
        
        // Prepare attachment data
        $attachment = array(
            'post_mime_type' => 'application/pdf',
            'post_title' => 'Agreement - ' . $reservation['booking_reference'],
            'post_content' => 'Guest agreement for ' . $reservation['guest_name'] . ' - ' . $reservation['property_name'],
            'post_status' => 'inherit',
            'post_author' => $reservation['guest_id']
        );
        
        // Insert attachment
        $attachment_id = wp_insert_attachment($attachment, $file_path);
        
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        
        // Generate attachment metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attachment_data);
        
        // Add custom meta for easy filtering
        update_post_meta($attachment_id, '_gms_reservation_id', $reservation['id']);
        update_post_meta($attachment_id, '_gms_booking_reference', $reservation['booking_reference']);
        update_post_meta($attachment_id, '_gms_document_type', 'signed_agreement');
        
        return $attachment_id;
    }
    
    private function sendSMSNotification($reservation, $pdf_url) {
        // Get SMS settings
        $voipms_username = get_option('gms_voipms_username');
        $voipms_password = get_option('gms_voipms_password');
        $voipms_did = get_option('gms_voipms_did');
        
        if (empty($voipms_username) || empty($voipms_password) || empty($voipms_did)) {
            error_log('GMS: SMS not configured, skipping notification');
            return false;
        }
        
        // Build message
        $portal_link = home_url('/guest-portal/' . $reservation['portal_token']);
        $message = "Your agreement for " . $reservation['property_name'] . " has been signed! Download your copy here: " . $portal_link;
        
        // Send via VoIP.ms API
        $sms_handler = new GMS_SMS_Handler();
        return $sms_handler->sendSMS($reservation['guest_phone'], $message);
    }
    
    private function getUserIP() {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return sanitize_text_field($ip);
    }
}