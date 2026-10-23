/* ============================================================
   PROS AUTO — Scripts de l'application
   ============================================================ */
document.addEventListener('DOMContentLoaded', function () {
  var BS = window.bootstrap;

  /* ---------- Flash / bannières : disparition automatique ----------
     Pause le compte à rebours au survol pour laisser le temps de lire
     un message long ou de cliquer sur le lien d'action. */
  document.querySelectorAll('.flash-alert').forEach(function (el) {
    var timer = null;
    var start = function () {
      timer = setTimeout(function () {
        if (BS && BS.Alert) {
          BS.Alert.getOrCreateInstance(el).close();
        }
      }, 6000);
    };
    start();
    el.addEventListener('mouseenter', function () { clearTimeout(timer); });
    el.addEventListener('mouseleave', start);
  });

  /* ---------- Sidebar mobile ---------- */
  var sidebar = document.querySelector('.app-sidebar');
  var toggler = document.querySelector('.sidebar-toggler');
  var backdrop = document.querySelector('.sidebar-backdrop');
  if (toggler && sidebar) {
    var closeSidebar = function () { sidebar.classList.remove('open'); if (backdrop) backdrop.classList.remove('show'); };
    toggler.addEventListener('click', function (e) {
      e.stopPropagation();
      sidebar.classList.toggle('open');
      if (backdrop) backdrop.classList.toggle('show', sidebar.classList.contains('open'));
    });
    if (backdrop) backdrop.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeSidebar(); });
    sidebar.querySelectorAll('.nav-link').forEach(function (a) { a.addEventListener('click', closeSidebar); });
  }

  /* ---------- Sidebar pro : rail icônes + mémoire ---------- */
  var sidebarReduce = document.getElementById('sidebarReduce');
  if (sidebarReduce && sidebar) {
    var sidebarReduceIcon = sidebarReduce.querySelector('i');
    var reduceLabel = sidebarReduce.getAttribute('data-tip-reduce') || 'Reduce';
    var expandLabel = sidebarReduce.getAttribute('data-tip-expand') || 'Expand';
    var paintRail = function (collapsed) {
      if (sidebarReduceIcon) {
        sidebarReduceIcon.className = 'bi ' + (collapsed ? 'bi-chevron-double-right' : 'bi-chevron-double-left');
      }
      var label = collapsed ? expandLabel : reduceLabel;
      sidebarReduce.setAttribute('aria-label', label);
      sidebarReduce.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    };
    var setRail = function (collapsed, save) {
      sidebar.classList.toggle('sidebar-collapsed', collapsed);
      document.body.classList.toggle('rail-collapsed', collapsed);
      paintRail(collapsed);
      if (save !== false) {
        try { localStorage.setItem('sidebar-collapsed', collapsed ? '1' : '0'); } catch (e) {}
      }
    };
    var storedRail = null;
    try { storedRail = localStorage.getItem('sidebar-collapsed'); } catch (e) {}
    setRail(storedRail === '1', false);
    sidebarReduce.addEventListener('click', function () {
      setRail(!sidebar.classList.contains('sidebar-collapsed'));
    });
    /* Raccourci pro : [ replie / déplie le rail. */
    document.addEventListener('keydown', function (ev) {
      if ((ev.key === '[' || ev.key === ']' ) && !/INPUT|TEXTAREA|SELECT/.test((document.activeElement || {}).tagName || '')) {
        setRail(!sidebar.classList.contains('sidebar-collapsed'));
      }
    });

    /* Cool floating popup: follows the hovered icon, right side, only when collapsed. */
    var railTip = document.getElementById('rail-tip');
    if (!railTip) {
      railTip = document.createElement('div');
      railTip.id = 'rail-tip';
      railTip.setAttribute('role', 'tooltip');
      document.body.appendChild(railTip);
    }
    var railTipTimer = null;
    var showRailTip = function (el) {
      if (!sidebar.classList.contains('sidebar-collapsed')) { return; }
      if (window.innerWidth < 992) { return; }
      var label = el.getAttribute('data-tip');
      if (!label) { return; }
      railTip.textContent = label;
      railTip.classList.toggle('is-active', el.classList.contains('active'));
      var r = el.getBoundingClientRect();
      railTip.style.top = Math.max(8, (r.top + r.height / 2 - 19)) + 'px';
      railTip.style.left = (r.right + 12) + 'px';
      clearTimeout(railTipTimer);
      railTipTimer = setTimeout(function () { railTip.classList.add('show'); }, 90);
    };
    var hideRailTip = function () {
      clearTimeout(railTipTimer);
      railTip.classList.remove('show');
    };
    /* Hide the popup on scroll / resize so it never floats detached. */
    sidebar.addEventListener('scroll', hideRailTip, { passive: true });
    window.addEventListener('resize', hideRailTip, { passive: true });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { hideRailTip(); } });
    sidebar.querySelectorAll('.nav-link[data-tip]').forEach(function (el) {
      el.addEventListener('mouseenter', function () { showRailTip(el); });
      el.addEventListener('mouseleave', hideRailTip);
      el.addEventListener('focus', function () { showRailTip(el); });
      el.addEventListener('blur', hideRailTip);
      el.addEventListener('click', hideRailTip);
    });
  }

  /* ---------- Theme (dark / light) : icône seule ---------- */
  var themeToggle = document.getElementById('themeToggle');
  var themeIcon = document.querySelector('#themeToggle .theme-icon');
  var storedTheme = null;
  try { storedTheme = localStorage.getItem('theme'); } catch (e) {}
  var initialTheme = storedTheme || document.documentElement.getAttribute('data-theme') || (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem('theme', theme); } catch (e) {}
    var isLight = (theme === 'light');
    if (themeIcon) {
      themeIcon.className = 'bi theme-icon ' + (isLight ? 'bi-sun-fill' : 'bi-moon-stars-fill');
    }
    if (themeToggle) {
      themeToggle.classList.toggle('is-light', isLight);
    }
  }

  if (themeToggle) {
    themeToggle.addEventListener('click', function () {
      var current = document.documentElement.getAttribute('data-theme') || 'dark';
      applyTheme(current === 'dark' ? 'light' : 'dark');
    });
  }

  applyTheme(initialTheme);


  /* ---------- Dropdown notifications (clic) ---------- */
  var notifTrigger = document.getElementById('notifTrigger');
  var notifDrop = document.getElementById('notifDropdown');
  if (notifTrigger && notifDrop) {
    var setNotifState = function (open) {
      notifDrop.classList.toggle('open', open);
      notifDrop.setAttribute('aria-hidden', open ? 'false' : 'true');
      notifTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    notifTrigger.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      setNotifState(!notifDrop.classList.contains('open'));
    });
    document.addEventListener('click', function (ev) {
      if (!notifDrop.contains(ev.target) && !notifTrigger.contains(ev.target)) {
        setNotifState(false);
      }
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') setNotifState(false);
    });
    document.querySelectorAll('.notif-item').forEach(function (a) {
      a.addEventListener('click', function () { setNotifState(false); });
    });
  }

/* ---------- Fonctions de confirmation popup innovante ---------- */
function escapeConfirmHtml(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}

function showConfirmPopup(title, message, onConfirm, onCancel, okLabel, cancelLabel) {
  // Nettoyer tout popup existante (et considérer la précédente comme annulée)
  const existing = document.getElementById('confirmPopup');
  if (existing) {
    const prevCancel = window.__confirm_onCancel;
    existing.remove();
    window.__confirm_onConfirm = null;
    window.__confirm_onCancel = null;
    if (typeof prevCancel === 'function') { try { prevCancel(); } catch (e) {} }
  }

  const popupHtml = `
    <div class="confirm-popup" id="confirmPopup" role="dialog" aria-modal="true">
      <div class="card">
        <div class="icon"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i></div>
        <h2>${escapeConfirmHtml(title)}</h2>
        <p>${escapeConfirmHtml(message)}</p>
        <div class="confirm-actions">
          <button type="button" class="btn btn-cancel" data-confirm-cancel>${escapeConfirmHtml(cancelLabel || 'Annuler')}</button>
          <button type="button" class="btn btn-confirm" data-confirm-ok>${escapeConfirmHtml(okLabel || 'Confirmer')}</button>
        </div>
      </div>
    </div>
  `;
  document.body.insertAdjacentHTML('beforeend', popupHtml);

  // Stocker les callbacks pour y accéder depuis handleConfirm
  window.__confirm_onConfirm = onConfirm;
  window.__confirm_onCancel = onCancel;

  const popup = document.getElementById('confirmPopup');
  const okBtn = popup.querySelector('[data-confirm-ok]');
  const cancelBtn = popup.querySelector('[data-confirm-cancel]');
  if (okBtn) okBtn.addEventListener('click', handleConfirmConfirmer);
  if (cancelBtn) cancelBtn.addEventListener('click', handleConfirmAnnuler);
  // Clic sur le fond sombre = annuler
  popup.addEventListener('click', function (e) {
    if (e.target === popup) handleConfirmAnnuler();
  });
  if (okBtn) okBtn.focus();
}

function hideConfirmPopup() {
  const popup = document.getElementById('confirmPopup');
  if (popup) popup.remove();
  window.__confirm_onConfirm = null;
  window.__confirm_onCancel = null;
}

function handleConfirmConfirmer() {
  const onConfirm = window.__confirm_onConfirm;
  hideConfirmPopup();
  if (typeof onConfirm === 'function') {
    try { onConfirm(); } catch (e) { console.error(e); }
  }
}

function handleConfirmAnnuler() {
  const onCancel = window.__confirm_onCancel;
  hideConfirmPopup();
  if (typeof onCancel === 'function') {
    try { onCancel(); } catch (e) { console.error(e); }
  }
}

// Gestion de l Escape key pour fermer le popup (comme une annulation)
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && document.getElementById('confirmPopup')) {
    handleConfirmAnnuler();
  }
});

/* ---------- Remplacement de window.confirm ---------- */
// Remplacer window.confirm par notre popup design
window.confirm = function(msg) {
  return new Promise((resolve) => {
    showConfirmPopup(
      'Confirmation',
      msg,
      () => { resolve(true); },  // Valide
      () => { resolve(false); }  // Annule
    );
  });
};

// Auto-initialiser au chargement
document.addEventListener('DOMContentLoaded', () => {
  window.__confirm_onConfirm = null;
  window.__confirm_onCancel = null;
});

/* ---------- Modale de confirmation générique ----------
       Usage :  <form data-confirm="message" data-confirm-action="delete"
                       data-confirm-title="Titre" data-confirm-btn="btn-danger|btn-primary" ...>
    */
    var confirmModal = document.getElementById('confirmModal');
    var confirmForm = document.getElementById('confirmForm');
    if (confirmModal && confirmForm && BS && BS.Modal) {
      var modal = BS.Modal.getOrCreateInstance(confirmModal);
      document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          var msg = form.getAttribute('data-confirm') || document.getElementById('confirmMsg').textContent;
          var title = form.getAttribute('data-confirm-title') || '';
          var action = form.getAttribute('data-confirm-action') || '';
          var btnClass = form.getAttribute('data-confirm-btn') || 'btn-danger';
          document.getElementById('confirmMsg').textContent = msg;
          if (title) document.getElementById('confirmTitle').innerHTML = '<i class="bi bi-question-circle me-2"></i>' + title;
          document.getElementById('confirmAction').value = action;
          var btn = document.getElementById('confirmBtn');
          btn.className = 'btn ' + btnClass;

          /* Cible de soumission : celle du formulaire d'origine.
             ATTENTION : ne JAMAIS lire form.action — le formulaire de la
             modale contient un champ id="confirmAction", et le navigateur
             expose les éléments nommés comme propriétés du form :
             form.action renvoyait donc cet <input>, et la soumission
             partait vers « [object HTMLInputElement] » (404, suppression
             impossible). getAttribute('action') renvoie la vraie valeur
             ou null ; à défaut on poste sur la page courante. */
          var target = form.getAttribute('action') || window.location.href;
          /* Filet de sécurité : on n'accepte jamais une cible qui n'est pas
             une URL. Une property named-element retomberait ici. */
          if (!target || typeof target !== 'string' || target.charAt(0) === '[') {
            target = window.location.href;
          }
          confirmForm.setAttribute('action', target);
          confirmForm.setAttribute('method', 'post');

          // transfère les champs du formulaire d'origine dans la modale
          document.querySelectorAll('#confirmFields').forEach(function (n) { n.remove(); });
          var holder = document.createElement('div');
          holder.id = 'confirmFields';
          var fields = form.querySelectorAll('input, select, textarea');
          for (var i = 0; i < fields.length; i++) {
            var el = fields[i];
            if (!el.name) continue;
            var clone = document.createElement('input');
            clone.type = 'hidden';
            clone.name = el.name;
            clone.value = el.value;
            holder.appendChild(clone);
          }
          confirmForm.appendChild(holder);
          modal.show();
        });
      });
      confirmForm.addEventListener('submit', function () {
        var f = confirmForm;
        modal.hide();
        // soumission réelle après fermeture (requestSubmit respecte l'action)
        setTimeout(function () {
          if (typeof f.requestSubmit === 'function') { f.requestSubmit(); } else { f.submit(); }
        }, 250);
      });
    }

  /* ---------- Lignes de document (devis / facture) ---------- */
  var linesTable = document.getElementById('docLines');
  if (linesTable) {
    var tvaDefault = parseFloat(document.body.dataset.defaultTva || '19');
    var rowTemplate = document.getElementById('docLineTemplate');
    var totalsEl = document.getElementById('docTotals');
    var itemsCount = 0;

    window.docAddLine = function (data) {
      itemsCount++;
      var html = rowTemplate.innerHTML;
      html = html.replace(/__IDX__/g, String(itemsCount));
      html = html.replace(/__TVA__/g, String(tvaDefault));
      var row = document.createElement('tbody');
      row.innerHTML = html;
      /* Récupérer la ligne AVANT de l'insérer : appendChild déplace le
         nœud, donc row.firstElementChild serait null ensuite (les
         écouteurs input/change et le bouton supprimer n'étaient alors
         jamais attachés et les totaux ne se recalculaient pas). */
      var tr = row.firstElementChild;
      var tb = linesTable.querySelector('tbody');
      tb.appendChild(tr);
      if (data) {
        setRow(tr, data);
      }
      tr.querySelector('.doc-line-remove').addEventListener('click', function () {
        tr.remove();
        docRecalc();
      });
      tr.querySelectorAll('input, select').forEach(function (inp) {
        inp.addEventListener('input', docRecalc);
        inp.addEventListener('change', docRecalc);
      });
      docRecalc();
      return tr;
    };

    function setRow(tr, d) {
      if (d.description !== undefined) tr.querySelector('[data-field="desc"]').value = d.description;
      if (d.quantity !== undefined) tr.querySelector('[data-field="qty"]').value = d.quantity;
      if (d.unit_price !== undefined) tr.querySelector('[data-field="price"]').value = d.unit_price;
      var discountInput = tr.querySelector('[data-field="discount"]');
      if (discountInput && d.discount_pct !== undefined) discountInput.value = d.discount_pct;
      var tvaInput = tr.querySelector('[data-field="tva"]');
      if (tvaInput && d.tva_rate !== undefined) tvaInput.value = d.tva_rate;
    }

    window.docRecalc = function () {
      var ht = 0, tva = 0;
      linesTable.querySelectorAll('tbody tr').forEach(function (tr) {
        var qty = parseFloat(tr.querySelector('[data-field="qty"]').value) || 0;
        var price = parseFloat(tr.querySelector('[data-field="price"]').value) || 0;
        var lineHt;
        var lineTva;
        var discountInput = tr.querySelector('[data-field="discount"]');
        var tvaInput = tr.querySelector('[data-field="tva"]');
        if (discountInput) {
          var discount = Math.max(0, Math.min(100, parseFloat(discountInput.value) || 0));
          lineHt = qty * price * (1 - discount / 100);
        } else {
          lineHt = qty * price;
        }
        var rate = tvaInput ? (parseFloat(tvaInput.value) || 0) : tvaDefault;
        lineTva = lineHt * rate / 100;
        ht += lineHt;
        tva += lineTva;
        var cell = tr.querySelector('[data-field="line_total"]');
        if (cell) cell.textContent = fmtNum(lineHt);
      });
      if (totalsEl) {
        var set = function (id, v) { var n = document.getElementById(id); if (n) n.textContent = fmtNum(v); };
        set('totalHt', ht);
        set('totalTva', tva);
        set('totalTtc', ht + tva);
      }
    };

    function fmtNum(v) {
      var loc = document.body.dataset.locale || 'fr-FR';
      return v.toLocaleString(loc, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + (document.body.dataset.currency || 'CAD');
    }

    /* véhicule filtré par client */
    var clientSel = document.getElementById('docClient');
    var vehicleSel = document.getElementById('docVehicle');
    if (clientSel && vehicleSel) {
      var map = {};
      try { map = JSON.parse(document.getElementById('clientVehiclesData').textContent || '{}'); } catch (err) { map = {}; }
      var selectedVehicle = vehicleSel.dataset.selected || '';
      function refreshVehicles() {
        if (!clientSel.value) {
          vehicleSel.innerHTML = '<option value="">—</option>';
          vehicleSel.disabled = true;
          return;
        }
        var list = map[clientSel.value] || [];
        if (!list.length) {
          vehicleSel.innerHTML = '<option value="">Aucun véhicule enregistré pour ce client</option>';
          vehicleSel.disabled = true;
          return;
        }
        vehicleSel.disabled = false;
        vehicleSel.innerHTML = '<option value="">—</option>' + list.map(function (v) {
          return '<option value="' + v.id + '">' + v.label + '</option>';
        }).join('');
        if (list.length === 1) {
          vehicleSel.value = String(list[0].id);
        } else if (selectedVehicle && list.some(function (v) { return String(v.id) === String(selectedVehicle); })) {
          vehicleSel.value = selectedVehicle;
        } else {
          selectedVehicle = '';
        }
      }
      clientSel.addEventListener('change', refreshVehicles);
      refreshVehicles();
    }

    /* ---------- Initialisation des lignes du document ----------
       Le script de la page (devis / facture) expose window.docInitLines,
       qui ajoute les lignes initiales du formulaire (ligne vide, ou lignes
       reprises d'un devis). app.js étant chargé en pied de page, son
       gestionnaire DOMContentLoaded s'exécute APRÈS celui de la page :
       on appelle donc ce point d'entrée ici, une fois docAddLine() et
       docRecalc() réellement définis. */
    if (typeof window.docInitLines === 'function') {
      window.docInitLines();
    }
  }
});

/* ============================================================
   Recherche globale : bouton effacer + raccourci Ctrl+K/Cmd+K
   ============================================================ */
document.addEventListener('DOMContentLoaded', function () {

  // Bouton "effacer" dans chaque champ de recherche
  document.querySelectorAll('.global-search-field').forEach(function (field) {
    var input = field.querySelector('.global-search-input');
    var clear = field.querySelector('[data-search-clear]');
    if (!input) return;

    var sync = function () {
      if (clear) clear.hidden = input.value.length === 0;
    };
    sync();
    input.addEventListener('input', sync);

    if (clear) {
      clear.addEventListener('click', function () {
        input.value = '';
        sync();
        input.focus();
      });
    }
  });

  // Raccourci clavier : Ctrl+K (Windows/Linux) / Cmd+K (macOS)
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
      var inputs = Array.prototype.slice.call(document.querySelectorAll('.global-search-input'));
      var target = null;
      for (var i = 0; i < inputs.length; i++) {
        if (inputs[i].offsetParent !== null) { target = inputs[i]; break; }
      }
      if (target) {
        e.preventDefault();
        target.focus();
        target.select();
      }
    }
  });

  // Focus automatique à l'ouverture de la recherche mobile
  var mobileSearch = document.getElementById('navbarSearchMobile');
  if (mobileSearch) {
    mobileSearch.addEventListener('shown.bs.collapse', function () {
      var inp = mobileSearch.querySelector('.global-search-input');
      if (inp) inp.focus();
    });
  }
});

/* ============================================================
   LISTE DÉROULANTE AVEC RECHERCHE (vcombo)
   Solution 100 % native : aucune dépendance externe (le projet
   n'embarque pas Select2). Recherche au clavier, liste scrollable,
   sélection unique. Utilisée par les champs Marque / Modèle du
   formulaire véhicule ; le catalogue est fourni par la page dans
   #vcombo-data (JSON) et le champ Modèle se remplit tout seul
   quand la marque change.
   ============================================================ */
document.addEventListener('DOMContentLoaded', function () {
  var holder = document.getElementById('vcombo-data');
  if (!holder) return;

  var CATALOG = {};
  try {
    CATALOG = JSON.parse(holder.textContent || '{}') || {};
  } catch (err) {
    return; // JSON invalide : les champs restent de simples champs texte
  }

  function normKey(s) {
    return String(s == null ? '' : s).trim().toLowerCase().replace(/\s+/g, ' ');
  }

  /* Recherche insensible aux accents et aux caractères typographiques :
     « mega » trouve « Mégane », « citroen » trouve « Citroën ». */
  var MARKS = /[̀-ͯ]/g;   // diacritiques combinants
  var APOST = /['‘’ʼ`]/g;
  var DASH  = /[-–—_]/g;
  function fold(s) {
    var t = normKey(s);
    try {
      t = t.normalize('NFD').replace(MARKS, '');
    } catch (err) { /* normalize non supporté : on garde tel quel */ }
    return t.replace(APOST, '').replace(DASH, ' ').replace(/\s+/g, ' ').trim();
  }

  // Index marque normalisée => modèles
  var INDEX = {};
  Object.keys(CATALOG).forEach(function (brand) {
    INDEX[normKey(brand)] = CATALOG[brand] || [];
  });

  function Combo(root) {
    var input    = root.querySelector('.vcombo-control .form-control');
    var menu     = root.querySelector('.vcombo-menu');
    var list     = root.querySelector('.vcombo-list');
    var emptyBox = root.querySelector('.vcombo-empty');
    if (!input || !menu || !list) return null;

    var emptyText = root.getAttribute('data-vcombo-empty') || '';
    var dependsOn = root.getAttribute('data-vcombo-depends');   // ex. "brand"
    var allItems  = null;
    var open      = false;
    var cursor    = -1;

    function source() {
      // Modèles : uniquement ceux de la marque sélectionnée
      if (dependsOn) {
        var parent = document.getElementById(dependsOn);
        var brand  = parent ? normKey(parent.value) : '';
        if (!brand) return [];
        var l = INDEX[brand];
        return Array.isArray(l) ? l.slice() : [];
      }
      // Marques : tout le catalogue
      return Object.keys(CATALOG).slice();
    }

    function render() {
      var items = (allItems || source()).slice();
      var self  = normKey(input.getAttribute('data-vcombo-value') || '');

      // La valeur déjà enregistrée reste toujours proposée (fiche existante)
      if (self && !items.some(function (v) { return normKey(v) === self; })) {
        items.unshift(input.getAttribute('data-vcombo-value'));
      }

      var q = normKey(input.value);
      var needle = fold(input.value);
      var filtered = (!q || q === self)
        ? items
        : items.filter(function (v) { return fold(v).indexOf(needle) !== -1; });

      list.innerHTML = '';
      cursor = -1;

      emptyBox.textContent = emptyText;
      emptyBox.hidden = filtered.length > 0;
      if (!filtered.length) return;

      filtered.forEach(function (value) {
        var li  = document.createElement('li');
        var opt = document.createElement('div');
        opt.className = 'vcombo-opt';
        opt.setAttribute('role', 'option');
        opt.setAttribute('data-value', value);
        opt.textContent = value;
        if (normKey(value) === self) {
          opt.classList.add('selected');
          opt.setAttribute('aria-selected', 'true');
        }
        li.appendChild(opt);
        list.appendChild(li);
      });
    }

    function show() {
      if (input.disabled) return;
      allItems = source();
      render();
      menu.hidden = false;
      root.classList.add('open');
      open = true;
      input.setAttribute('aria-expanded', 'true');
    }

    function hide() {
      menu.hidden = true;
      root.classList.remove('open');
      open = false;
      cursor = -1;
      input.setAttribute('aria-expanded', 'false');
    }

    function commit(value) {
      input.value = value;
      input.setAttribute('data-vcombo-value', value);
      allItems = null;
      hide();
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function highlight() {
      var opts = list.querySelectorAll('.vcombo-opt');
      if (!opts.length) return;
      if (cursor < 0) cursor = 0;
      if (cursor >= opts.length) cursor = opts.length - 1;
      for (var i = 0; i < opts.length; i++) opts[i].classList.remove('active');
      opts[cursor].classList.add('active');
      if (opts[cursor].scrollIntoView) opts[cursor].scrollIntoView({ block: 'nearest' });
    }

    /* ---------- Souris ---------- */
    input.addEventListener('focus', function () { show(); });
    input.addEventListener('click', function () { if (open) hide(); else show(); });

    list.addEventListener('mousedown', function (ev) {
      var opt = ev.target.closest ? ev.target.closest('.vcombo-opt') : null;
      if (!opt) return;
      ev.preventDefault();          // évite que l'input perde le focus
      commit(opt.getAttribute('data-value'));
      input.focus();
    });

    /* ---------- Clavier ---------- */
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        if (!open) { show(); return; }
        cursor += (ev.key === 'ArrowDown' ? 1 : -1);
        highlight();
      } else if (ev.key === 'Enter') {
        var opts = list.querySelectorAll('.vcombo-opt');
        if (open && opts.length) {
          ev.preventDefault();   // pas de soumission accidentelle du formulaire
          commit(opts[cursor < 0 ? 0 : cursor].getAttribute('data-value'));
        }
      } else if (ev.key === 'Escape') {
        if (open) { ev.preventDefault(); hide(); }
      } else if (ev.key === 'Tab') {
        hide();
      }
    });

    /* ---------- Filtrage à la frappe ---------- */
    input.addEventListener('input', function () {
      input.setAttribute('data-vcombo-value', ''); // saisie en cours
      allItems = null;
      if (open) render(); else show();
    });

    /* ---------- Clic extérieur ---------- */
    document.addEventListener('mousedown', function (ev) {
      if (open && !root.contains(ev.target)) hide();
    });

    return {
      root: root,
      input: input,
      // Changement de marque : remise à zéro du modèle précédent
      reset: function (value) {
        input.value = value || '';
        input.setAttribute('data-vcombo-value', value || '');
        allItems = null;
        hide();
      },
      refresh: function () {
        allItems = source();
        if (open) render();
      }
    };
  }

  /* ---------- Initialisation ---------- */
  var combos = {};
  Array.prototype.forEach.call(document.querySelectorAll('[data-vcombo]'), function (root) {
    var c = Combo(root);
    if (c) combos[root.getAttribute('data-vcombo-name')] = c;
  });

  var brand = combos['brand'];
  var model = combos['model'];

  function syncModel() {
    if (!brand || !model) return;
    var hasBrand = brand.input.value.trim() !== '';
    // Sans marque : champ modèle désactivé ET vide
    model.input.disabled = !hasBrand;
    model.root.classList.toggle('is-disabled', !hasBrand);
    if (hasBrand) {
      model.refresh();   // charge les modèles de la marque choisie
    } else {
      model.reset('');
    }
  }

  if (brand && model) {
    brand.input.addEventListener('change', function () {
      model.reset('');   // réinitialise le modèle précédent
      syncModel();
    });
    // Fiche existante : la marque est déjà renseignée
    brand.input.setAttribute('data-vcombo-value', brand.input.value || '');
    model.input.setAttribute('data-vcombo-value', model.input.value || '');
    syncModel();
  }
});
