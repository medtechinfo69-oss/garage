<?php
/**
 * Génère et télécharge le PDF de la facture.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_admin();
require_once __DIR__ . '/../../includes/doc_pdf.php';

$id = (int) ($_GET['id'] ?? 0);
$doc = fetch_one(
    "SELECT f.*, d.quote_number, c.civility, c.first_name, c.last_name, c.phone, c.email, c.address, c.postal_code, c.city,
            v.license_plate, v.brand, v.model, v.year, v.vin, v.mileage
     FROM factures f
     LEFT JOIN devis d ON d.id = f.devis_id
     JOIN clients c ON c.id = f.client_id
     LEFT JOIN vehicles v ON v.id = f.vehicle_id
     WHERE f.id = ?",
    [$id]
);
if (!$doc) {
    /* Document inexistant : retour à la liste avec un message clair,
       plutôt qu'une page blanche « Not found ». */
    flash('danger', t('error.not_found'));
    redirect('admin/factures/index.php');
}
$items = fetch_doc_items('facture', $id);
$client = ['civility' => $doc['civility'], 'first_name' => $doc['first_name'], 'last_name' => $doc['last_name'],
           'phone' => $doc['phone'], 'email' => $doc['email'], 'address' => $doc['address'],
           'postal_code' => $doc['postal_code'], 'city' => $doc['city']];
$vehicle = $doc['license_plate'] ? [
    'license_plate' => $doc['license_plate'], 'brand' => $doc['brand'], 'model' => $doc['model'],
    'year' => $doc['year'], 'vin' => $doc['vin'], 'mileage' => $doc['mileage'],
] : null;

$pdf = build_document_pdf($doc, $client, $vehicle, $items, 'facture');
$pdf->send($doc['invoice_number'] . '.pdf');
