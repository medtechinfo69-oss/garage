<?php

/**
 * Mise en page des documents (Devis / Facture) :
 * - rendu PDF professionnel
 * - gabarit e-mail HTML
 */

declare(strict_types=1);

/* Interdit l'accès direct à ce fichier (inclusion uniquement). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(403); exit('Accès direct interdit.'); }


require_once __DIR__ . '/pdf_writer.php';

/**
 * Construit le PDF d'un devis ou d'une facture.
 * $doc   : ligne de devis/factures (avec client/vehicle joints)
 * $items : lignes du document
 * $type  : 'devis' | 'facture'
 */
/* Couleurs de la charte (0..1 par composante). */
const PDF_INK   = [0.106, 0.141, 0.188]; // bleu nuit (texte fort / barres)
const PDF_RED   = [0.8, 0.164, 0.133]; // rouge PROS AUTO
const PDF_GREY  = [0.42, 0.46, 0.52];    // libellés secondaires
const PDF_LINE  = 0.85;                  // filets clairs
const PDF_SOFT  = [0.957, 0.965, 0.973]; // fonds de bloc
const PDF_ZEBRA = [0.976, 0.980, 0.986]; // lignes alternées

/**
 * Écrit du texte aligné à gauche / centré / à droite dans une colonne,
 * en tronquant avec « … » si la largeur disponible est dépassée.
 */
function pdf_cell(PdfWriter $pdf, float $x, float $w, float $y, string $txt, string $align = 'left'): void
{
    $txt = (string) $txt;
    if ($txt === '') {
        return;
    }
    if ($pdf->textWidth($txt) > $w) {
        while ($txt !== '' && $pdf->textWidth($txt . '…') > $w) {
            $txt = mb_substr($txt, 0, -1);
        }
        $txt .= '…';
    }
    $tw = $pdf->textWidth($txt);
    if ($align === 'right') {
        $tx = $x + $w - $tw;
    } elseif ($align === 'center') {
        $tx = $x + ($w - $tw) / 2;
    } else {
        $tx = $x;
    }
    $pdf->textAt($tx, $y, $txt);
}

/**
 * Découpe un texte en lignes qui tiennent dans la largeur $w (en pt),
 * mot par mot. Un mot plus large que la colonne est coupé caractère par
 * caractère : RIEN n'est perdu, contrairement à pdf_cell() qui tronque
 * avec « … ». Renvoie toujours au moins une ligne.
 */
function pdf_wrap_lines(PdfWriter $pdf, string $txt, float $w): array
{
    $txt = str_replace(["\r\n", "\r"], "\n", $txt);
    $out = [];
    foreach (explode("\n", $txt) as $para) {
        $cur = '';
        foreach (preg_split('/\s+/u', trim((string) $para), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            /* Mot seul plus large que la colonne : on le découpe en
               morceaux qui tiennent, sans perdre de caractère. */
            while ($pdf->textWidth($word) > $w && mb_strlen($word) > 1) {
                $cut = mb_strlen($word) - 1;
                while ($cut > 1 && $pdf->textWidth(mb_substr($word, 0, $cut)) > $w) {
                    $cut--;
                }
                if ($cur !== '') {
                    $out[] = $cur;
                    $cur = '';
                }
                $out[] = mb_substr($word, 0, $cut);
                $word = mb_substr($word, $cut);
            }
            $cand = $cur === '' ? $word : $cur . ' ' . $word;
            if ($cur !== '' && $pdf->textWidth($cand) > $w) {
                $out[] = $cur;
                $cur = $word;
            } else {
                $cur = $cand;
            }
        }
        if ($cur !== '') {
            $out[] = $cur;
        }
    }
    return $out ?: [''];
}

/**
 * Construit le PDF d'un devis ou d'une facture.
 * $doc   : ligne de devis/factures (avec client/vehicle joints)
 * $items : lignes du document
 * $type  : 'devis' | 'facture'
 */
function build_document_pdf(array $doc, array $client, ?array $vehicle, array $items, string $type): PdfWriter
{
    $pdf = new PdfWriter();
    $co = company_info();
    $cur = currency();
    $L = $doc['doc_lang'] ?? current_lang();

    /* ---------- Géométrie de la page ---------- */
    $w = PdfWriter::PAGE_W;
    $m = 42.0;                 // marge gauche / droite
    $right = $w - $m;          // bord droit
    $cw = $right - $m;         // largeur utile
    $footerTop = 86.0;         // filet du pied de page (4 lignes en dessous)

    /* Alignement à droite : textAt($right, …) écrit le texte À GAUCHE au bord
       droit, ce qui le faisait sortir de la page. */
    $rightAlign = static function (PdfWriter $pdf, float $right, float $y, string $txt): void {
        $pdf->textAt($right - $pdf->textWidth($txt), $y, $txt);
    };

    /* 1. EN-TÊTE — coordonnées société à gauche, logo à droite. */
    $headTop = PdfWriter::PAGE_H - $m;   // 799.89

    /* Bloc société : aligné à gauche, une information par ligne. */
    $pdf->setFont(false, 8.5);
    $pdf->setColor(...PDF_GREY);
    $coLines = [
        trim((string) $co['address']),
        trim((string) $co['phone']),
        trim((string) $co['email']),
        trim((string) $co['web']),
        $co['tax_id'] !== '' ? 'MF : ' . trim((string) $co['tax_id']) : '',
    ];
    $coLines = array_values(array_filter($coLines, static fn($v) => trim((string) $v) !== ''));
    $coLineH = 10.0;
    $ly = $headTop - 8;
    foreach ($coLines as $ln) {
        $pdf->textAt($m, $ly, (string) $ln);
        $ly -= $coLineH;
    }
    $pdf->setColor(0, 0, 0);

    /* Logo placé à droite du bloc texte, sans chevauchement. */
    $logoName = 'logo';
    $logoPath = __DIR__ . '/../assets/images/logo.png';
    if (!is_file($logoPath)) {
        $logoPath = __DIR__ . '/../assets/images/logo.jpg';
    }
    $hasLogo = $pdf->addImage($logoPath, $logoName);
    $logoH = 48.0;
    $logoBottom = $headTop - $logoH;
    if ($hasLogo) {
        $pdf->drawImageFit($logoName, $right - 150.0, $logoBottom, 150.0, $logoH);
    }

    /* Filet sous le bloc complet. */
    $ruleY = min($logoBottom, $ly) - 10;
    $pdf->line($m, $ruleY, $right, $ruleY, 0.9, PDF_LINE);
    $pdf->line($m, $ruleY, $m + 62, $ruleY, 2.6, 0.878);

    $y = $ruleY;

    /* ==========================================================
     * 2. TITRE — « FACTURE » / « DEVIS » immédiatement après l'en-tête,
     *    à gauche ; n° + dates dans un bloc aligné à droite.
     * ========================================================== */
    $bandTop = $y - 16;          // haut du bandeau titre
    $bandH   = 84.0;             // assez haut : titre + n° + date sous le titre
    $pdf->rectFilled($m, $bandTop - $bandH, $cw, $bandH, PDF_SOFT);
    /* Liseré rouge à gauche du bandeau. */
    $pdf->rectFilled($m, $bandTop - $bandH, 3.2, $bandH, PDF_RED);

    $title = $type === 'devis' ? t('pdf.devis') : t('pdf.facture');
    $pdf->setColor(...PDF_INK);
    $pdf->setFont(true, 24);
    $pdf->textAt($m + 16, $bandTop - 30, mb_strtoupper($title));

    /* Métadonnées du document : bloc propre à droite du titre.
       Le numéro reste au-dessus de la date, avec un interligne régulier. */
    $num = (string) ($type === 'devis' ? $doc['quote_number'] : $doc['invoice_number']);
    $meta = [];
    if ($type === 'devis') {
        $meta[] = t('pdf.date') . ' : ' . fmt_date($doc['devis_date']);
        if (!empty($doc['valid_until'])) {
            $meta[] = t('pdf.valid_until') . ' : ' . fmt_date($doc['valid_until']);
        }
    } else {
        $meta[] = t('pdf.date') . ' : ' . fmt_date($doc['invoice_date']);
        if (!empty($doc['devis_id'])) {
            $dnum = fetch_one("SELECT quote_number FROM devis WHERE id = ?", [$doc['devis_id']]);
            if ($dnum) {
                $meta[] = t('pdf.ref_devis', $dnum['quote_number']);
            }
        }
    }

    $pdf->setFont(true, 12.5);
    $pdf->setColor(...PDF_INK);
    $rightAlign($pdf, $right, $bandTop - 30, $num);
    $pdf->setFont(false, 8.5);
    $pdf->setColor(...PDF_GREY);
    $my = $bandTop - 48;
    foreach ($meta as $ln) {
        $rightAlign($pdf, $right, $my, $ln);
        $my -= 12;
    }
    $pdf->setColor(0, 0, 0);

    $y = $bandTop - $bandH;

    /* ==========================================================
     * 3. CLIENT / VÉHICULE — deux blocs clairs, côte à côte.
     * ========================================================== */
    $y -= 20;
    $boxTop = $y;
    $padX = 11.0;
    $lineH = 12.5;

    /* Colonne de gauche : plus large si pas de véhicule. */
    $gap = 14.0;
    $halfW = ($cw - $gap) / 2;
    $colW = $vehicle ? $halfW : $cw;

    /* Contenu du bloc client (une information par ligne). */
    $cInfo = [
        civility_label((string) ($client['civility'] ?? '')) . ' ' . trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')),
        trim((string) ($client['address'] ?? '')),
        trim(((string) ($client['postal_code'] ?? '')) . ' ' . (string) ($client['city'] ?? '')),
        trim((string) ($client['phone'] ?? '')),
        trim((string) ($client['email'] ?? '')),
    ];
    $cInfo = array_values(array_filter($cInfo, static fn($v) => trim((string) $v) !== ''));

    $vInfo = [];
    if ($vehicle) {
        $vehLine = trim(($vehicle['brand'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
        if (!empty($vehicle['year'])) {
            $vehLine .= ' (' . $vehicle['year'] . ')';
        }
        $vInfo[] = $vehLine;
        $vInfo[] = t('common.plate') . ' : ' . (string) ($vehicle['license_plate'] ?? '');
        if (!empty($vehicle['vin'])) {
            $vInfo[] = t('common.vin') . ' : ' . $vehicle['vin'];
        }
        if (($vehicle['mileage'] ?? null) !== null && ($vehicle['mileage'] ?? '') !== '') {
            $vInfo[] = t('common.mileage') . ' : ' . number_format((float) $vehicle['mileage']) . ' km';
        }
        $vInfo = array_values(array_filter($vInfo, static fn($x) => trim((string) $x) !== ''));
    }

    /* Hauteur commune aux deux blocs : assez pour la colonne la plus longue. */
    $rows = max(count($cInfo), count($vInfo), 1);
    $boxH = 24 + $rows * $lineH + 8;

    $drawBox = static function (PdfWriter $pdf, float $bx, float $bw, float $top, float $h, string $label, array $lines) use ($padX, $lineH): void {
        $pdf->rectFilled($bx, $top - $h, $bw, $h, PDF_SOFT);
        /* Liseré d'accent à gauche du bloc. */
        $pdf->rectFilled($bx, $top - $h, 2.6, $h, PDF_RED);
        $pdf->setColor(...PDF_INK);
        $pdf->setFont(true, 8);
        $pdf->textAt($bx + $padX, $top - 14, mb_strtoupper($label));
        $pdf->setFont(false, 9.5);
        $ty = $top - 30;
        foreach ($lines as $ln) {
            pdf_cell($pdf, $bx + $padX, $bw - 2 * $padX, $ty, (string) $ln);
            $ty -= $lineH;
        }
        $pdf->setColor(0, 0, 0);
    };

    $drawBox($pdf, $m, $colW, $boxTop, $boxH, t('pdf.client'), $cInfo);
    if ($vehicle) {
        $drawBox($pdf, $m + $colW + $gap, $halfW, $boxTop, $boxH, t('pdf.vehicle'), $vInfo);
    }

    $y = $boxTop - $boxH;

    /* ==========================================================
     * 4. TABLEAU DES LIGNES
     *    N° | Description (extensible) | Qté | PU | Total
     * ========================================================== */
    $y -= 22;

    /* Colonnes du tableau : mêmes informations que le tableau du
       formulaire (Description | Quantité | Prix unitaire (HT) | Total HT),
       plus le n° de ligne comme sur la page de consultation.
       Les colonnes « Remise » et « TVA » ne sont ajoutées que si les
       lignes portent réellement une remise ou un taux autre que 19 % :
       aucune information n'est perdue, et une facture ordinaire garde
       exactement la présentation du formulaire (remise 0, TVA 19 %).
       N°(26) | Description(extensible) | Qté(46) | PU(70) | [Remise(46)]
       | [TVA(40)] | Total(78) */
    $showDisc = false;
    $showTva  = false;
    foreach ($items as $it) {
        if ((float) ($it['discount_pct'] ?? 0) > 0) {
            $showDisc = true;
        }
        if (abs((float) ($it['tva_rate'] ?? 19) - 19.0) > 0.001) {
            $showTva = true;
        }
    }

    $wDesc = $cw - (26 + 46 + 70 + ($showDisc ? 46 : 0) + ($showTva ? 40 : 0) + 78);
    $cols  = [
        ['w' => 26.0,   'align' => 'center', 'label' => t('pdf.no')],
        ['w' => $wDesc, 'align' => 'left',   'label' => t('pdf.description')],
        ['w' => 46.0,   'align' => 'right',  'label' => t('pdf.qty')],
        ['w' => 70.0,   'align' => 'right',  'label' => t('pdf.unit_price')],
    ];
    if ($showDisc) {
        $cols[] = ['w' => 46.0, 'align' => 'right', 'label' => t('pdf.discount') . ' %'];
    }
    if ($showTva) {
        $cols[] = ['w' => 40.0, 'align' => 'right', 'label' => t('pdf.tva_rate') . ' %'];
    }
    $cols[] = ['w' => 78.0, 'align' => 'right', 'label' => t('pdf.total')];

    /* Positions horizontales calculées UNE fois : l'en-tête et les lignes
       utilisent donc exactement les mêmes colonnes (alignement garanti). */
    $cx = $m;
    foreach ($cols as $k => $c) {
        $cols[$k]['x'] = $cx;
        $cx += (float) $c['w'];
    }

    /* En-tête : fond bleu nuit, texte blanc (noir = illisible).
       Réutilisé à l'identique après chaque saut de page. */
    $drawHead = static function (PdfWriter $pdf, array $cols, float $top, float $cw, float $m): void {
        $pdf->rectFilled($m, $top - 8, $cw, 20, PDF_INK);
        $pdf->setFont(true, 8);
        $pdf->setColor(1, 1, 1);
        foreach ($cols as $c) {
            pdf_cell($pdf, (float) $c['x'], (float) $c['w'], $top - 2, (string) $c['label'], (string) $c['align']);
        }
    };

    $drawHead($pdf, $cols, $y, $cw, $m);
    $pdf->setColor(0, 0, 0);

    $y -= 26;

    $pdf->setFont(false, 9);
    $i = 0;
    $minY = $footerTop + 40;
    $topY = PdfWriter::PAGE_H - $m - 30;   // première ligne utilisable après un saut
    foreach ($items as $it) {
        /* Description repliée sur plusieurs lignes si nécessaire : le
           formulaire autorise 255 caractères, ils doivent TOUS apparaître
           (l'ancien code tronquait avec « … » : information perdue).
           Chaque ligne du document affiche donc bien description,
           quantité, prix unitaire et total HT. */
        $descLines = pdf_wrap_lines($pdf, (string) ($it['description'] ?? ''), $wDesc);
        $nLines    = count($descLines);
        $rowH      = 19.0 + ($nLines - 1) * 11.0;

        /* Saut de page AVANT de dessiner la ligne (jamais au milieu), et
           jamais deux fois de suite (sinon pages vides à répétition). */
        if ($y - $rowH < $minY && $y < $topY) {
            $pdf->addPage();
            $y = $topY;
            $drawHead($pdf, $cols, $y, $cw, $m);
            $pdf->setColor(0, 0, 0);
            $pdf->setFont(false, 9);
            $y -= 26;
        }

        /* Texte des lignes : TOUJOURS noir. Sans ce rappel, la couleur
           laissée par un fond (zébrures) s'appliquait au texte : les
           lignes suivant un fond clair s'écrivaient en quasi-blanc, donc
           invisibles. */
        $pdf->setColor(0, 0, 0);

        /* Zébrage discret des lignes paires, derrière toutes les lignes
           de texte de la ligne. */
        $bandY = $y - ($nLines - 1) * 11.0 - 5.0;
        if ($i % 2 === 1) {
            $pdf->rectFilled($m, $bandY, $cw, $rowH, PDF_ZEBRA);
        }

        $c = 0;
        pdf_cell($pdf, (float) $cols[$c]['x'], (float) $cols[$c]['w'], $y, (string) ($i + 1), 'center');
        $c++;
        foreach ($descLines as $n => $ln) {
            pdf_cell($pdf, (float) $cols[$c]['x'], (float) $cols[$c]['w'], $y - $n * 11.0, $ln, 'left');
        }
        $c++;
        pdf_cell($pdf, (float) $cols[$c]['x'], (float) $cols[$c]['w'], $y, num_short((float) ($it['quantity'] ?? 0)), 'right');
        $c++;
        pdf_cell($pdf, (float) $cols[$c]['x'], (float) $cols[$c]['w'], $y, num_short((float) ($it['unit_price'] ?? 0)), 'right');
        $c++;
        if ($showDisc) {
            pdf_cell($pdf, (float) $cols[$c]['x'], (float) $cols[$c]['w'], $y, num_short((float) ($it['discount_pct'] ?? 0)), 'right');
            $c++;
        }
        if ($showTva) {
            pdf_cell($pdf, (float) $cols[$c]['x'], (float) $cols[$c]['w'], $y, num_short((float) ($it['tva_rate'] ?? 19)), 'right');
            $c++;
        }
        pdf_cell($pdf, (float) $cols[$c]['x'], (float) $cols[$c]['w'], $y, num_short((float) ($it['line_total'] ?? 0)), 'right');

        $y -= $rowH;
        $i++;
        $pdf->line($m, $y + 6, $right, $y + 6, 0.3, PDF_LINE);
    }
    $pdf->setColor(0, 0, 0);

    /* Filet de clôture du tableau. */
    $pdf->line($m, $y + 6, $right, $y + 6, 0.9, 0.5);
    $y -= 26;

    /* ==========================================================
     * 5. TOTAUX — bloc aligné à droite, avec la barre TTC en évidence.
     * ========================================================== */
    $amountRight = static function (PdfWriter $pdf, float $right, float $y, string $txt): void {
        $pdf->textAt($right - $pdf->textWidth($txt), $y, $txt);
    };

    /* Hauteur nécessaire au bloc de totaux : Total HT, [Remise totale],
       TVA, écart + barre TTC. Si elle ne tient plus au-dessus du pied de
       page (tableau qui remplit la page), on continue sur une page neuve :
       un total ne doit jamais s'écrire par-dessus le pied de page. */
    $discountTotal = (float) ($doc['total_discount'] ?? 0);
    $needTotals    = 76.0 + ($discountTotal > 0 ? 15.0 : 0.0);
    if ($y - $needTotals < $footerTop) {
        $pdf->addPage();
        $y = PdfWriter::PAGE_H - $m - 30;
    }

    $boxRight = $right;
    $boxLeft  = $right - 232;

    $pdf->setFont(false, 9.5);
    $pdf->textAt($boxLeft, $y, t('common.total_ht'));
    $amountRight($pdf, $boxRight, $y, money_str((float) $doc['total_ht']));
    $y -= 15;

    /* Remise totale : affichée seulement si le document en comporte une
       (les formulaires devis/facture la forcent à 0 : pas de ligne
       « 0,00 » inutile, mais aucune remise réelle n'est masquée). */
    if ($discountTotal > 0) {
        $pdf->textAt($boxLeft, $y, t('pdf.discount_total'));
        $amountRight($pdf, $boxRight, $y, '-' . money_str($discountTotal));
        $y -= 15;
    }

    $pdf->textAt($boxLeft, $y, t('common.total_tva') . ' (19 %)');
    $amountRight($pdf, $boxRight, $y, money_str((float) $doc['total_tva']));
    $y -= 22;   // marge suffisante : sinon la barre TTC recouvre cette ligne

    /* Barre TTC : fond bleu nuit, texte blanc. */
    $pdf->rectFilled($boxLeft - 10, $y - 8, $boxRight - $boxLeft + 10, 24, PDF_INK);
    $pdf->setFont(true, 11);
    $pdf->setColor(1, 1, 1);
    $pdf->textAt($boxLeft, $y, t('common.total_ttc'));
    $amountRight($pdf, $boxRight - 10, $y, money_str((float) $doc['total_ttc']));
    $pdf->setColor(0, 0, 0);
    $y -= 30;

    /* Statut « payé » : pastille verte sous le bloc de totaux. */
    if ($type === 'facture' && ($doc['status'] ?? '') === 'payee') {
        $lagText = t('pdf.status_paid');
        $pdf->setFont(true, 10);
        $badgeW = $pdf->textWidth($lagText) + 22;
        $badgeX = $boxRight - $badgeW;
        $pdf->rectFilled($badgeX, $y - 8, $badgeW, 20, [0.12, 0.62, 0.33]);
        $pdf->setColor(1, 1, 1);
        $pdf->textAt($badgeX + 11, $y - 2, $lagText);
        $pdf->setColor(0, 0, 0);
        $y -= 22;
    }

    /* ==========================================================
     * 6. NOTES (facultatives) — sous les totaux, sur toute la largeur.
     * ========================================================== */
    if (!empty($doc['notes'])) {
        $notesTop = min($y - 6, $boxLeft - 20);
        $pdf->setFont(true, 8);
        $pdf->setColor(...PDF_INK);
        $pdf->textAt($m, $notesTop, mb_strtoupper(t('pdf.notes')));
        $pdf->setColor(0, 0, 0);
        $ny = $notesTop - 13;
        $pdf->setFont(false, 8.5);
        foreach (wrap_text((string) $doc['notes'], 96) as $ln) {
            if ($ny < $footerTop + 30) {
                break;
            }
            $pdf->textAt($m, $ny, $ln);
            $ny -= 11;
        }
        $y = min($y, $ny);
    }

    /* ==========================================================
     * 7. PIED DE PAGE
     * ========================================================== */

    return $pdf;
}

/* ---------- Helpers documents ---------- */

function money_str(float $n): string
{
    return number_format($n, 2, ',', ' ') . ' ' . currency();
}

function wrap_text(string $text, int $maxChars): array
{
    $lines = [];
    foreach (preg_split('/\r?\n/', $text) as $para) {
        $words = preg_split('/\s+/', trim($para));
        $line = '';
        foreach ($words as $wrd) {
            if (mb_strlen($line) + mb_strlen($wrd) + 1 > $maxChars && $line !== '') {
                $lines[] = $line;
                $line = $wrd;
            } else {
                $line = $line === '' ? $wrd : $line . ' ' . $wrd;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    return $lines ?: [''];
}

/**
 * Gabarit e-mail professionnel (HTML) pour l'envoi d'un document.
 */
function document_email_html(array $doc, array $client, ?array $vehicle, string $type, string $message): string
{
    $co = company_info();
    $cur = currency();
    $L = $doc['doc_lang'] ?? current_lang();
    $num = $type === 'devis' ? $doc['quote_number'] : $doc['invoice_number'];
    $veh = $vehicle ? trim($vehicle['brand'] . ' ' . $vehicle['model']) . ' (' . $vehicle['license_plate'] . ')' : '—';
    $docWord = $type === 'devis' ? t('devis.view_title') : t('facture.view_title');

    $subject = $type === 'devis'
        ? t('email.subject_devis', $num, $co['name'])
        : t('email.subject_facture', $num, $co['name']);

    $intro = $type === 'devis'
        ? t('email.devis_intro', $num, $veh)
        : t('email.facture_intro', $num, $veh);

    $dateLine = $type === 'devis'
        ? ($doc['valid_until'] ? t('email.valid_until', fmt_date($doc['valid_until'])) : '')
        : ($doc['due_date'] ? t('email.due_date', fmt_date($doc['due_date'])) : '');

    $rows = '';
    foreach (fetch_doc_items($type, (int) $doc['id']) as $it) {
        $rows .= '<tr>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #eee;">' . e($it['description']) . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;">' . num_short((float) $it['quantity']) . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;">' . money_str((float) $it['line_total']) . '</td>'
            . '</tr>';
    }

    $html = '
<!doctype html>
<html lang="' . $L . '">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f1f4f8;font-family:Segoe UI,Arial,sans-serif;">
<div style="max-width:640px;margin:24px auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e9f0;">
  <div style="padding:28px 32px;">
    <p style="font-size:14px;color:#333;margin-top:0;">' . e(t('email.greeting', client_name($client))) . '</p>
    <p style="font-size:14px;color:#333;">' . e($intro) . '</p>'
    . ($dateLine ? '<p style="font-size:13px;color:#666;">' . e($dateLine) . '</p>' : '')
    . '
    <table role="presentation" width="100%" style="margin:16px 0;font-size:13px;border-collapse:collapse;">
      <tr style="background:#1b2430;color:#fff;">
        <th align="left" style="padding:9px 10px;">' . e(t('pdf.description')) . '</th>
        <th align="right" style="padding:9px 10px;">' . e(t('pdf.qty')) . '</th>
        <th align="right" style="padding:9px 10px;">' . e(t('pdf.total')) . '</th>
      </tr>
      ' . $rows . '
    </table>
    <table role="presentation" style="margin-left:auto;font-size:13px;">
      <tr><td style="padding:3px 12px 3px 0;color:#555;">' . e(t('pdf.total')) . '</td><td align="right" style="padding:3px 0;">' . money_str((float) $doc['total_ht']) . '</td></tr>
      <tr><td style="padding:3px 12px 3px 0;color:#555;">' . e(t('pdf.tva_total')) . '</td><td align="right" style="padding:3px 0;">' . money_str((float) $doc['total_tva']) . '</td></tr>
      <tr><td style="padding:6px 12px 6px 0;font-weight:bold;font-size:15px;color:#1b2430;">' . e(t('pdf.ttc')) . '</td><td align="right" style="padding:6px 0;font-weight:bold;font-size:15px;color:#1b2430;">' . money_str((float) $doc['total_ttc']) . '</td></tr>
    </table>'
    . ($message ? '<p style="font-size:13px;color:#333;margin-top:18px;">' . nl2br(e($message)) . '</p>' : '')
    . '
    <p style="font-size:13px;color:#666;">' . e(t('email.attachment')) . '</p>
    <p style="font-size:14px;color:#333;margin-top:24px;">' . e(t('email.signature')) . '<br><strong>' . e($co['name']) . '</strong><br>' . e(t('email.team', $co['name'])) . '</p>
  </div>
  <div style="background:#f8fafc;padding:16px 32px;font-size:11px;color:#8a94a0;">'
    . e($co['name']) . ' — ' . e($co['tax_id']) . ' — ' . e($co['phone']) . ' — ' . e($co['email']) . '</div>
</div>
</body>
</html>';

    return $html;
}
