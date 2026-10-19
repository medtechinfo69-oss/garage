<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

require_once __DIR__ . '/../../includes/doc_pdf.php';

$fromDevisId = (int) ($_GET['devis_id'] ?? 0);
$fromDevis = null;
if ($fromDevisId) {
    $fromDevis = fetch_one("SELECT * FROM devis WHERE id = ?", [$fromDevisId]);
    if ($fromDevis && !in_array($fromDevis['status'], ['accepte', 'envoye', 'brouillon'], true)) {
        flash('danger', t('error.devis_not_acceptable'));
        redirect('admin/devis/view.php?id=' . $fromDevisId);
    }
}

$clients = fetch_all("SELECT id, first_name, last_name FROM clients ORDER BY last_name, first_name");
$allVehicles = fetch_all("SELECT id, client_id, license_plate, brand, model FROM vehicles ORDER BY license_plate");
$clientVehiclesData = [];
foreach ($allVehicles as $v) {
    $clientVehiclesData[$v['client_id']][] = ['id' => (int) $v['id'], 'label' => $v['license_plate'] . ' — ' . trim($v['brand'] . ' ' . $v['model'])];
}

$old = [
    'invoice_number' => next_doc_number('facture'),
    'client_id' => $fromDevis ? (int) $fromDevis['client_id'] : 0,
    'vehicle_id' => $fromDevis ? (int) $fromDevis['vehicle_id'] : 0,
    'invoice_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+15 days')),
    'doc_lang' => current_lang(),
    'notes' => '',
];
if ($fromDevis) {
    $old['doc_lang'] = $fromDevis['doc_lang'];
    $old['notes'] = (string) $fromDevis['notes'];
}
$items = $fromDevis ? fetch_doc_items('devis', $fromDevisId) : [];
foreach ($items as &$item) {
    $item['discount_pct'] = 0;
    $item['tva_rate'] = 19.0;
}
unset($item);

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    foreach ($old as $k => $v) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $items = read_doc_items($_POST, 'item');
    foreach ($items as &$item) {
        $item['discount_pct'] = 0;
        $item['tva_rate'] = 19.0;
    }
    unset($item);

    if ($old['client_id'] === '' || !fetch_one("SELECT id FROM clients WHERE id = ?", [(int) $old['client_id']])) {
        $errors[] = t('error.required') . ' : ' . t('devis.client');
    }
    if ($old['vehicle_id'] !== '') {
        $veh = fetch_one("SELECT id, client_id FROM vehicles WHERE id = ?", [(int) $old['vehicle_id']]);
        if (!$veh || (int) $veh['client_id'] !== (int) $old['client_id']) {
            $old['vehicle_id'] = '';
        }
    } else {
        $old['vehicle_id'] = '';
    }
    if (!validate_doc_number('facture', $old['invoice_number'])) {
        $errors[] = fetch_one("SELECT COUNT(*) c FROM factures WHERE invoice_number = ?", [$old['invoice_number']])['c']
            ? t('error.unique_doc_number') : t('error.invalid_doc_number');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['invoice_date'])) {
        $old['invoice_date'] = date('Y-m-d');
    }
    if ($old['due_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['due_date'])) {
        $old['due_date'] = '';
    }
    if (!in_array($old['doc_lang'], ['fr', 'en'], true)) {
        $old['doc_lang'] = 'fr';
    }
    if (!$items) {
        $errors[] = t('error.no_items');
    }

    if (!$errors) {
        execute(
            "INSERT INTO factures (invoice_number, devis_id, client_id, vehicle_id, invoice_date, due_date, status, doc_lang, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)",
            [
                $old['invoice_number'], $fromDevisId ?: null, (int) $old['client_id'],
                $old['vehicle_id'] !== '' ? (int) $old['vehicle_id'] : null,
                $old['invoice_date'], $old['due_date'] !== '' ? $old['due_date'] : null,
                'brouillon', $old['doc_lang'], $old['notes'] !== '' ? $old['notes'] : null, current_user_id(),
            ]
        );
        $factureId = last_insert_id();
        save_doc_items('facture', $factureId, $items);
        if ($fromDevisId) {
            execute("UPDATE devis SET status = 'facture' WHERE id = ?", [$fromDevisId]);
        }
        $client = fetch_one("SELECT * FROM clients WHERE id = ?", [(int) $old['client_id']]);
        log_activity('facture_created', 'factures', $factureId, t('log.facture_created', $old['invoice_number'], client_name($client)));
        flash('success', t('flash.saved'));
        redirect('admin/factures/view.php?id=' . $factureId);
    }
}

$pageTitle = t('facture.add');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-receipt me-2 text-danger"></i><?= e(t('facture.add')) ?></h1>
    <?php if ($fromDevis): ?>
      <div class="subtitle"><?= e(sprintf(t('facture.from_devis'), e($fromDevis['quote_number']))) ?></div>
    <?php endif; ?>
  </div>
  <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i><?= e(t('common.back')) ?></a>
</div>

<?php if ($errors): ?>
  <?= banner('danger', '', t('common.form_errors'), $errors) ?>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="row g-3 mb-4">
        <div class="col-md-3">
          <label class="form-label required" for="invoice_number"><?= e(t('facture.number')) ?></label>
          <input type="text" class="form-control" id="invoice_number" name="invoice_number" value="<?= e($old['invoice_number']) ?>" maxlength="30" required>
          <div class="form-text">FAC-AAAA-NNNN</div>
        </div>
        <div class="col-md-4">
          <label class="form-label required" for="docClient"><?= e(t('devis.client')) ?></label>
          <select class="form-select" id="docClient" name="client_id" required>
            <option value=""><?= e(t('common.all')) ?>…</option>
            <?php foreach ($clients as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= (int) $old['client_id'] === (int) $c['id'] ? 'selected' : '' ?>>
                <?= e(trim($c['first_name'] . ' ' . $c['last_name'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label" for="docVehicle"><?= e(t('devis.vehicle')) ?></label>
          <select class="form-select" id="docVehicle" name="vehicle_id" data-selected="<?= e($old['vehicle_id']) ?>">
            <option value="">—</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label" for="doc_lang"><?= e(t('common.language_doc')) ?></label>
          <select class="form-select" id="doc_lang" name="doc_lang">
            <option value="fr" <?= $old['doc_lang'] === 'fr' ? 'selected' : '' ?>>Français</option>
            <option value="en" <?= $old['doc_lang'] === 'en' ? 'selected' : '' ?>>English</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label required" for="invoice_date"><?= e(t('facture.date')) ?></label>
          <input type="date" class="form-control" id="invoice_date" name="invoice_date" value="<?= e($old['invoice_date']) ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label" for="due_date"><?= e(t('facture.due_date')) ?></label>
          <input type="date" class="form-control" id="due_date" name="due_date" value="<?= e($old['due_date']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="notes"><?= e(t('common.notes')) ?></label>
          <input type="text" class="form-control" id="notes" name="notes" value="<?= e($old['notes']) ?>">
        </div>
      </div>

      <h6 class="fw-bold mb-2"><i class="bi bi-list-ul me-1"></i><?= e(t('common.description')) ?>s</h6>
      <div id="docLines" class="doc-lines">
        <table class="table align-middle">
          <thead>
            <tr>
              <th style="min-width:260px;"><?= e(t('devis.line_desc')) ?></th>
              <th style="width:90px;"><?= e(t('common.quantity')) ?></th>
              <th style="width:120px;"><?= e(t('common.unit_price')) ?> (HT)</th>
              <th style="width:130px;" class="text-end"><?= e(t('common.total_ht')) ?></th>
              <th style="width:46px;"></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-outline-primary mb-3" onclick="docAddLine()"><i class="bi bi-plus-lg me-1"></i><?= e(t('common.add')) ?></button>

      <div id="docTotals" class="doc-totals mb-4">
        <div class="total-row"><span class="text-muted"><?= e(t('common.total_ht')) ?></span><strong id="totalHt">0.00</strong></div>
        <div class="total-row"><span class="text-muted"><?= e(t('common.total_tva')) ?> (19 %)</span><strong id="totalTva">0.00</strong></div>
        <div class="total-row total-ttc"><span><?= e(t('common.total_ttc')) ?></span><strong id="totalTtc">0.00</strong></div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-danger"><i class="bi bi-check-lg me-1"></i><?= e(t('common.save')) ?></button>
        <a href="index.php" class="btn btn-secondary"><?= e(t('common.cancel')) ?></a>
      </div>
    </form>
  </div>
</div>

<template id="docLineTemplate">
  <tr>
    <td><input type="text" class="form-control" data-field="desc" name="item_desc[__IDX__]" placeholder="<?= e(t('devis.line_desc')) ?>" maxlength="255"></td>
    <td><input type="number" step="0.01" min="0" class="form-control" data-field="qty" name="item_qty[__IDX__]" value="1"></td>
    <td><input type="number" step="0.01" min="0" class="form-control text-end" data-field="price" name="item_price[__IDX__]" value="0"></td>
    <td class="text-end fw-semibold" data-field="line_total">0.00</td>
    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger doc-line-remove"><i class="bi bi-x-lg"></i></button></td>
  </tr>
</template>
<script id="clientVehiclesData" type="application/json"><?= json_encode($clientVehiclesData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
  document.body.dataset.defaultTva = 19;
  document.body.dataset.currency = <?= json_encode(currency()) ?>;
  /* app.js est chargé en pied de page : il appelle window.docInitLines
     dès que docAddLine() / docRecalc() sont définis (voir assets/js/app.js). */
  var docLinesInitDone = false;
  window.docInitLines = function () {
    if (docLinesInitDone) return;
    docLinesInitDone = true;
    <?php if ($items): ?>
      const items = <?= json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      items.forEach(function (it) { docAddLine(it); });
    <?php else: ?>
      docAddLine();
    <?php endif; ?>
  };

  document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.docAddLine === 'function') { window.docInitLines(); }
  });
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
