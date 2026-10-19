<?php

/**
 * Référentiel des marques et modèles automobiles (liste de référence).
 *
 * Inclusion uniquement (comme functions.php).
 *
 * Ce fichier n'insère AUCUNE donnée en base : il ne fournit qu'une
 * liste de marques/modèles de référence. Les valeurs réellement
 * présentes dans la table `vehicles` sont fusionnées à la volée par
 * vehicle_brand_catalog() / vehicle_models_by_brand() (voir functions.php),
 * afin que toute marque ou tout modèle saisi auparavant reste proposé
 * dans les listes déroulantes (aucune perte, aucun doublon).
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(403); exit('Accès direct interdit.'); }

/**
 * Liste de référence : marque => modèles.
 * L'ordre des clés/éléments est celui d'affichage (tri appliqué par l'appelant).
 */
function vehicle_reference_catalog(): array
{
    static $catalog = [
        /* ---------- Marques françaises / européennes ---------- */
        'Peugeot'       => ['106', '107', '108', '205', '206', '207', '208', '2008', '301', '302', '303', '307', '308', '3008', '4007', '4008', '5008', '508', 'Rifter', 'Partner', 'Expert', 'Traveller'],
        'Renault'       => ['Twingo', 'Clio', 'Clio IV', 'Clio V', 'Captur', 'Mégane', 'Scénic', 'Laguna', 'Kangoo', 'Trafic', 'Master', 'Talisman', 'Arkana', 'Austral', 'Zoe'],
        'Citroën'       => ['2CV', 'AX', 'ZX', 'Xsara', 'X Picasso', 'Xsara Picasso', 'C3', 'C3 Aircross', 'C4', 'C4 Cactus', 'C4 Picasso', 'C5 Aircross', 'Berlingo', 'Nemo', 'Jumpy', 'Jumper', 'C-Elysée'],
        'DS'            => ['3', '4', '4S', '5', '7', '7 Crossback', '9'],
        'Dacia'         => ['Sandero', 'Sandero Stepway', 'Logan', 'Logan MCV', 'Duster', 'Duster II', 'Lodgy', 'Dokker', 'Spring'],
        'Alfa Romeo'    => ['145', '147', '156', '166', 'MiTo', 'GT', 'Giulietta', 'Giulia', 'Stelvio'],
        'Fiat'          => ['Uno', 'Panda', 'Panda Cross', 'Punto', 'Tipo', '500', '500L', 'Stilo', 'Bravo', 'Doblo', 'Multipla', 'Freemont'],
        'Lancia'        => ['Y 10', 'Ypsilon', 'Dedra', 'Kappa'],
        'Abarth'        => ['500', '595', 'Punto', '124 Spider'],
        'Alpine'        => ['A110', 'A110S', 'Rally'],

        /* ---------- Marques allemandes ---------- */
        'Volkswagen'    => ['Polo', 'Polo IV', 'Golf', 'Golf III', 'Golf IV', 'Golf V', 'Golf VI', 'Golf VII', 'Golf VIII', 'Passat', 'Passat Variant', 'Jetta', 'Touran', 'Sharan', 'Caddy', 'Tiguan', 'T-Roc', 'Phaeton', 'Amarok', 'Arteon', 'ID.3', 'ID.4'],
        'Audi'          => ['A1', 'A2', 'A3', 'A4', 'A4 Avant', 'A5', 'A6', 'A6 Avant', 'A7', 'A8', 'Q3', 'Q5', 'Q5 Sportback', 'Q7', 'Q8', 'TT', 'R8', 'e-tron'],
        'BMW'           => ['118i', 'Série 1', 'Série 2', '320i', 'Série 3', 'Série 4', '520i', 'Série 5', 'Série 6', 'Série 7', 'X1', 'X3', 'X5', 'X6', 'M3', 'M4', 'M5', 'Z3', 'Z4', 'i3', 'i8'],
        'Mercedes-Benz' => ['A 180', 'Classe A', 'Classe B', 'C 180', 'Classe C', 'E 220', 'Classe E', 'Classe S', 'GLA', 'GLC', 'GLE', 'SLS', 'Sprinter', 'Vito'],
        'Opel'          => ['Corsa', 'Astra', 'Astra F', 'Zafira', 'Meriva', 'Vectra', 'Antara', 'Mokka', 'Crossland X', 'Grandland X', 'Insignia', 'Adam'],
        'Porsche'       => ['911', 'Cayenne', 'Macan', 'Panamera', 'Boxster', 'Cayman', 'Carrera'],
        'Smart'         => ['Fortwo', 'Forfour', 'Roadster'],

        /* ---------- Marques japonaises / coréennes ---------- */
        'Toyota'        => ['Aygo', 'Yaris', 'Yaris Cross', 'Auris', 'Corolla', 'C-HR', 'RAV4', 'Land Cruiser', 'Hilux', 'Avensis', 'Verso', 'Prius', 'Starlet'],
        'Honda'         => ['Jazz', 'Civic', 'Accord', 'HR-V', 'CR-V', 'City', 'Insight', 'Legend'],
        'Nissan'        => ['Micra', 'Note', 'Tiida', 'Almera', 'Primera', 'Qashqai', 'X-Trail', 'Juke', 'Navara', 'Pathfinder', 'Leaf', '370Z'],
        'Mazda'         => ['121', '2', '3', '5', '6', 'Demio', '626', 'MX-5', 'CX-3', 'CX-5', 'BT-50'],
        'Mitsubishi'    => ['Space Star', 'Carisma', 'Lancer', 'Galant', 'Pajero', 'L200', 'Outlander', 'ASX'],
        'Suzuki'        => ['Alto', 'Swift', 'Celerio', 'Baleno', 'Ignis', 'SX4', 'Vitara', 'Jimny'],
        'Subaru'        => ['Justy', 'Impreza', 'Legacy', 'Forester', 'Outback', 'XV'],
        'Lexus'         => ['IS', 'ES', 'GS', 'NX', 'RX', 'CT', 'LX'],
        'Isuzu'         => ['Munch', 'Trooper', 'D-Max', 'NPR', 'NQR'],
        'Daihatsu'      => ['Charade', 'Sirion', 'YTer', 'Applause'],
        'Hyundai'       => ['Pony', 'Accent', 'Galloper', 'i10', 'i20', 'i30', 'i40', 'i50', 'Elantra', 'Sonata', 'Kona', 'Tucson', 'Santa Fe', 'Santa Fe II', 'H-1', 'H100', 'Terracan'],
        'Kia'           => ['Sephia', 'Carens', 'Shuma', 'Picanto', 'Rio', 'Ceed', 'Cerato', 'Optima', 'K5', 'Soul', 'Sportage', 'Sorento', 'Magentis'],

        /* ---------- Marques américaines ---------- */
        'Ford'          => ['Fiesta', 'Fiesta S', 'Escort', 'Focus', 'Focus II', 'Fusion', 'Mondeo', 'Ka', 'C-Max', 'Puma', 'Kuga', 'Escape', 'Edge', 'Explorer', 'Mustang', 'Ranger', 'Transit', 'Tourneo'],
        'Chevrolet'    => ['Matiz', 'Spark', 'Aveo', 'Lacetti', 'Nubira', 'Cruze', 'Malibu', 'Captiva', 'Trax', 'Tavera', 'Blazer', 'Suburban'],
        'Chrysler'      => ['Neon', 'PT Cruiser', 'Sebring', '300', 'Voyager', 'Grand Voyager', 'Town & Country'],
        'Jeep'          => ['Wrangler', 'Cherokee', 'Compass', 'Renegade', 'Commander', 'Grand Cherokee'],
        'Dodge'         => ['Caliber', 'Journey', 'Charger', 'Challenger', 'Durango'],
        'Cadillac'      => ['Deville', 'CTS', 'SRX', 'Escalade'],
        'Tesla'         => ['Model 3', 'Model S', 'Model X', 'Model Y'],

        /* ---------- Autres marques courantes ---------- */
        'Land Rover'    => ['Freelander', 'Defender', 'Discovery', 'Range Rover', 'Range Rover Sport', 'Range Rover Evoque'],
        'Bentley'       => ['Continental', 'Azure', 'Flying Spur', 'Mulsanne'],
        'Ferrari'       => ['360', 'F430', '458 Italia', 'California', 'F12 Berlinetta'],
        'Infiniti'      => ['G20', 'G35', 'G37', 'Q50', 'Q70'],
        'MG'            => ['MG 4', 'MG 5', 'MG 6', 'MG TF', 'MG GS', 'MG ZS', 'MG RX'],
        'Great Wall'    => ['Wingle 5', 'Steed', 'Voleex C30', 'Haval H2', 'Haval H6', 'Haval H9'],
        'Chery'         => ['QQ', 'A5', 'E3', 'Tiggo', 'Tiggo 3', 'Tiggo 4'],
        'Geely'         => ['MK', 'EC7', 'Emgrand', 'GX7', 'Emgrand X7', 'Coolray'],
        'BYD'           => ['F0', 'F3', 'F6', 'e2', 'e6', 'Song Plus', 'Dolphin', 'Atto 3'],
        'Proton'        => ['Saga', 'Wira', 'Persona', 'Exora'],
        'Perodua'       => ['Axia', 'Myvi', 'Bezza', 'Alza', 'Kembara'],
    ];

    return $catalog;
}
