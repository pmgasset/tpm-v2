<?php
class GMS_Agreement_Handler {

    public function __construct() {
        add_action('wp_ajax_nopriv_gms_save_agreement', [$this, 'save_agreement']);
    }

    public function save_agreement() {
        check_ajax_referer('gms_guest_nonce', 'nonce');

        $reservation_id = intval($_POST['reservation_id']);
        $signature_data = sanitize_text_field($_POST['signature']);

        if (!$reservation_id || empty($signature_data)) {
            wp_send_json_error(['message' => 'Missing data.']);
            return;
        }

        // Save signature and generate PDF
        $this->generate_agreement_pdf($reservation_id, $signature_data);

        // Update reservation status in the database
        GMS_Database::updateReservationStatus($reservation_id, 'agreement_signed');

        wp_send_json_success(['message' => 'Agreement signed successfully!']);
    }

    public function generate_agreement_pdf($reservation_id, $signature_data) {
        $reservation = GMS_Database::getReservationById($reservation_id);
        if (!$reservation) return;

        require_once GMS_PLUGIN_PATH . 'lib/tcpdf/tcpdf.php';

        $pdf = new TCPDF();
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        
        // Agreement Text
        $agreement_text = get_option('gms_agreement_template', 'Default agreement text.');
        $pdf->Write(0, "Guest Agreement\n\n", '', 0, 'C', true);
        $pdf->MultiCell(0, 10, $agreement_text);
        
        // Signature Image
        $pdf->Ln(10);
        $pdf->Write(0, 'Guest Signature:');
        $pdf->Image('@' . base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $signature_data)), 15, $pdf->GetY() + 5, 60, 30, 'PNG');

        // Save the PDF to the uploads directory
        $upload_dir = wp_upload_dir();
        $pdf_path = $upload_dir['path'] . '/agreement-' . $reservation['portal_token'] . '.pdf';
        $pdf->Output($pdf_path, 'F');
        
        // You can save the file path to the reservation's meta data for later access
        // update_post_meta($reservation_id, '_agreement_pdf_path', $pdf_path);
    }
}
