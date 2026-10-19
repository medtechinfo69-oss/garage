<?php
/**
 * Envoi du devis par e-mail (HTML + PDF joint).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_admin();
require_once __DIR__ . '/../../includes/doc_pdf.php';
require_once __DIR__ . '/../../includes/mailer.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect('admin/devis/index.php');
}
verify_csrf();

$devisId = (int) ($_POST['devis_id'] ?? 0);
$to = trim((string) ($_POST['to_email'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

$doc = fetch_one(
    "SELECT d.*, c.civility, c.first_name, c.last_name, c.phone, c.email, c.address, c.postal_code, c.city,
            v.license_plate, v.brand, v.model, v.year, v.vin, v.mileage
     FROM devis d
     JOIN clients c ON c.id = d.client_id
     LEFT JOIN vehicles v ON v.id = d.vehicle_id
     WHERE d.id = ?",
    [$devisId]
);
if (!$doc) {
    flash('danger', t('error.not_found'));
    redirect('admin/devis/index.php');
}

$items = fetch_doc_items('devis', $devisId);
$client = ['civility' => $doc['civility'], 'first_name' => $doc['first_name'], 'last_name' => $doc['last_name'],
           'phone' => $doc['phone'], 'email' => $doc['email'], 'address' => $doc['address'],
           'postal_code' => $doc['postal_code'], 'city' => $doc['city']];
$vehicle = $doc['vehicle_id'] ? [
    'license_plate' => $doc['license_plate'], 'brand' => $doc['brand'], 'model' => $doc['model'],
    'year' => $doc['year'], 'vin' => $doc['vin'], 'mileage' => $doc['mileage'],
] : null;

// génère le PDF en mémoire temporaire
$co = company_info();
$pdfFile = tempnam(sys_get_temp_dir(), 'devis_') . '.pdf';
$pdf = build_document_pdf($doc, $client, $vehicle, $items, 'devis');
file_put_contents($pdfFile, $pdf->build());

$subject = t('email.subject_devis', $doc['quote_number'], $co['name']);
$html = document_email_html($doc, $client, $vehicle, 'devis', $message);

[$ok, $err] = send_document_email($to, $subject, $html, $pdfFile, $doc['quote_number'] . '.pdf', $devisId, 'devis', $doc['quote_number']);

if ($ok) {
    execute("UPDATE devis SET status = 'envoye' WHERE id = ? AND status = 'brouillon'", [$devisId]);
    flash('success', t('flash.email_sent', $to));
} else {
    @unlink($pdfFile);
    flash('danger', t('flash.email_failed') . ' (PDF : <a href="pdf.php?id=' . $devisId . '" target="_blank">' . e($doc['quote_number']) . '.pdf</a>)');
}
redirect('admin/devis/view.php?id=' . $devisId);
