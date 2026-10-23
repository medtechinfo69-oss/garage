<?php

/**
 * Générateur PDF minimaliste en PHP pur (aucune dépendance).
 * - Police standard Helvetica / Helvetica-Bold (encodage WinAnsi / CP1252)
 * - Texte, lignes, rectangles, images JPEG
 * - Pages A4 (595.28 x 841.89 pt)
 *
 * Usage :
 *   $pdf = new PdfWriter();
 *   $pdf->setFont(false, 12);
 *   $pdf->textAt(40, 800, 'Bonjour');
 *   $pdf->send('document.pdf');
 */

declare(strict_types=1);

/* Interdit l'accès direct à ce fichier (inclusion uniquement). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(403); exit('Accès direct interdit.'); }


class PdfWriter
{
    public const PAGE_W = 595.28;
    public const PAGE_H = 841.89;

    private array $pages = [];   // index => contenu du stream
    private int $cursor = 0;
    private array $images = [];  // nom => [data, width, height]
    private float $fontSize = 10;
    private bool $bold = false;

    public function __construct()
    {
        $this->addPage();
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->cursor = count($this->pages) - 1;
    }

    public function setPage(int $i): void
    {
        if ($i >= 0 && $i < count($this->pages)) {
            $this->cursor = $i;
        }
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function setFont(bool $bold, float $size = 10): void
    {
        $this->bold = $bold;
        $this->fontSize = $size;
    }

    /** Couleur du texte (0..1 par composante). Remise à noir en fin de usage. */
    public function setColor(float $r, float $g, float $b): void
    {
        $this->out(sprintf('%.3F %.3F %.3F rg', $r, $g, $b));
    }

    public function fontIsBold(): bool
    {
        return $this->bold;
    }

    public function fontSize(): float
    {
        return $this->fontSize;
    }

    /* ---------- Opérations primitives ---------- */

    private function out(string $op): void
    {
        $this->pages[$this->cursor] .= $op . "\n";
    }

    private function esc(string $s): string
    {
        $s = $this->toWinAnsi($s);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private function toWinAnsi(string $utf8): string
    {
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $utf8);
        if ($out === false) {
            $out = @mb_convert_encoding($utf8, 'CP1252', 'UTF-8');
        }
        if ($out === false) {
            $out = preg_replace('/[^\x20-\x7E]/', '', $utf8);
        }
        return (string) $out;
    }

    /** Largeur approximative d'une chaîne (en pt) avec la police courante. */
    public function textWidth(string $s): float
    {
        /* toWinAnsi() renvoie des octets CP1252 (1 octet = 1 caractère) :
           on parcourt donc la chaîne octet par octet. mb_str_split(…, 'CP1252')
           n'est pas fiable selon les builds et fausse l'alignement. */
        $s = $this->toWinAnsi($s);
        $w = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $w += self::widthOf($s[$i], $this->bold);
        }
        return $w * $this->fontSize / 1000.0;
    }

    /** Tableau des largeurs Helvetica (en millièmes d'unité). */
    public static function widthOf(string $ch, bool $bold): float
    {
        $w = 500;

        if ($ch >= 'A' && $ch <= 'Z') {
            $w = 667;
        } elseif ($ch >= 'a' && $ch <= 'z') {
            $w = 500;
        } elseif ($ch >= '0' && $ch <= '9') {
            $w = 556;
        } elseif ($ch === ' ') {
            $w = 278;
        } elseif ($ch === '.') {
            $w = 278;
        } elseif ($ch === ',') {
            $w = 278;
        } elseif ($ch === ':') {
            $w = 278;
        } elseif ($ch === ';') {
            $w = 278;
        } elseif ($ch === '/') {
            $w = 278;
        } elseif ($ch === '-') {
            $w = 333;
        } elseif ($ch === '(' || $ch === ')') {
            $w = 333;
        } elseif ($ch === '+') {
            $w = 584;
        } elseif ($ch === '=') {
            $w = 584;
        } elseif ($ch === '?') {
            $w = 556;
        } elseif ($ch === '!') {
            $w = 333;
        } elseif ($ch === "'") {
            $w = 191;
        } elseif ($ch === '"') {
            $w = 444;
        } elseif ($ch === '€') {
            $w = 556;
        } elseif ($ch === '%') {
            $w = 889;
        } elseif ($ch === '&') {
            $w = 667;
        } elseif ($ch === '@') {
            $w = 1015;
        }

        if ($bold) {
            if ($ch >= 'a' && $ch <= 'z') {
                $w = 556;
            } elseif ($ch >= '0' && $ch <= '9') {
                $w = 556;
            } elseif ($ch === ' ') {
                $w = 278;
            }
        }

        return $w;
    }

    public function textAt(float $x, float $y, string $text, ?bool $bold = null, ?float $size = null): void
    {
        $b = $bold ?? $this->bold;
        $sz = $size ?? $this->fontSize;
        $fk = $b ? 'F2' : 'F1';
        $this->out(sprintf('BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET', $fk, $sz, $x, $y, $this->esc($text)));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, float $gray = 0.6): void
    {
        $this->out(sprintf('%.2F w %.2F %.2F %.2F RG %.2F %.2F m %.2F %.2F l S', $width, $gray, $gray, $gray, $x1, $y1, $x2, $y2));
    }

    public function rect(float $x, float $y, float $w, float $h, float $gray = 0.8, bool $fill = false, float $lw = 0.5): void
    {
        $this->out(sprintf('%.2F w %.2F %.2F %.2F RG %.2F %.2F %.2F %.2F re %s', $lw, $gray, $gray, $gray, $x, $y, $w, $h, $fill ? 'f' : 'S'));
    }

    public function rectFilled(float $x, float $y, float $w, float $h, array $rgb): void
    {
        /* « rg » règle la couleur NON STROKANTE, qui sert au remplissage
           MAIS AUSSI au texte. Sans isolation, un fond (les zébrures du
           tableau) laissait la couleur courante à 0.98/0.98/0.99 : les
           lignes paires, le Total HT et la TVA s'écrivaient en
           quasi-blanc, donc invisibles sur le fond blanc de la page.
           q…Q sauvegarde et restaure l'état graphique (couleurs, police,
           matrice) : le remplissage n'affecte plus le texte suivant. */
        $this->out(sprintf('q %.2F %.2F %.2F rg %.2F %.2F %.2F %.2F re f Q', $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h));
    }

    /* ---------- Images ---------- */

    /**
     * Ajoute une image (JPEG natif, ou PNG/autre converti en JPEG si la
     * GD est disponible). Le logo de l'entreprise est un PNG : sans cette
     * conversion, il ne pouvait pas du tout être placé dans le PDF.
     */
    public function addImage(string $path, string $name): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $size = @getimagesize($path);
        if (!$size) {
            return false;
        }

        // JPEG : incorporé tel quel (aucune ré-encodage, aucune perte).
        if ($size[2] === IMAGETYPE_JPEG) {
            $this->images[$name] = [file_get_contents($path), $size[0], $size[1]];
            return true;
        }

        // PNG (et autres formats) : conversion en JPEG via GD.
        if (!function_exists('imagecreatefrompng')) {
            return false;
        }
        $img = null;
        if ($size[2] === IMAGETYPE_PNG) {
            $img = @imagecreatefrompng($path);
        } elseif ($size[2] === IMAGETYPE_GIF) {
            $img = @imagecreatefromgif($path);
        } elseif ($size[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
            $img = @imagecreatefromwebp($path);
        }
        if (!$img) {
            return false;
        }

        /* Le fond de page est blanc : on aplatit la transparence sur du
           blanc, sinon les zones transparentes virent au noir en JPEG. */
        $w = imagesx($img);
        $h = imagesy($img);
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);

        ob_start();
        imagejpeg($flat, null, 92);
        $data = (string) ob_get_clean();
        imagedestroy($flat);

        if ($data === '') {
            return false;
        }
        $this->images[$name] = [$data, $w, $h];
        return true;
    }

    /** Alias historique (JPEG uniquement). */
    public function addJpeg(string $path, string $name): bool
    {
        return $this->addImage($path, $name);
    }

    public function drawJpeg(string $name, float $x, float $y, float $w, float $h): void
    {
        $this->drawImage($name, $x, $y, $w, $h);
    }

    public function drawImage(string $name, float $x, float $y, float $w, float $h): void
    {
        if (!isset($this->images[$name])) {
            return;
        }
        $this->out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Img%s Do Q', $w, $h, $x, $y, $name));
    }

    /**
     * Dessine une image en conservant ses proportions, à l'intérieur
     * d'une boîte $boxW x $boxH, alignée à gauche et centrée verticalement
     * sur $y (bas de la boîte). Retourne la largeur réellement utilisée.
     */
    public function drawImageFit(string $name, float $x, float $y, float $boxW, float $boxH): float
    {
        if (!isset($this->images[$name])) {
            return 0.0;
        }
        [, $iw, $ih] = $this->images[$name];
        if ($iw <= 0 || $ih <= 0) {
            return 0.0;
        }
        $scale = min($boxW / $iw, $boxH / $ih);
        $dw = $iw * $scale;
        $dh = $ih * $scale;
        $dy = $y + ($boxH - $dh) / 2; // centrage vertical dans la boîte
        $this->drawImage($name, $x, $dy, $dw, $dh);
        return $dw;
    }

    /* ---------- Génération du fichier ---------- */

    public function build(): string
    {
        $n = count($this->pages);
        $objs = []; // numéro => corps sans "N 0 obj" / "endobj"

        // 1 : Catalogue
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        // 2 : Pages
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (3 + 2 * $i) . ' 0 R';
        }
        $objs[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $n >>";

        $firstFont = 3 + 2 * $n;
        $hasImages = count($this->images) > 0;

        for ($i = 0; $i < $n; $i++) {
            /* Attention à l'imbrication : /Font et /XObject sont des
               dictionnaires imbriqués DANS /Resources. Chacun doit être
               refermé, puis /Resources lui-même. Un « >> » manquant ici
               faisait absorber /Contents par /Resources : la page n'avait
               plus de /Contents de premier niveau et restait blanche. */
            $resources = "<< /ProcSet [/PDF /Text /ImageB /ImageC /ImageI] /Font << /F1 {$firstFont} 0 R /F2 " . ($firstFont + 1) . " 0 R >>";
            if ($hasImages) {
                $resources .= ' /XObject <<';
                foreach (array_keys($this->images) as $j => $name) {
                    $resources .= ' /Img' . $name . ' ' . ($firstFont + 2 + $j) . ' 0 R';
                }
                $resources .= ' >>';
            }
            $resources .= ' >>';
            $objs[3 + 2 * $i] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::PAGE_W . ' ' . self::PAGE_H . "] /Resources " . $resources . " /Contents " . (4 + 2 * $i) . " 0 R >>";
            $stream = $this->pages[$i];
            $objs[4 + 2 * $i] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        $objs[$firstFont] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objs[$firstFont + 1] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $fn = $firstFont + 2;
        foreach ($this->images as $name => [$data, $w, $h]) {
            $objs[$fn] = "<< /Type /XObject /Subtype /Image /Width " . $w . " /Height " . $h
                . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($data) . " >>\nstream\n"
                . $data . "\nendstream";
            $fn++;
        }

        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }

        $max = max(array_keys($offsets));
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . ($max + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";
        return $pdf;
    }

    /** Téléchargement direct. */
    public function send(string $filename): void
    {
        $pdf = $this->build();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $pdf;
        exit;
    }
}
