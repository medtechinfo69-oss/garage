<?php
/**
 * Envoi de la facture par e-mail (HTML + PDF joint).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_admin();
require_once __DIR__ . '/../../includes/doc_pdf.php';
require_once __DIR__ . '/../../includes/mailer.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect('admin/factures/index.php');
}
verify_csrf();

$factureId = (int) ($_POST['facture_id'] ?? 0);
$to = trim((string) ($_POST['to_email'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    flash('danger', t('error.invalid_email'));
    redirect('admin/factures/view.php?id=' . $factureId);
}

$doc = fetch_one(
    "SELECT f.*, d.quote_number, c.civility, c.first_name, c.last_name, c.phone, c.email, c.address, c.postal_code, c.city,
            v.license_plate, v.brand, v.model, v.year, v.vin, v.mileage
     FROM factures f
     LEFT JOIN devis d ON d.id = f.devis_id
     JOIN clients c ON c.id = f.client_id
     LEFT JOIN vehicles v ON v.id = f.vehicle_id
     WHERE f.id = ?",
    [$factureId]
);
if (!$doc) {
    flash('danger', t('error.not_found'));
    redirect('admin/factures/index.php');
}

$items = fetch_doc_items('facture', $factureId);
$client = ['civility' => $doc['civility'], 'first_name' => $doc['first_name'], 'last_name' => $doc['last_name'],
           'phone' => $doc['phone'], 'email' => $doc['email'], 'address' => $doc['address'],
           'postal_code' => $doc['postal_code'], 'city' => $doc['city']];
$vehicle = $doc['license_plate'] ? [
    'license_plate' => $doc['license_plate'], 'brand' => $doc['brand'], 'model' => $doc['model'],
    'year' => $doc['year'], 'vin' => $doc['vin'], 'mileage' => $doc['mileage'],
] : null;

$co = company_info();
$pdfFile = tempnam(sys_get_temp_dir(), 'facture_');
if ($pdfFile === false) {
    flash('danger', t('flash.email_failed'));
    redirect('admin/factures/view.php?id=' . $factureId);
}

try {
    $pdf = build_document_pdf($doc, $client, $vehicle, $items, 'facture');
    if (file_put_contents($pdfFile, $pdf->build()) === false) {
        throw new RuntimeException('Unable to write temporary PDF');
    }

    $subject = t('email.subject_facture', $doc['invoice_number'], $co['name']);
    $html = document_email_html($doc, $client, $vehicle, 'facture', $message);

    [$ok, $err] = send_document_email($to, $subject, $html, $pdfFile, $doc['invoice_number'] . '.pdf', $factureId, 'factures', $doc['invoice_number']);
} catch (Throwable $e) {
    error_log('[garage] Invoice email preparation failed: ' . $e->getMessage());
    $ok = false;
}

if ($ok) {
    execute("UPDATE factures SET status = 'envoyee' WHERE id = ? AND status = 'brouillon'", [$factureId]);
    flash('success', t('flash.email_sent', $to));
} else {
    flash('danger', t('flash.email_failed'), [
        'url' => 'admin/factures/pdf.php?id=' . $factureId,
        'label' => t('common.download_pdf') . ' — ' . $doc['invoice_number'] . '.pdf',
    ]);
}
@unlink($pdfFile);
redirect('admin/factures/view.php?id=' . $factureId);
