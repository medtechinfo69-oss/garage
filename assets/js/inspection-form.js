/* ============================================================
   PROS AUTO — Formulaire d'inspection
   ------------------------------------------------------------
   Page concernée : admin/inspections/form.php
   Le script s'ignore seul sur les autres pages (il cherche #inspectionForm).

   Sommaire
     1. Utilitaires              4. Aperçu du mécanicien affecté
     2. Compteurs de caractères  5. Zone de dépôt de la photo
     3. Aperçu du véhicule       6. Validation et état du bouton
   ============================================================ */
(function (window, document) {
  'use strict';

  /* ---------- 1. Utilitaires ---------- */

  /** Récupère un élément par son id (null si absent). */
  function $(id) {
    return document.getElementById(id);
  }

  /** Écrit un texte dans un élément (sans interpréter de HTML). */
  function setText(id, value) {
    var node = $(id);
    if (node) node.textContent = value;
  }

  /** Affiche / masque un élément via l'attribut natif [hidden]. */
  function toggle(node, visible) {
    if (node) node.hidden = !visible;
  }

  /** "Ahmed Ben Salah" => "AB" : 1re lettre des 2 premiers mots. */
  function initials(name) {
    return name
      .split(/\s+/)
      .slice(0, 2)
      .map(function (word) { return word.charAt(0); })
      .join('')
      .toUpperCase();
  }

  /* ---------- 2. Compteurs de caractères ---------- */
  /* Chaque zone de texte déclare data-counter ; le compteur lié porte
     data-counter-for="<id du champ>". Le gabarit d'affichage vient de
     l'attribut data-chars-template du formulaire (ex. "%d caractères"). */
  function initCounters(form, template) {
    form.querySelectorAll('textarea[data-counter]').forEach(function (field) {
      var output = form.querySelector('[data-counter-for="' + field.id + '"]');
      if (!output) return;

      var max = field.getAttribute('maxlength') || '';

      function refresh() {
        output.textContent = template.replace('%d', field.value.length)
          + (max ? ' / ' + max : '');
      }

      field.addEventListener('input', refresh);
      refresh(); // état initial (mode édition : le texte est déjà rempli)
    });
  }

  /* ---------- 3. Aperçu du véhicule (panneau latéral) ---------- */
  /* Les données sont injectées par PHP dans un bloc
     <script type="application/json"> : clé = id de véhicule. */
  function initVehiclePreview() {
    var select  = $('vehicle_id');
    var empty   = $('inspPvEmpty');
    var live    = $('inspPvLive');
    var payload = $('inspVehiclesData');
    if (!select || !empty || !live || !payload) return;

    var vehicles = {};
    try {
      vehicles = JSON.parse(payload.textContent) || {};
    } catch (err) {
      vehicles = {}; // JSON invalide : l'aperçu reste simplement vide
    }

    function render() {
      var vehicle = vehicles[String(select.value)];

      if (!vehicle) {            // aucun véhicule sélectionné
        toggle(empty, true);
        toggle(live, false);
        return;
      }

      toggle(empty, false);
      toggle(live, true);

      setText('inspPvPlate', vehicle.plate);
      setText('inspPvModel', vehicle.model + (vehicle.year ? ' · ' + vehicle.year : ''));
      setText('inspPvClient', vehicle.client || '—');
      setText('inspPvVin', vehicle.vin || '—');
      setText('inspPvYear', vehicle.year || '—');

      // badge d'état : HTML produit par status_badge() côté serveur
      var badge = $('inspPvStatus');
      if (badge) badge.innerHTML = vehicle.badge || '';
    }

    select.addEventListener('change', render);
    render(); // appel initial : véhicule déjà présélectionné en édition
  }

  /* ---------- 4. Aperçu du mécanicien affecté ---------- */
  /* Chaque option est construite ainsi : "Nom — Spécialité".
     On la découpe sur le tiret cadratin pour séparer le nom de la spécialité. */
  function initMechanicPreview() {
    var select = $('mechanic_id');
    var empty  = $('inspMechEmpty');
    var live   = $('inspMechLive');
    if (!select || !empty || !live) return;

    function render() {
      var option = select.options[select.selectedIndex];

      if (!option || !option.value) {   // rien de sélectionné
        toggle(empty, true);
        toggle(live, false);
        return;
      }

      var parts = option.textContent.split('—');
      var name  = (parts.shift() || '').trim();

      toggle(empty, false);
      toggle(live, true);

      setText('inspMechName', name);
      setText('inspMechSpec', parts.join('—').trim()); // vide s'il n'y a pas de spécialité
      setText('inspMechAvatar', initials(name));
    }

    select.addEventListener('change', render);
    render(); // appel initial : mécanicien déjà présélectionné en édition
  }

  /* ---------- 5. Zone de dépôt de la photo ---------- */
  /* Clic sur la zone ou sur le bouton = ouverture du sélecteur de fichiers.
     Glisser-déposer : le fichier déposé est injecté dans l'input pour
     qu'il soit réellement envoyé avec le formulaire. */
  function initPhotoDrop() {
    var drop   = $('inspDrop');
    var input  = $('photo');
    var browse = $('inspBrowse');
    var clear  = $('inspDropClear');
    var prev   = $('inspDropPreview');
    var img    = $('inspDropImg');
    if (!drop || !input || !prev || !img) return;

    function showFile(file) {
      if (!file) return;
      // Aperçu local via un objet URL (absent sur de très vieux navigateurs)
      if (window.URL && window.URL.createObjectURL) {
        img.src = window.URL.createObjectURL(file);
      }
      setText('inspDropName', file.name);
      toggle(prev, true);
      drop.classList.add('has-file'); // masque icône et bouton « Parcourir »
    }

    function reset() {
      input.value = '';
      toggle(prev, false);
      img.removeAttribute('src');
      drop.classList.remove('has-file');
    }

    input.addEventListener('change', function () { showFile(input.files[0]); });

    if (browse) {
      browse.addEventListener('click', function (ev) {
        ev.preventDefault();
        input.click();
      });
    }

    if (clear) {
      clear.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation(); // évite de rouvrir le sélecteur
        reset();
      });
    }

    drop.addEventListener('click', function (ev) {
      if (ev.target.closest('button')) return;
      input.click();
    });

    ['dragenter', 'dragover'].forEach(function (type) {
      drop.addEventListener(type, function (ev) {
        ev.preventDefault();
        drop.classList.add('is-drag');
      });
    });

    ['dragleave', 'drop'].forEach(function (type) {
      drop.addEventListener(type, function (ev) {
        ev.preventDefault();
        drop.classList.remove('is-drag');
      });
    });

    drop.addEventListener('drop', function (ev) {
      if (!ev.dataTransfer || !ev.dataTransfer.files.length) return;
      try {
        input.files = ev.dataTransfer.files;
      } catch (err) {
        /* navigateur restrictif : l'aperçu fonctionne, l'envoi non */
      }
      showFile(ev.dataTransfer.files[0]);
    });
  }

  /* ---------- 6. Validation et état du bouton ---------- */
  /* Le formulaire est en novalidate : on met donc en avant les champs
     obligatoires vides (classe .is-invalid) puis on recentre sur le premier.
     Le serveur refait la même validation : c'est la seule vraie sécurité. */
  function initValidation(form) {
    // Retire la marque d'erreur dès que l'utilisateur corrige le champ
    form.addEventListener('input', function (ev) {
      var field = ev.target;
      if (field.classList.contains('is-invalid') && String(field.value || '').trim()) {
        field.classList.remove('is-invalid');
      }
    });

    form.addEventListener('submit', function (ev) {
      var firstInvalid = null;

      form.querySelectorAll('[required]').forEach(function (field) {
        if (field.disabled) return;               // ex. aucun mécanicien actif
        var empty = !String(field.value || '').trim();
        field.classList.toggle('is-invalid', empty);
        if (empty && !firstInvalid) firstInvalid = field;
      });

      if (firstInvalid) {
        ev.preventDefault();
        firstInvalid.focus();
        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }

      // Envoi : on fige le bouton pour éviter le double-clic
      var button = $('inspSubmit');
      if (button) {
        var label  = button.querySelector('.insp-btn-label');
        var loader = button.querySelector('.insp-btn-loading');
        if (label) label.classList.add('d-none');
        if (loader) loader.classList.remove('d-none');
        button.disabled = true;
      }
    });
  }

  /* ---------- Amorçage ---------- */
  function init() {
    var form = $('inspectionForm');
    if (!form) return; // page différente : rien à faire

    initCounters(form, form.dataset.charsTemplate || '%d');
    initVehiclePreview();
    initMechanicPreview();
    initPhotoDrop();
    initValidation(form);
  }

  // Exposition pour un éventuel appel manuel + démarrage automatique
  window.InspectionForm = { init: init };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init(); // script chargé en fin de body : le DOM est déjà prêt
  }
})(window, document);