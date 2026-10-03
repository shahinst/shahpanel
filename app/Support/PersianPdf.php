<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use TCPDF;
use TCPDF_FONTS;

/**
 * Renders a Blade view to PDF with TCPDF (LGPL-3.0, compatible with the
 * panel's AGPL-3.0 licence). TCPDF joins Persian letters and lays out
 * right-to-left text itself; Vazirmatn ships with the panel (resources/fonts)
 * and is converted to TCPDF's font format once, into storage.
 *
 * Persian pages use the Farsi-digit cut of the font, so any Latin digit that
 * slips through still prints as a Persian one. Other languages print in
 * DejaVu Sans, Chinese in TCPDF's built-in CJK font.
 */
class PersianPdf
{
    /** Bump when the bundled font files change, so stale conversions are not reused. */
    private const FONT_CACHE_VERSION = 'v1-fd';

    /**
     * @param  array<string, mixed>  $data  pdfTitle and pdfFooter are read from here
     */
    public static function render(string $view, array $data = [], string $orientation = 'P'): string
    {
        $persian = locale_digits() === 'fa';
        $rtl = locale_is_rtl();
        $fontFiles = $persian ? self::vazirmatn() : [];
        $font = match (true) {
            $persian => 'vazirmatn',
            str_starts_with(app()->getLocale(), 'zh') => 'cid0cs',
            default => 'dejavusans',
        };

        $footer = (string) ($data['pdfFooter'] ?? (string) config('app.name'));
        $pageLabel = __('accounting.pdf_page', ['page' => '{p}', 'pages' => '{n}']);

        $pdf = new class($orientation, 'mm', 'A4', true, 'UTF-8') extends TCPDF
        {
            public string $footerText = '';

            public string $pageLabel = '';

            public string $footerFont = 'dejavusans';

            public function Footer(): void
            {
                $this->SetY(-12);
                $this->SetFont($this->footerFont, '', 8);
                $this->SetTextColor(156, 163, 175);
                $this->SetDrawColor(229, 231, 235);
                $this->Line($this->lMargin, $this->GetY(), $this->getPageWidth() - $this->rMargin, $this->GetY());
                $page = strtr($this->pageLabel, [
                    '{p}' => $this->getAliasNumPage(),
                    '{n}' => $this->getAliasNbPages(),
                ]);
                $half = ($this->getPageWidth() - $this->lMargin - $this->rMargin) / 2;
                $this->Cell($half, 8, $this->footerText, 0, 0, $this->getRTL() ? 'R' : 'L');
                $this->Cell($half, 8, $page, 0, 0, $this->getRTL() ? 'L' : 'R');
            }
        };

        foreach ($fontFiles as $style => $file) {
            $pdf->AddFont('vazirmatn', $style, $file);
        }

        $pdf->footerText = $footer;
        $pdf->pageLabel = $pageLabel;
        $pdf->footerFont = $font;

        $pdf->SetCreator((string) config('app.name'));
        $pdf->SetAuthor((string) config('app.name'));
        $pdf->SetTitle((string) ($data['pdfTitle'] ?? config('app.name')));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->setFontSubsetting(true);
        $pdf->setRTL($rtl);
        $pdf->SetFont($font, '', 10);
        $pdf->AddPage();
        $pdf->writeHTML(view($view, $data)->render(), true, false, true, false, '');
        $pdf->lastPage();

        return $pdf->Output('', 'S');
    }

    /**
     * Converts the bundled Vazirmatn (Farsi digits) to TCPDF's format on first
     * use. Returns the converted definition file of each style.
     *
     * @return array<string, string>
     */
    protected static function vazirmatn(): array
    {
        $dir = storage_path('app/tcpdf-fonts/'.self::FONT_CACHE_VERSION).'/';

        if (! is_dir($dir)) {
            File::ensureDirectoryExists($dir, 0775);
        }

        $files = [];

        foreach (['' => 'Vazirmatn-FD-Regular.ttf', 'B' => 'Vazirmatn-FD-Bold.ttf'] as $style => $file) {
            $name = $style === '' ? 'vazirmatn' : 'vazirmatnb';
            $files[$style] = $dir.$name.'.php';

            if (! is_file($dir.$name.'.php')) {
                $converted = TCPDF_FONTS::addTTFfont(resource_path('fonts/vazirmatn/'.$file), 'TrueTypeUnicode', '', 96, $dir);

                // TCPDF names the files after the source file; give them the
                // stable names the family below points at.
                if (is_string($converted) && $converted !== $name) {
                    foreach (['.php', '.z', '.ctg.z'] as $ext) {
                        if (is_file($dir.$converted.$ext)) {
                            rename($dir.$converted.$ext, $dir.$name.$ext);
                        }
                    }
                    $php = (string) file_get_contents($dir.$name.'.php');
                    file_put_contents($dir.$name.'.php', str_replace(
                        ["'".$converted.".z'", "'".$converted.".ctg.z'"],
                        ["'".$name.".z'", "'".$name.".ctg.z'"],
                        $php
                    ));
                }
            }
        }

        return $files;
    }
}
