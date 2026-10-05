<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrCodeService
{
    /**
     * Genera el código QR para el texto dado y lo retorna como SVG vectorial puro.
     */
    public static function svg(string $text, int $size = 120, string $ecc = 'M', int $margin = 2): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, $margin),
            new SvgImageBackEnd
        );
        $writer = new Writer($renderer);

        $svg = $writer->writeString($text, 'UTF-8', self::mapEcc($ecc));

        // Remover prólogo XML para permitir inserción limpia e inline
        $cleanSvg = preg_replace('/<\?xml.*?\?>/i', '', $svg) ?? $svg;

        return trim($cleanSvg);
    }

    /**
     * Retorna el SVG codificado en formato data URI (base64) listo para atributos src de etiquetas <img> en DomPDF.
     */
    public static function dataUri(string $text, int $size = 120, string $ecc = 'M', int $margin = 2): string
    {
        $svg = self::svg($text, $size, $ecc, $margin);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private static function mapEcc(string $ecc): ErrorCorrectionLevel
    {
        return match (strtoupper($ecc)) {
            'L' => ErrorCorrectionLevel::L(),
            'Q' => ErrorCorrectionLevel::Q(),
            'H' => ErrorCorrectionLevel::H(),
            default => ErrorCorrectionLevel::M(),
        };
    }
}
