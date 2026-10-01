<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Permiso Administrativo #{{ $solicitud->id }} - INSAMU</title>
    <style>
        @page {
            margin: 25mm 20mm 25mm 20mm;
        }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 11pt;
            color: #1a202c;
            line-height: 1.5;
            margin: 0;
            padding: 0;
        }
        .header {
            border-bottom: 2px solid #2b6cb0;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .institution-title {
            font-size: 16pt;
            font-weight: bold;
            color: #2b6cb0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .institution-subtitle {
            font-size: 9pt;
            color: #4a5568;
            margin-top: 2px;
        }
        .doc-folio {
            float: right;
            text-align: right;
            font-size: 10pt;
            color: #4a5568;
        }
        .folio-number {
            font-size: 13pt;
            font-weight: bold;
            color: #2d3748;
        }
        .title-box {
            text-align: center;
            background-color: #ebf8ff;
            border: 1px solid #bee3f8;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 20px;
        }
        .title-box h1 {
            margin: 0;
            font-size: 14pt;
            color: #2b6cb0;
            text-transform: uppercase;
        }
        .status-badge {
            display: inline-block;
            margin-top: 5px;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 9pt;
            font-weight: bold;
            text-transform: uppercase;
            background-color: #e2e8f0;
            color: #2d3748;
        }
        .status-decretado { background-color: #c6f6d5; color: #22543d; }
        .status-en_rrhh { background-color: #feebc8; color: #744210; }
        .status-pendiente { background-color: #fed7d7; color: #742a2a; }
        
        .section-title {
            font-size: 11pt;
            font-weight: bold;
            color: #2b6cb0;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 4px;
            margin-top: 15px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        table.data-table th, table.data-table td {
            padding: 6px 8px;
            text-align: left;
            font-size: 10pt;
            border: 1px solid #e2e8f0;
        }
        table.data-table th {
            background-color: #f7fafc;
            color: #4a5568;
            font-weight: bold;
            width: 25%;
        }
        
        /* Estampilla de Firma Electrónica Simple (FES) */
        .stamp-container {
            margin-top: 20px;
            page-break-inside: avoid;
        }
        .stamp-box {
            border: 2px solid #2b6cb0;
            border-left: 6px solid #2b6cb0;
            background-color: #f7fafc;
            border-radius: 4px;
            padding: 12px 14px;
            margin-bottom: 12px;
        }
        .stamp-header {
            font-size: 9pt;
            font-weight: bold;
            color: #2b6cb0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            border-bottom: 1px dashed #cbd5e0;
            padding-bottom: 4px;
        }
        .stamp-glosa {
            font-size: 9.5pt;
            font-weight: 600;
            color: #1a202c;
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 8px 10px;
            border-radius: 3px;
            margin-bottom: 6px;
            line-height: 1.4;
        }
        .stamp-details {
            font-size: 8.5pt;
            color: #4a5568;
        }
        .stamp-details span {
            margin-right: 15px;
        }
        .stamp-subrogante {
            color: #c53030;
            font-weight: bold;
        }
        
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            font-size: 8pt;
            color: #718096;
            text-align: center;
            border-top: 1px solid #e2e8f0;
            padding-top: 5px;
        }
        .clear {
            clear: both;
        }
    </style>
</head>
<body>

    <div class="header">
        <div class="doc-folio">
            Folio Solicitud: <span class="folio-number">#{{ str_pad($solicitud->id, 6, '0', STR_PAD_LEFT) }}</span><br>
            Fecha Emisión: {{ now()->format('d/m/Y H:i') }}
        </div>
        <div class="institution-title">INSAMU</div>
        <div class="institution-subtitle">Módulo de Permisos Administrativos y Gestión de Personas</div>
        <div class="clear"></div>
    </div>

    <div class="title-box">
        <h1>Certificado de Solicitud de Permiso Administrativo</h1>
        <div class="status-badge status-{{ in_array($solicitud->estado, ['decretado', 'en_rrhh']) ? $solicitud->estado : 'pendiente' }}">
            Estado: {{ strtoupper(str_replace('_', ' ', $solicitud->estado)) }}
        </div>
    </div>

    <div class="section-title">I. Identificación del Funcionario Solicitante</div>
    <table class="data-table">
        <tr>
            <th>Nombre Completo</th>
            <td>{{ $solicitud->nombre_solicitante ?? 'Funcionario INSAMU (ID: '.$solicitud->insamu_user_id.')' }}</td>
            <th>RUT</th>
            <td>{{ $solicitud->rut_solicitante ?? 'No registrado' }}</td>
        </tr>
        <tr>
            <th>Cargo</th>
            <td>{{ $solicitud->cargo_solicitante ?? 'Personal de Planta / Contrata' }}</td>
            <th>Unidad / Depto.</th>
            <td>{{ $solicitud->unidad_solicitante ?? 'INSAMU' }}</td>
        </tr>
    </table>

    <div class="section-title">II. Detalle del Permiso Solicitado</div>
    <table class="data-table">
        <tr>
            <th>Tipo de Permiso</th>
            <td>{{ $solicitud->tipo_permiso }}</td>
            <th>Días Solicitados</th>
            <td><strong>{{ $solicitud->dias_solicitados }} día(s)</strong></td>
        </tr>
        <tr>
            <th>Fecha Inicio</th>
            <td>{{ \Carbon\Carbon::parse($solicitud->fecha_inicio)->format('d/m/Y') }}</td>
            <th>Fecha Término</th>
            <td>{{ \Carbon\Carbon::parse($solicitud->fecha_fin)->format('d/m/Y') }}</td>
        </tr>
        @if($solicitud->motivo)
        <tr>
            <th>Motivo / Observaciones</th>
            <td colspan="3">{{ $solicitud->motivo }}</td>
        </tr>
        @endif
        @if($solicitud->decreto_numero)
        <tr>
            <th>Decreto Exento N°</th>
            <td><strong>{{ $solicitud->decreto_numero }}</strong></td>
            <th>Fecha Decreto</th>
            <td>{{ $solicitud->fecha_decreto ? \Carbon\Carbon::parse($solicitud->fecha_decreto)->format('d/m/Y') : '-' }}</td>
        </tr>
        @endif
    </table>

    <div class="section-title">III. Estampillas de Firma Electrónica Simple (FES) - INSAMU</div>
    <div class="stamp-container">
        @forelse($solicitud->firmasAprobadas as $firma)
            @php
                $nombreFirmante = $firma->nombre_visador ?? 'Visador Autorizado (ID: '.$firma->insamu_visador_id.')';
                $rutFirmante = $firma->rut_visador ?? 'No registrado';
                $fechaFirma = $firma->fecha_accion ? $firma->fecha_accion->format('d/m/Y') : now()->format('d/m/Y');
                $horaFirma = $firma->fecha_accion ? $firma->fecha_accion->format('H:i') : now()->format('H:i');
                
                $cargoBase = $firma->cargo_visador ?: $firma->rol_firma;
                $cargoConSubrogancia = $firma->es_subrogante ? "{$cargoBase} Subrogante" : $cargoBase;
            @endphp
            <div class="stamp-box">
                <div class="stamp-header">
                    [FES-INSAMU] Estampilla Oficial de Visación - Rol: {{ $firma->rol_firma }}
                    @if($firma->es_subrogante)
                        <span class="stamp-subrogante">(Subrogancia)</span>
                    @endif
                </div>
                <div class="stamp-glosa">
                    Documento firmado electrónicamente mediante autenticación de PIN en INSAMU por {{ $nombreFirmante }}, RUT {{ $rutFirmante }} con fecha {{ $fechaFirma }} a las {{ $horaFirma }}.
                </div>
                <div class="stamp-details">
                    <span><strong>Cargo:</strong> {{ $cargoConSubrogancia }}</span>
                    <span><strong>ID Visador INSAMU:</strong> {{ $firma->insamu_visador_id }}</span>
                    <span><strong>Firma ID:</strong> #{{ $firma->id }}</span>
                </div>
            </div>
        @empty
            <p style="font-style: italic; color: #718096; padding: 10px; background-color: #f7fafc; border: 1px dashed #cbd5e0; text-align: center;">
                Este documento aún no cuenta con firmas electrónicas aprobadas.
            </p>
        @endforelse
    </div>

    <div class="footer">
        Documento emitido y custodiado por la API de Permisos INSAMU bajo protocolo de clave simétrica y autenticación PIN FES.
        Página 1 de 1 - Código de Verificación: {{ hash('sha256', 'INSAMU_'.$solicitud->id.'_'.$solicitud->created_at) }}
    </div>

</body>
</html>
