<?php

class GMS_Agreement_Handler {

    public function __construct() {
        add_action('wp_ajax_nopriv_gms_save_agreement', array($this, 'save_agreement'));
    }

    public function save_agreement() {
        check_ajax_referer('gms_guest_nonce', 'nonce');

        $reservation_id = isset($_POST['reservation_id']) ? intval($_POST['reservation_id']) : 0;
        $signature_data = isset($_POST['signature']) ? sanitize_text_field($_POST['signature']) : '';

        if (!$reservation_id || empty($signature_data)) {
            wp_send_json_error(array('message' => 'Missing data.'));
        }

        $reservation = GMS_Database::getReservationById($reservation_id);
        if (!$reservation) {
            wp_send_json_error(array('message' => 'Reservation not found.'));
        }

        $existing = GMS_Database::getAgreementByReservation($reservation_id);
        if ($existing && $existing['status'] === 'signed') {
            wp_send_json_error(array('message' => 'Agreement already signed.'));
        }

        $agreement_id = GMS_Database::createAgreement(array(
            'reservation_id' => $reservation_id,
            'guest_id' => $reservation['guest_id'],
            'agreement_text' => get_option('gms_agreement_template', ''),
            'signature_data' => $signature_data,
        ));

        if (!$agreement_id) {
            wp_send_json_error(array('message' => 'Failed to save agreement.'));
        }

        $pdf = $this->generatePDFForAgreement($agreement_id);

        if (is_wp_error($pdf)) {
            wp_send_json_error(array('message' => $pdf->get_error_message()));
        }

        GMS_Database::updateReservationStatus($reservation_id, 'agreement_signed');

        wp_send_json_success(array(
            'message' => 'Agreement signed successfully!',
            'pdf_url' => $pdf['url'],
        ));
    }

    public function generate_agreement_pdf($reservation_id, $signature_data) {
        $reservation = GMS_Database::getReservationById($reservation_id);
        if (!$reservation) {
            return new WP_Error('gms_missing_reservation', 'Reservation not found.');
        }

        $agreement = GMS_Database::getAgreementByReservation($reservation_id);

        if ($agreement) {
            GMS_Database::updateAgreement($agreement['id'], array('signature_data' => $signature_data));
            return $this->generatePDFForAgreement($agreement['id']);
        }

        $agreement_id = GMS_Database::createAgreement(array(
            'reservation_id' => $reservation_id,
            'guest_id' => $reservation['guest_id'],
            'agreement_text' => get_option('gms_agreement_template', ''),
            'signature_data' => $signature_data,
        ));

        if (!$agreement_id) {
            return new WP_Error('gms_agreement_create_failed', 'Unable to create agreement record.');
        }

        return $this->generatePDFForAgreement($agreement_id);
    }

    public function generatePDFForAgreement($agreement_id) {
        $agreement = GMS_Database::getAgreementById($agreement_id);
        if (!$agreement) {
            return new WP_Error('gms_missing_agreement', 'Agreement not found.');
        }

        $reservation = GMS_Database::getReservationById($agreement['reservation_id']);
        if (!$reservation) {
            return new WP_Error('gms_missing_reservation', 'Reservation not found.');
        }

        require_once GMS_PLUGIN_PATH . 'lib/tcpdf/tcpdf.php';

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return new WP_Error('gms_upload_dir_error', $upload_dir['error']);
        }

        wp_mkdir_p($upload_dir['path']);

        $filename = sprintf('agreement-%s-%s.pdf', sanitize_title($reservation['portal_token']), time());
        $pdf_path = trailingslashit($upload_dir['path']) . $filename;
        $pdf_url = trailingslashit($upload_dir['url']) . $filename;

        $pdf = new TCPDF();
        $pdf->SetCreator('Guest Management System');
        $pdf->SetAuthor($reservation['guest_name']);
        $pdf->SetTitle('Guest Agreement');
        $pdf->SetMargins(20, 20, 20);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);

        $agreement_text = $agreement['agreement_text'];
        if (empty($agreement_text)) {
            $agreement_text = get_option('gms_agreement_template', '');
        }

        $pdf->Write(0, 'Guest Agreement', '', 0, 'C', true, 0, false, false, 0);
        $pdf->Ln(5);
        $pdf->MultiCell(0, 10, wp_strip_all_tags($agreement_text));

        $pdf->Ln(10);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Write(0, sprintf('Guest Name: %s', $reservation['guest_name']));
        $pdf->Ln(6);
        $pdf->Write(0, sprintf('Booking Reference: %s', $reservation['booking_reference']));
        $pdf->Ln(6);
        $signed_at = !empty($agreement['signed_at']) ? $agreement['signed_at'] : current_time('mysql');
        $pdf->Write(0, sprintf('Signed At: %s', date('F j, Y g:i A', strtotime($signed_at))));
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Write(0, 'Guest Signature:');

        $signature_data = $agreement['signature_data'];
        if (!empty($signature_data)) {
            $signature_data = preg_replace('#^data:image/\w+;base64,#i', '', $signature_data);
            $signature_image = base64_decode($signature_data);

            if ($signature_image !== false) {
                $pdf->Image('@' . $signature_image, 15, $pdf->GetY() + 5, 60, 30, 'PNG');
                $pdf->Ln(35);
            }
        }

        $pdf->Output($pdf_path, 'F');

        GMS_Database::updateAgreement($agreement_id, array(
            'pdf_path' => $pdf_path,
            'pdf_url' => $pdf_url,
            'status' => 'signed',
            'signed_at' => current_time('mysql'),
        ));

        return array(
            'path' => $pdf_path,
            'url' => $pdf_url,
        );
    }
}

