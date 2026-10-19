<?php
/**
 * Génère et télécharge le PDF du devis.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_admin();
require_once __DIR__ . '/../../includes/doc_pdf.php';

$id = (int) ($_GET['id'] ?? 0);
$doc = fetch_one(
    "SELECT d.*, c.civility, c.first_name, c.last_name, c.phone, c.email, c.address, c.postal_code, c.city,
            v.license_plate, v.brand, v.model, v.year, v.vin, v.mileage
     FROM devis d
     JOIN clients c ON c.id = d.client_id
     LEFT JOIN vehicles v ON v.id = d.vehicle_id
     WHERE d.id = ?",
    [$id]
);
if (!$doc) {
    /* Document inexistant (id erroné, ou devis supprimé) : on renvoie
       l'utilisateur vers la liste avec un message clair, au lieu de lui
       afficher une page blanche « Not found » qui ressemble à un plantage. */
    flash('danger', t('error.not_found'));
    redirect('admin/devis/index.php');
}
$items = fetch_doc_items('devis', $id);
$client = ['civility' => $doc['civility'], 'first_name' => $doc['first_name'], 'last_name' => $doc['last_name'],
           'phone' => $doc['phone'], 'email' => $doc['email'], 'address' => $doc['address'],
           'postal_code' => $doc['postal_code'], 'city' => $doc['city']];
$vehicle = $doc['vehicle_id'] ? [
    'license_plate' => $doc['license_plate'], 'brand' => $doc['brand'], 'model' => $doc['model'],
    'year' => $doc['year'], 'vin' => $doc['vin'], 'mileage' => $doc['mileage'],
] : null;

$pdf = build_document_pdf($doc, $client, $vehicle, $items, 'devis');
$pdf->send($doc['quote_number'] . '.pdf');
