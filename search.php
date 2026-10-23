<?php
/**
 * Recherche globale : clients, véhicules, mécaniciens, utilisateurs.
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

$q = trim((string) ($_GET['q'] ?? ''));
$like = '%' . $q . '%';

$clients = $vehicles = $mechanics = $users = [];
$isMech = is_mechanic();
$myMechId = $isMech ? (int) current_user()['mechanic_id'] : 0;
if (mb_strlen($q) >= 2) {
    if (!$isMech) {
        $clients = fetch_all(
            "SELECT c.*, (SELECT COUNT(*) FROM vehicles v WHERE v.client_id = c.id) nb_veh
             FROM clients c
             WHERE CONCAT(c.first_name,' ',c.last_name) LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? OR c.phone2 LIKE ? OR c.email LIKE ?
             ORDER BY c.last_name LIMIT 10",
            [$like, $like, $like, $like, $like]
        );
        $vehicles = fetch_all(
            "SELECT v.*, c.first_name, c.last_name, m.full_name mech_name
             FROM vehicles v
             JOIN clients c ON c.id = v.client_id
             LEFT JOIN mechanics m ON m.id = v.mechanic_id
             WHERE v.license_plate LIKE ? OR v.vin LIKE ? OR v.brand LIKE ? OR v.model LIKE ?
             ORDER BY v.created_at DESC LIMIT 10",
            [$like, $like, $like, $like]
        );
        $mechanics = fetch_all(
            "SELECT m.* FROM mechanics m WHERE m.full_name LIKE ? OR m.phone LIKE ? OR m.email LIKE ? ORDER BY m.full_name LIMIT 10",
            [$like, $like, $like]
        );
    } else {
        // Mécanicien : uniquement ses propres véhicules
        $vehicles = fetch_all(
            "SELECT v.*, c.first_name, c.last_name, m.full_name mech_name
             FROM vehicles v
             JOIN clients c ON c.id = v.client_id
             LEFT JOIN mechanics m ON m.id = v.mechanic_id
             WHERE v.mechanic_id = ? AND (v.license_plate LIKE ? OR v.vin LIKE ? OR v.brand LIKE ? OR v.model LIKE ?)
             ORDER BY v.created_at DESC LIMIT 10",
            [$myMechId, $like, $like, $like, $like]
        );
    }
    if (is_admin()) {
        $users = fetch_all(
            "SELECT u.*, m.full_name mech_name FROM users u LEFT JOIN mechanics m ON m.id = u.mechanic_id
             WHERE u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ? LIMIT 10",
            [$like, $like, $like]
        );
    }
}

$total = count($clients) + count($vehicles) + count($mechanics) + count($users);
$pageTitle = t('search.title');
require __DIR__ . '/includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-search me-2 text-danger"></i><?= e(t('search.title')) ?></h1>
  </div>
</div>

<!-- Zone de recherche mise en avant -->
<div class="search-hero">
  <form class="search-hero-form" role="search" action="<?= e(app_url('search.php')) ?>" method="get">
    <div class="search-hero-field">
      <i class="bi bi-search"></i>
      <input type="search" name="q" value="<?= e($q) ?>" minlength="2" autofocus autocomplete="off"
             placeholder="<?= e(t('search.placeholder')) ?>" aria-label="<?= e(t('common.search')) ?>">
      <?php if ($q !== ''): ?>
        <a class="search-hero-clear" href="<?= e(app_url('search.php')) ?>"
           title="<?= e(t('search.clear')) ?>" aria-label="<?= e(t('search.clear')) ?>">
          <i class="bi bi-x-lg"></i>
        </a>
      <?php endif; ?>
    </div>
    <button type="submit" class="btn search-hero-btn">
      <i class="bi bi-search me-1"></i><?= e(t('common.search')) ?>
    </button>
  </form>
  <?php if ($q !== ''): ?>
    <div class="search-hero-meta">
      <i class="bi bi-funnel me-1"></i>
      <?= sprintf(t('search.results_for'), '<strong>' . e($q) . '</strong>', $total) ?>
    </div>
  <?php endif; ?>
</div>

<?php if (mb_strlen($q) < 2): ?>
  <div class="empty-state">
    <i class="bi bi-keyboard"></i>
    <p><?= e(t('search.min_chars')) ?></p>
  </div>
<?php elseif ($total === 0): ?>
  <div class="empty-state">
    <i class="bi bi-emoji-neutral"></i>
    <p><?= e(sprintf(t('search.no_results'), e($q))) ?></p>
    <p class="empty-state-hint"><?= e(t('search.placeholder')) ?></p>
  </div>
<?php else: ?>
  <div class="row g-3">
    <?php if (!$isMech): ?>
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-people me-2"></i><?= e(t('search.clients')) ?> (<?= count($clients) ?>)</div>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <?php foreach ($clients as $c): ?>
              <tr class="cursor-pointer" onclick="location.href='<?= e(app_url('admin/clients/view.php?id=' . (int) $c['id'])) ?>'">
                <td class="fw-semibold"><?= e(civility_label($c['civility']) . ' ' . trim($c['first_name'] . ' ' . $c['last_name'])) ?></td>
                <td class="small"><?= e($c['phone'] ?: '') ?> <?= $c['email'] ? '· ' . e($c['email']) : '' ?></td>
                <td class="text-end"><span class="badge text-bg-light border"><?= (int) $c['nb_veh'] ?> <i class="bi bi-car-front"></i></span></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <div class="<?= $isMech ? 'col-12' : 'col-lg-6' ?>">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-car-front me-2"></i><?= e($isMech ? t('mech.my_vehicles') : t('search.vehicles')) ?> (<?= count($vehicles) ?>)</div>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <?php foreach ($vehicles as $v): ?>
              <?php $vehLink = $isMech ? 'mechanic/vehicle_view.php' : 'admin/vehicles/view.php'; ?>
              <tr class="cursor-pointer" onclick="location.href='<?= e(app_url($vehLink . '?id=' . (int) $v['id'])) ?>'">
                <td class="fw-semibold"><?= e($v['license_plate']) ?></td>
                <td class="small"><?= e(trim($v['brand'] . ' ' . $v['model'])) ?><?= $v['year'] ? ' ' . e($v['year']) : '' ?></td>
                <td class="small"><?= e(trim($v['first_name'] . ' ' . $v['last_name'])) ?></td>
                <td class="text-end"><?= status_badge($v['status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-person-gear me-2"></i><?= e(t('search.mechanics')) ?> (<?= count($mechanics) ?>)</div>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <?php foreach ($mechanics as $m): ?>
              <tr>
                <td class="fw-semibold"><?= e($m['full_name']) ?></td>
                <td class="small"><?= e($m['phone'] ?: '') ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
      </div>
    </div>
    <?php if ($users): ?>
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-person-badge me-2"></i><?= e(t('search.users')) ?> (<?= count($users) ?>)</div>
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <?php foreach ($users as $usr): ?>
                <tr>
                  <td class="fw-semibold"><?= e($usr['username']) ?></td>
                  <td class="small"><?= e($usr['full_name']) ?></td>
                  <td><?= role_badge($usr['role']) ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
