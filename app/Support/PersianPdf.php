<?php

namespace App\Support;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * Renders a Blade view to PDF with mPDF. dompdf, used before, neither joins
 * Persian letters nor lays out right-to-left text, so every Persian word came
 * out as separate, reversed glyphs. mPDF shapes Persian properly; Vazirmatn
 * ships with the panel (resources/fonts) so nothing is downloaded at runtime.
 *
 * Persian pages use the Farsi-digit cut of the font, so any Latin digit that
 * slips through still prints as a Persian one; other languages print in
 * DejaVu Sans.
 */
class PersianPdf
{
    private const FONT_CACHE_VERSION = 'v33-fd';

    /**
     * @param  array<string, mixed>  $data
     */
    public static function render(string $view, array $data = [], string $orientation = 'P'): string
    {
        $persian = locale_digits() === 'fa';
        $rtl = locale_is_rtl();
        // mPDF caches parsed font tables by family name. The folder is
        // versioned so changing the bundled font files can never reuse tables
        // parsed from the old ones (which fails with "GPOS lookup not supported").
        $tempDir = storage_path('app/mpdf/'.self::FONT_CACHE_VERSION);

        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $defaultConfig = (new ConfigVariables())->getDefaults();
        $defaultFonts = (new FontVariables())->getDefaults();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => $orientation,
            'tempDir' => $tempDir,
            'fontDir' => array_merge($defaultConfig['fontDir'], [resource_path('fonts/vazirmatn')]),
            'fontdata' => $defaultFonts['fontdata'] + [
                // The Farsi-digit cut only: the Latin-digit file carries an
                // OpenType table mPDF cannot read.
                'vazirmatn' => [
                    'R' => 'Vazirmatn-FD-Regular.ttf',
                    'B' => 'Vazirmatn-FD-Bold.ttf',
                    'useOTL' => 0x80,
                    'useKashida' => 75,
                ],
            ],
            // Other languages print in DejaVu Sans (Latin, Cyrillic) and let
            // mPDF pick a font for scripts it lacks, such as Chinese.
            'default_font' => $persian ? 'vazirmatn' : 'dejavusans',
            'directionality' => $rtl ? 'rtl' : 'ltr',
            'autoScriptToLang' => true,
            'autoLangToFont' => ! $persian,
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 16,
            'margin_footer' => 6,
        ]);

        $mpdf->SetTitle((string) ($data['pdfTitle'] ?? config('app.name')));
        $mpdf->SetCreator((string) config('app.name'));
        $mpdf->WriteHTML(view($view, $data)->render());

        return $mpdf->Output('', 'S');
    }
}
