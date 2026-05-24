<?php

namespace App\Support;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * Builds an mPDF instance pre-configured for Persian/RTL output using the
 * self-hosted Vazirmatn font, so generated invoices match the on-screen UI
 * and render correctly offline (no external font fetch).
 */
class Pdf
{
    public static function make(): Mpdf
    {
        $tmp = storage_path('app/mpdf-tmp');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }

        $fontDirs = (new ConfigVariables)->getDefaults()['fontDir'];
        $fontData = (new FontVariables)->getDefaults()['fontdata'];

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'directionality' => 'rtl',
            'tempDir' => $tmp,
            'fontDir' => array_merge($fontDirs, [storage_path('fonts')]),
            'fontdata' => $fontData + [
                'vazirmatn' => [
                    'R' => 'Vazirmatn-Regular.ttf',
                    'B' => 'Vazirmatn-Bold.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
            ],
            'default_font' => 'vazirmatn',
        ]);

        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;

        return $mpdf;
    }

    /** Render a Blade view to a downloadable PDF string. */
    public static function fromView(string $view, array $data = []): string
    {
        $pdf = self::make();
        $pdf->WriteHTML(view($view, $data)->render());

        return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
