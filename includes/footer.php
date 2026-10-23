<?php
/* Interdit l'accès direct à ce fichier (inclusion uniquement). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(403); exit('Accès direct interdit.'); }
?>
<!-- Fermeture : contenu -->
</div>
</main>
<div class="sidebar-backdrop" aria-hidden="true"></div>
</div><!-- /app-wrapper -->

<!-- Modale de confirmation générique -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" id="confirmForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="confirm_action" id="confirmAction" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="confirmTitle"><i class="bi bi-question-circle me-2"></i><?= e(t('common.delete_confirm_title')) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="confirmMsg"><?= e(t('common.delete_confirm_msg')) ?></div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
          <button type="submit" class="btn btn-danger" id="confirmBtn"><i class="bi bi-check-lg me-1"></i><?= e(t('common.confirm')) ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/chat_widget.php'; ?>

<script src="<?= e(asset_url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/app.js')) ?>"></script>
</body>
</html>
