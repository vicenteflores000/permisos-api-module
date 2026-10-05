<?php

namespace App\Services;

use App\Models\PermisoSolicitud;
use Barryvdh\DomPDF\Facade\Pdf;

class PdfService
{
    /**
     * Generar el PDF inalterable del permiso administrativo con código QR incrustado.
     */
    public function generarPdf(PermisoSolicitud $solicitud, ?string $validationUrl = null, ?string $qrDataUri = null): \Barryvdh\DomPDF\PDF
    {
        $solicitud->load(['firmasAprobadas']);

        if (empty($validationUrl)) {
            $insamuUrl = rtrim((string) (config('services.insamu.url') ?: env('INSAMU_URL') ?: env('INSAMU_BASE_URL') ?: 'https://salud.mdonihue.cl'), '/');
            $validationUrl = "{$insamuUrl}/permisos/validar/{$solicitud->id}";
        }

        if (empty($qrDataUri)) {
            $qrDataUri = QrCodeService::dataUri($validationUrl, 100, 'M', 2);
        }

        $pdf = Pdf::loadView('pdf.permiso', [
            'solicitud' => $solicitud,
            'validationUrl' => $validationUrl,
            'qrDataUri' => $qrDataUri,
        ]);

        $pdf->setPaper('letter', 'portrait');
        $pdf->setOption([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => false,
            'defaultFont' => 'Helvetica',
        ]);

        return $pdf;
    }
}
