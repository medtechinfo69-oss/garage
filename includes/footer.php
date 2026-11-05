<?php
/* Interdit l'accès direct à ce fichier (inclusion uniquement). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(403); exit('Accès direct interdit.'); }
?>
<!-- Fermeture : contenu -->
</div>
</main>
<div class="sidebar-backdrop" aria-hidden="true"></div>
</div><!-- /app-wrapper -->

<!-- Template popup de confirmation -->
<div class="confirm-popup template" id="confirmPopupTemplate" style="display:none;" aria-hidden="true">
  <div class="card">
    <div class="icon"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></div>
    <h2 id="confirmPopupTitle"></h2>
    <p id="confirmPopupMessage"></p>
    <div class="confirm-note">Cette action sera appliquée immédiatement.</div>
    <div class="confirm-actions">
      <button type="button" class="btn btn-cancel" data-confirm-cancel>Annuler</button>
      <button type="button" class="btn btn-confirm" data-confirm-ok>Accepter</button>
    </div>
  </div>
</div>

<!-- Template popup danger (suppression) -->
<div class="confirm-popup-danger template" id="confirmPopupDangerTemplate" style="display:none;" aria-hidden="true">
  <div class="card">
    <div class="icon"><i class="bi bi-trash3-fill" aria-hidden="true"></i></div>
    <h2 id="confirmPopupTitle"></h2>
    <p id="confirmPopupMessage"></p>
    <div class="confirm-note">Cette action est immédiate et irréversible.</div>
    <div class="confirm-actions">
      <button type="button" class="btn btn-cancel" data-confirm-cancel>Annuler</button>
      <button type="button" class="btn btn-confirm" data-confirm-ok>Accepter</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/chat_widget.php'; ?>
<!-- Bootstrap + app.js sont chargées plus haut dans <head> (defer) -->
</body>
</html>
