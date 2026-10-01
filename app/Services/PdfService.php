<?php

namespace App\Services;

use App\Models\PermisoSolicitud;
use Barryvdh\DomPDF\Facade\Pdf;

class PdfService
{
    /**
     * Generar el PDF inalterable del permiso administrativo.
     */
    public function generarPdf(PermisoSolicitud $solicitud): \Barryvdh\DomPDF\PDF
    {
        $solicitud->load(['firmasAprobadas']);

        $pdf = Pdf::loadView('pdf.permiso', [
            'solicitud' => $solicitud,
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
