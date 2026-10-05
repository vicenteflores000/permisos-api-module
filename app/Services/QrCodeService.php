<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
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
     * Retorna el código QR en formato PNG Data URI (base64) nativamente compatible con DomPDF.
     */
    public static function pngDataUri(string $text, int $size = 120, string $ecc = 'M', int $margin = 2): string
    {
        if (extension_loaded('gd')) {
            try {
                $qr = Encoder::encode($text, self::mapEcc($ecc));
                $matrix = $qr->getMatrix();
                $w = $matrix->getWidth();
                $h = $matrix->getHeight();
                $scale = max(2, (int) round($size / max(1, $w + 2 * $margin)));
                $imgW = ($w + 2 * $margin) * $scale;
                $imgH = ($h + 2 * $margin) * $scale;

                $img = imagecreatetruecolor($imgW, $imgH);
                $white = imagecolorallocate($img, 255, 255, 255);
                $black = imagecolorallocate($img, 0, 0, 0);
                imagefilledrectangle($img, 0, 0, $imgW, $imgH, $white);

                for ($y = 0; $y < $h; $y++) {
                    for ($x = 0; $x < $w; $x++) {
                        if ($matrix->get($x, $y)) {
                            $x1 = ($x + $margin) * $scale;
                            $y1 = ($y + $margin) * $scale;
                            $x2 = $x1 + $scale - 1;
                            $y2 = $y1 + $scale - 1;
                            imagefilledrectangle($img, $x1, $y1, $x2, $y2, $black);
                        }
                    }
                }

                ob_start();
                imagepng($img);
                $pngData = ob_get_clean();

                return 'data:image/png;base64,'.base64_encode((string) $pngData);
            } catch (\Throwable) {
                // Fallback to SVG
            }
        }

        $svg = self::svg($text, $size, $ecc, $margin);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Retorna el QR codificado en formato data URI (base64) listo para atributos src de etiquetas <img> en DomPDF.
     */
    public static function dataUri(string $text, int $size = 120, string $ecc = 'M', int $margin = 2): string
    {
        return self::pngDataUri($text, $size, $ecc, $margin);
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
