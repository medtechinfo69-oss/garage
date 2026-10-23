<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$doc = fetch_one(
    "SELECT d.*, c.first_name, c.last_name, c.civility, c.phone, c.email, c.address, c.postal_code, c.city,
            v.license_plate, v.brand, v.model, v.year, v.vin, v.mileage
     FROM devis d
     JOIN clients c ON c.id = d.client_id
     LEFT JOIN vehicles v ON v.id = d.vehicle_id
     WHERE d.id = ?",
    [$id]
);
if (!$doc) {
    flash('danger', t('error.not_found'));
    redirect('admin/devis/index.php');
}
$items = fetch_doc_items('devis', $id);
$client = ['id' => (int) $doc['client_id'], 'civility' => $doc['civility'], 'first_name' => $doc['first_name'], 'last_name' => $doc['last_name']];
$vehicle = $doc['vehicle_id'] ? [
    'license_plate' => $doc['license_plate'], 'brand' => $doc['brand'], 'model' => $doc['model'],
    'year' => $doc['year'], 'vin' => $doc['vin'], 'mileage' => $doc['mileage'],
] : null;

/* ---------- POST : changement de statut ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'status') {
    verify_csrf();
    $st = (string) ($_POST['status'] ?? '');
    if (in_array($st, ['brouillon', 'envoye', 'accepte', 'refuse'], true)) {
        execute("UPDATE devis SET status = ? WHERE id = ?", [$st, $id]);
        log_activity('devis_status', 'devis', $id, t('log.devis_updated', $doc['quote_number']) . ' → ' . $st);
        flash('success', t('flash.updated'));
    }
    redirect('admin/devis/view.php?id=' . $id);
}

$cur = currency();
$pageTitle = $doc['quote_number'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title no-print">
  <div>
    <h1><i class="bi bi-file-earmark-text me-2 text-danger"></i><?= e(t('devis.view_title')) ?> <?= e($doc['quote_number']) ?>
      <?= doc_status_badge('devis', $doc['status']) ?>
    </h1>
    <div class="subtitle">
      <?= e(trim($doc['first_name'] . ' ' . $doc['last_name'])) ?>
      <?= $doc['license_plate'] ? ' · ' . e($doc['license_plate']) : '' ?>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <button onclick="window.print()" class="btn btn-secondary"><i class="bi bi-printer me-1"></i><?= e(t('common.print')) ?></button>
    <a href="pdf.php?id=<?= (int) $doc['id'] ?>" class="btn btn-dark" target="_blank"><i class="bi bi-file-earmark-pdf me-1"></i><?= e(t('common.download_pdf')) ?></a>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#emailModal"><i class="bi bi-envelope me-1"></i><?= e(t('common.send_email')) ?></button>
    <?php if (in_array($doc['status'], ['accepte', 'envoye', 'brouillon'], true)): ?>
      <a href="../factures/form.php?devis_id=<?= (int) $doc['id'] ?>" class="btn btn-success"><i class="bi bi-receipt me-1"></i><?= e(t('devis.create_invoice')) ?></a>
    <?php endif; ?>
    <form method="post" class="d-flex gap-2">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="status">
      <select name="status" class="form-select form-select-sm" style="width:auto;">
        <?php foreach (['brouillon', 'envoye', 'accepte', 'refuse'] as $st): ?>
          <option value="<?= $st ?>" <?= $doc['status'] === $st ? 'selected' : '' ?>><?= e(t('devis.' . $st)) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-outline-primary"><?= e(t('devis.change_status')) ?></button>
    </form>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i><?= e(t('common.back')) ?></a>
  </div>
</div>

<!-- ================= DOCUMENT IMPRIMABLE ================= -->
<div class="doc-sheet">
  <div class="d-flex justify-content-between align-items-start mb-4">
    <div>
      <img src="<?= e(asset_url('assets/images/logo.png')) ?>" alt="PROS AUTO" class="doc-logo">
    </div>
    <div class="text-end">
      <h2 class="fw-bold mb-0 text-uppercase" style="letter-spacing:2px;color:#1b2430;"><?= e(t('pdf.devis')) ?></h2>
      <div class="fw-bold fs-5"><?= e($doc['quote_number']) ?></div>
      <div class="small text-muted"><?= e(t('pdf.date')) ?> : <strong><?= fmt_date($doc['devis_date']) ?></strong></div>
      <?php if ($doc['valid_until']): ?>
        <div class="small text-muted"><?= e(t('pdf.valid_until')) ?> : <strong><?= fmt_date($doc['valid_until']) ?></strong></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-6">
      <div class="doc-block">
        <h6><?= e(t('pdf.client')) ?></h6>
        <div class="fw-semibold"><?= e(civility_label($doc['civility']) . ' ' . trim($doc['first_name'] . ' ' . $doc['last_name'])) ?></div>
        <div class="small"><?= e($doc['address'] ?? '') ?></div>
        <div class="small"><?= e(trim($doc['postal_code'] . ' ' . $doc['city'])) ?></div>
        <div class="small"><?= e($doc['phone'] ?? '') ?> <?= $doc['email'] ? '· ' . e($doc['email']) : '' ?></div>
      </div>
    </div>
    <?php if ($vehicle): ?>
      <div class="col-md-6">
        <div class="doc-block">
          <h6><?= e(t('pdf.vehicle')) ?></h6>
          <div class="fw-semibold"><?= e(trim($vehicle['brand'] . ' ' . $vehicle['model'])) ?> <?= e($vehicle['year'] ?? '') ?></div>
          <div class="small"><?= e(t('common.plate')) ?> : <strong><?= e($vehicle['license_plate']) ?></strong></div>
          <?php if ($vehicle['vin']): ?><div class="small"><?= e(t('common.vin')) ?> : <?= e($vehicle['vin']) ?></div><?php endif; ?>
          <?php if ($vehicle['mileage'] !== null): ?><div class="small"><?= e(t('common.mileage')) ?> : <?= number_format((float) $vehicle['mileage']) ?> km</div><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <table class="doc-table">
    <thead>
      <tr>
        <th style="width:40px;"><?= e(t('pdf.no')) ?></th>
        <th><?= e(t('pdf.description')) ?></th>
        <th style="width:60px;" class="text-end"><?= e(t('pdf.qty')) ?></th>
        <th style="width:100px;" class="text-end"><?= e(t('pdf.unit_price')) ?> (<?= e($cur) ?>)</th>
        <th style="width:110px;" class="text-end"><?= e(t('common.total_ht')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$items): ?>
        <tr><td colspan="6" class="text-center text-muted"><?= e(t('common.no_results')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($items as $i => $it): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= e($it['description']) ?></td>
          <td class="text-end"><?= num_short((float) $it['quantity']) ?></td>
          <td class="text-end"><?= num_short((float) $it['unit_price']) ?></td>
          <td class="text-end fw-semibold"><?= money((float) $it['line_total']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="doc-totals-box mt-3">
    <div class="tot-row"><span class="text-muted"><?= e(t('common.total_ht')) ?></span><span class="fw-semibold"><?= money((float) $doc['total_ht']) ?></span></div>
    <div class="tot-row"><span class="text-muted"><?= e(t('common.total_tva')) ?> (19 %)</span><span class="fw-semibold"><?= money((float) $doc['total_tva']) ?></span></div>
    <div class="ttc-row d-flex justify-content-between"><span><?= e(t('common.total_ttc')) ?></span><span><?= money((float) $doc['total_ttc']) ?></span></div>
  </div>

  <?php if ($doc['notes']): ?>
    <div class="mt-4">
      <h6 class="small text-uppercase text-muted" style="letter-spacing:1px;"><?= e(t('pdf.notes')) ?></h6>
      <div class="small"><?= nl2br(e($doc['notes'])) ?></div>
    </div>
  <?php endif; ?>

  <div class="d-flex justify-content-end">
    <div class="doc-sign" style="width:260px; text-align:center;">
      <?= e(t('pdf.signature')) ?>
    </div>
  </div>
</div>
<!-- ================= /DOCUMENT ================= -->

<!-- Modale e-mail -->
<div class="modal fade" id="emailModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="send.php">
        <?= csrf_field() ?>
        <input type="hidden" name="devis_id" value="<?= (int) $doc['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-envelope me-2"></i><?= e(t('common.send_email')) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label" for="to_email"><?= e(t('devis.to_email')) ?></label>
            <input type="email" class="form-control" id="to_email" name="to_email" value="<?= e($doc['email'] ?? '') ?>" required>
          </div>
          <div class="mb-2">
            <label class="form-label" for="message"><?= e(t('devis.message')) ?></label>
            <textarea class="form-control" id="message" name="message" rows="3"><?= e(t('email.devis_intro', $doc['quote_number'], $doc['license_plate'] ?? '—')) ?></textarea>
          </div>
          <div class="form-text"><i class="bi bi-paperclip me-1"></i><?= e(t('devis.pdf_attached', $doc['quote_number'])) ?></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i><?= e(t('devis.send')) ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
