<?php

namespace App\Support;

use Barryvdh\DomPDF\PDF;

class PdfFontSetup
{
    public static function ensureFontFiles(): void
    {
        $dir = storage_path('fonts/vazirmatn');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $sources = [
            'Vazirmatn-Regular.ttf' => 'https://raw.githubusercontent.com/rastikerdar/vazirmatn/master/fonts/ttf/Vazirmatn-Regular.ttf',
            'Vazirmatn-Bold.ttf' => 'https://raw.githubusercontent.com/rastikerdar/vazirmatn/master/fonts/ttf/Vazirmatn-Bold.ttf',
        ];

        foreach ($sources as $filename => $url) {
            $path = $dir.'/'.$filename;

            if (file_exists($path) && filesize($path) > 1000) {
                continue;
            }

            $context = stream_context_create([
                'http' => ['timeout' => 20],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);

            $contents = @file_get_contents($url, false, $context);

            if ($contents !== false && strlen($contents) > 1000) {
                file_put_contents($path, $contents);
            }
        }
    }

    public static function configure(PDF $pdf): void
    {
        self::ensureFontFiles();

        $fontRoot = storage_path('fonts');
        $fontCache = storage_path('fonts/dompdf-cache');

        if (! is_dir($fontCache)) {
            mkdir($fontCache, 0755, true);
        }

        $dompdf = $pdf->getDomPDF();
        $options = $dompdf->getOptions();
        $options->setFontDir($fontRoot);
        $options->setFontCache($fontCache);
        $options->setDefaultFont('vazirmatn');
        $options->setIsRemoteEnabled(false);
        $options->setChroot([$fontRoot, public_path()]);

        $regular = $fontRoot.'/vazirmatn/Vazirmatn-Regular.ttf';
        $bold = $fontRoot.'/vazirmatn/Vazirmatn-Bold.ttf';
        $fontMetrics = $dompdf->getFontMetrics();

        if (file_exists($regular)) {
            $fontMetrics->registerFont(
                ['family' => 'vazirmatn', 'style' => 'normal', 'weight' => 'normal'],
                $regular
            );
        }

        if (file_exists($bold)) {
            $fontMetrics->registerFont(
                ['family' => 'vazirmatn', 'style' => 'normal', 'weight' => 'bold'],
                $bold
            );
        }
    }
}
