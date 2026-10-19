<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$doc = fetch_one(
    "SELECT f.*, d.quote_number, c.first_name, c.last_name, c.civility, c.phone, c.email, c.address, c.postal_code, c.city,
            v.license_plate, v.brand, v.model, v.year, v.vin, v.mileage
     FROM factures f
     LEFT JOIN devis d ON d.id = f.devis_id
     JOIN clients c ON c.id = f.client_id
     LEFT JOIN vehicles v ON v.id = f.vehicle_id
     WHERE f.id = ?",
    [$id]
);
if (!$doc) {
    flash('danger', t('error.not_found'));
    redirect('admin/factures/index.php');
}
$items = fetch_doc_items('facture', $id);
$balance = max(0, (float) $doc['total_ttc'] - (float) $doc['amount_paid']);

/* ---------- POST ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'status') {
        $st = (string) ($_POST['status'] ?? '');
        if (in_array($st, ['brouillon', 'envoyee', 'payee', 'partiellement_payee', 'annulee'], true)) {
            execute("UPDATE factures SET status = ? WHERE id = ?", [$st, $id]);
            log_activity('facture_status', 'factures', $id, t('log.facture_updated', $doc['invoice_number']) . ' → ' . $st);
            flash('success', t('flash.updated'));
        }
        redirect('admin/factures/view.php?id=' . $id);
    }
    if ($action === 'payment') {
        $amount = max(0, (float) str_replace(',', '.', (string) ($_POST['amount'] ?? 0)));
        if ($amount > (float) $doc['total_ttc']) {
            $amount = (float) $doc['total_ttc'];
        }
        $newPaid = (float) $doc['amount_paid'] + $amount;
        /* Encaissement partiel : le statut reste inchangé (pas de
           « partiellement payée »). Seul un solde complet passe à « Payée ». */
        $status = $newPaid >= (float) $doc['total_ttc'] - 0.001 ? 'payee' : $doc['status'];
        execute("UPDATE factures SET amount_paid = ?, status = ? WHERE id = ?", [$newPaid, $status, $id]);
        log_activity('facture_payment', 'factures', $id, t('facture.amount_paid') . ' : ' . $amount . ' ' . currency());
        flash('success', t('flash.payment_updated'));
        redirect('admin/factures/view.php?id=' . $id);
    }
}

$cur = currency();
$pageTitle = $doc['invoice_number'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title no-print">
  <div>
    <h1><i class="bi bi-receipt me-2 text-danger"></i><?= e(t('facture.view_title')) ?> <?= e($doc['invoice_number']) ?>
      <?= doc_status_badge('facture', $doc['status']) ?>
    </h1>
    <div class="subtitle">
      <?= e(trim($doc['first_name'] . ' ' . $doc['last_name'])) ?>
      <?= $doc['license_plate'] ? ' · ' . e($doc['license_plate']) : '' ?>
      <?= $doc['quote_number'] ? ' · ' . e(t('facture.devis_ref')) . ' ' . e($doc['quote_number']) : '' ?>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap action-row">
    <div class="action-main">
      <button type="button" onclick="window.print()" class="btn btn-secondary"><i class="bi bi-printer me-1"></i><span class="btn-label"><?= e(t('common.print')) ?></span></button>
      <a href="pdf.php?id=<?= (int) $doc['id'] ?>" class="btn btn-dark" target="_blank"><i class="bi bi-file-earmark-pdf me-1"></i><span class="btn-label"><?= e(t('common.download_pdf')) ?></span></a>
      <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#emailModal"><i class="bi bi-envelope me-1"></i><span class="btn-label"><?= e(t('common.send_email')) ?></span></button>
      <?php if (!in_array($doc['status'], ['payee', 'annulee'], true)): ?>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#payModal"><i class="bi bi-cash-coin me-1"></i><span class="btn-label"><?= e(t('facture.collect')) ?></span></button>
      <?php endif; ?>
    </div>
    <div class="d-flex align-items-center gap-2 action-inline">
      <form method="post" class="d-flex align-items-center gap-2 action-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="status">
        <select name="status" class="form-select status-select" aria-label="<?= e(t('facture.status')) ?>">
          <?php $allowedStatuses = ['brouillon', 'payee', 'annulee']; ?>
          <?php if (!in_array($doc['status'], $allowedStatuses, true)): ?>
            <option value="<?= e($doc['status']) ?>" selected><?= e(t('facture.' . $doc['status'])) ?></option>
          <?php endif; ?>
          <?php foreach ($allowedStatuses as $st): ?>
            <option value="<?= $st ?>" <?= $doc['status'] === $st ? 'selected' : '' ?>><?= e(t('facture.' . $st)) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat me-1"></i><span class="btn-label"><?= e(t('devis.change_status')) ?></span></button>
      </form>
      <a href="index.php" class="btn btn-outline-secondary action-back"><i class="bi bi-arrow-left me-1"></i><span class="btn-label"><?= e(t('common.back')) ?></span></a>
    </div>
  </div>
</div>

<!-- ================= DOCUMENT IMPRIMABLE ================= -->
<div class="doc-sheet">
  <div class="d-flex justify-content-between align-items-start mb-4">
    <div>
      <img src="<?= e(app_url('assets/images/logo.png')) ?>" alt="PROS AUTO" class="doc-logo">
    </div>
    <div class="text-end">
      <h2 class="fw-bold mb-0 text-uppercase" style="letter-spacing:2px;color:#1b2430;"><?= e(t('pdf.facture')) ?></h2>
      <div class="fw-bold fs-5"><?= e($doc['invoice_number']) ?></div>
      <?php if ($doc['quote_number']): ?>
        <div class="small text-muted"><?= e(t('pdf.ref_devis', $doc['quote_number'])) ?></div>
      <?php endif; ?>
      <div class="small text-muted"><?= e(t('pdf.date')) ?> : <strong><?= fmt_date($doc['invoice_date']) ?></strong></div>
      <?php if ($doc['due_date']): ?>
        <div class="small text-muted"><?= e(t('pdf.due_date')) ?> : <strong><?= fmt_date($doc['due_date']) ?></strong></div>
      <?php endif; ?>
      <?php if ($doc['status'] === 'payee'): ?>
        <span class="badge text-bg-success fs-6 mt-1"><?= e(t('pdf.status_paid')) ?></span>
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
    <?php if ($doc['license_plate']): ?>
      <div class="col-md-6">
        <div class="doc-block">
          <h6><?= e(t('pdf.vehicle')) ?></h6>
          <div class="fw-semibold"><?= e(trim($doc['brand'] . ' ' . $doc['model'])) ?> <?= e($doc['year'] ?? '') ?></div>
          <div class="small"><?= e(t('common.plate')) ?> : <strong><?= e($doc['license_plate']) ?></strong></div>
          <?php if ($doc['vin']): ?><div class="small"><?= e(t('common.vin')) ?> : <?= e($doc['vin']) ?></div><?php endif; ?>
          <?php if ($doc['mileage'] !== null): ?><div class="small"><?= e(t('common.mileage')) ?> : <?= number_format((float) $doc['mileage']) ?> km</div><?php endif; ?>
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
        <th style="width:110px;" class="text-end"><?= e(t('pdf.total')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$items): ?>
        <tr><td colspan="5" class="text-center text-muted"><?= e(t('common.no_results')) ?></td></tr>
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
    <div class="tot-row"><span class="text-muted"><?= e(t('pdf.total')) ?></span><span class="fw-semibold"><?= money((float) $doc['total_ht']) ?></span></div>
     <div class="tot-row"><span class="text-muted"><?= e(t('pdf.tva_total')) ?> (19 %)</span><span class="fw-semibold"><?= money((float) $doc['total_tva']) ?></span></div>
    <div class="ttc-row d-flex justify-content-between"><span><?= e(t('pdf.ttc')) ?></span><span><?= money((float) $doc['total_ttc']) ?></span></div>
    <?php if ((float) $doc['amount_paid'] > 0): ?>
      <div class="tot-row mt-2"><span class="text-muted"><?= e(t('pdf.amount_paid')) ?></span><span>-<?= money((float) $doc['amount_paid']) ?></span></div>
      <div class="tot-row"><span class="fw-semibold"><?= e(t('pdf.balance_due')) ?></span><span class="fw-bold"><?= money($balance) ?></span></div>
    <?php endif; ?>
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
        <input type="hidden" name="facture_id" value="<?= (int) $doc['id'] ?>">
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
            <textarea class="form-control" id="message" name="message" rows="3"><?= e(t('email.facture_intro', $doc['invoice_number'], $doc['license_plate'] ?? '—')) ?></textarea>
          </div>
          <div class="form-text"><i class="bi bi-paperclip me-1"></i><?= e(t('facture.pdf_attached', $doc['invoice_number'])) ?></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i><?= e(t('devis.send')) ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modale encaissement -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="payment">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i><?= e(t('facture.collect_title')) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted"><?= e(t('facture.collect_msg')) ?></p>
          <div class="d-flex justify-content-between small mb-2">
            <span><?= e(t('common.total_ttc')) ?></span><strong><?= money((float) $doc['total_ttc']) ?></strong>
          </div>
          <div class="d-flex justify-content-between small mb-2">
            <span><?= e(t('facture.amount_paid')) ?></span><strong><?= money((float) $doc['amount_paid']) ?></strong>
          </div>
          <div class="d-flex justify-content-between small mb-3">
            <span><?= e(t('facture.balance')) ?></span><strong class="text-danger"><?= money($balance) ?></strong>
          </div>
          <label class="form-label" for="amount"><?= e(t('facture.amount_paid')) ?> (<?= e($cur) ?>)</label>
          <input type="number" step="0.01" min="0" max="<?= e(number_format($balance, 2, '.', '')) ?>" class="form-control" id="amount" name="amount" value="<?= e(number_format($balance, 2, '.', '')) ?>" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
          <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i><?= e(t('common.save')) ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
