<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INSAMU - Gestor de Saldos de Recursos Humanos</title>
    <!-- Tailwind CSS CDN para renderizado inmediato y compatibilidad -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .dropzone-active { border-color: #3b82f6; background-color: #eff6ff; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen">

    <!-- Topbar Institucional -->
    <header class="bg-indigo-900 text-white shadow-md border-b border-indigo-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3.5 flex flex-wrap justify-between items-center gap-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-xl bg-indigo-700 flex items-center justify-center font-black text-xl text-indigo-200 border border-indigo-500 shadow-inner">
                    IN
                </div>
                <div>
                    <h1 class="text-lg font-bold tracking-tight text-white flex items-center gap-2">
                        INSAMU
                        <span class="text-xs bg-indigo-800 text-indigo-200 px-2.5 py-0.5 rounded-full font-medium border border-indigo-700">Módulo RRHH</span>
                    </h1>
                    <p class="text-xs text-indigo-300">Gestor de Saldos Anuales y Compensaciones de Tiempo</p>
                </div>
            </div>

            <!-- Parámetros de Sesión RRHH -->
            <div class="flex items-center gap-3 text-xs bg-indigo-950/60 px-3.5 py-2 rounded-lg border border-indigo-800/80">
                <div class="flex items-center gap-1.5">
                    <span class="text-indigo-400 font-medium">Oficial RRHH:</span>
                    <input type="text" id="insamuRrhhUserId" value="RRHH-PANCHO" class="bg-indigo-900/80 border border-indigo-700 rounded px-2 py-1 text-white font-mono focus:outline-none focus:ring-1 focus:ring-indigo-400 w-28">
                </div>
                <div class="h-4 w-px bg-indigo-700"></div>
                <div class="flex items-center gap-1.5">
                    <span class="text-indigo-400 font-medium">Año:</span>
                    <select id="anioGlobal" class="bg-indigo-900/80 border border-indigo-700 rounded px-2 py-1 text-white font-bold focus:outline-none focus:ring-1 focus:ring-indigo-400">
                        <option value="2026" selected>2026</option>
                        <option value="2025">2025</option>
                        <option value="2027">2027</option>
                    </select>
                </div>
                <input type="hidden" id="apiSecretKey" value="{{ config('insamu.api_secret_key', 'insamu_secret_key_panchitowekito') }}">
            </div>
        </div>
    </header>

    <!-- Barra de Navegación de Submódulos (Tabs) -->
    <div class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex space-x-1 sm:space-x-8">
            <button onclick="cambiarTab('inicializacion')" id="tabBtn-inicializacion" class="tab-btn border-b-2 border-indigo-600 text-indigo-600 py-3.5 px-3 text-sm font-semibold flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                1. Inicialización de Permisos
            </button>
            <button onclick="cambiarTab('abonos')" id="tabBtn-abonos" class="tab-btn border-b-2 border-transparent text-slate-500 hover:text-slate-700 py-3.5 px-3 text-sm font-semibold flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                2. Abono de Compensaciones
            </button>
            <button onclick="cambiarTab('simulador')" id="tabBtn-simulador" class="tab-btn border-b-2 border-transparent text-slate-500 hover:text-slate-700 py-3.5 px-3 text-sm font-semibold flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                3. Formulario Funcionario (Bloqueo Preventivo)
            </button>
        </div>
    </div>

    <!-- Contenido Principal -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- ============================================================== -->
        <!-- SUBMÓDULO 1: INICIALIZACIÓN DE PERMISOS                         -->
        <!-- ============================================================== -->
        <div id="tab-inicializacion" class="tab-content space-y-8">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                <div>
                    <h2 class="text-xl font-bold text-slate-900">Inicialización Anual de Cupos (CESFAM / DESAM)</h2>
                    <p class="text-sm text-slate-500 mt-1">Configure los topes de <strong>Permisos Administrativos</strong> y <strong>Feriados Legales (Vacaciones)</strong> de forma individual o mediante planilla masiva.</p>
                </div>
                <a href="/rrhh/plantilla-saldos.csv" class="inline-flex items-center gap-2 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-semibold transition border border-slate-300">
                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Descargar Plantilla CSV
                </a>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                <!-- Columna Izquierda: Asignación Individual -->
                <div class="lg:col-span-5 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-5">
                    <div class="border-b border-slate-100 pb-3">
                        <h3 class="font-bold text-slate-900 flex items-center gap-2">
                            <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-xs flex items-center justify-center font-bold">1</span>
                            Asignación Individual por Funcionario
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5">Asigne o modifique los topes para un funcionario específico.</p>
                    </div>

                    <form id="formInicializarIndividual" onsubmit="inicializarIndividual(event)" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">ID / RUT del Funcionario</label>
                            <div class="flex gap-2">
                                <input type="text" id="indivUserId" required placeholder="Ej: MEDICO-01 o 15.420.312-K" class="flex-1 px-3.5 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
                                <button type="button" onclick="consultarEstadoFuncionario()" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-bold border border-slate-300">Ver</button>
                            </div>
                        </div>

                        <div id="indivEstadoPrevia" class="hidden p-3 bg-slate-50 rounded-lg border border-slate-200 text-xs space-y-1">
                            <span class="font-semibold text-slate-700">Estado actual en BD:</span>
                            <div id="indivEstadoDetalles" class="text-slate-600"></div>
                        </div>

                        <div class="p-3 bg-blue-50/80 border border-blue-200 rounded-xl text-xs space-y-1">
                            <div class="font-bold text-blue-900 flex items-center gap-1.5">
                                <span>🤖</span>
                                <span>Días Administrativos Automatizados</span>
                            </div>
                            <p class="text-blue-700 leading-relaxed text-[11px]">
                                El sistema asigna automáticamente <strong>6 días administrativos</strong> cada 1 de enero a las 00:00 vía Cron Job, con caducidad estricta al 31 de diciembre. No requiere carga manual de RRHH.
                            </p>
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Tope Feriado Legal (Vacaciones)</label>
                            <div class="relative">
                                <input type="number" step="0.5" min="0" max="60" id="indivDiasFeriado" value="15.0" required class="w-full px-3.5 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                                <span class="absolute right-3 top-2.5 text-xs text-slate-400">días</span>
                            </div>
                        </div>

                        <!-- Botones rápidos de feriado legal según antigüedad -->
                        <div class="flex items-center gap-1.5 text-xs text-slate-500">
                            <span>Topes habituales:</span>
                            <button type="button" onclick="document.getElementById('indivDiasFeriado').value = 15.0" class="px-2 py-0.5 bg-slate-100 hover:bg-slate-200 rounded text-slate-700 font-semibold border border-slate-200">15 d (Base)</button>
                            <button type="button" onclick="document.getElementById('indivDiasFeriado').value = 20.0" class="px-2 py-0.5 bg-slate-100 hover:bg-slate-200 rounded text-slate-700 font-semibold border border-slate-200">20 d (&gt;15 años)</button>
                            <button type="button" onclick="document.getElementById('indivDiasFeriado').value = 25.0" class="px-2 py-0.5 bg-slate-100 hover:bg-slate-200 rounded text-slate-700 font-semibold border border-slate-200">25 d (&gt;20 años)</button>
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Compensación Inicial (Opcional)</label>
                            <div class="relative">
                                <input type="number" step="0.5" min="0" id="indivHorasComp" value="0.0" class="w-full px-3.5 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                                <span class="absolute right-3 top-2.5 text-xs text-slate-400">horas</span>
                            </div>
                        </div>

                        <button type="submit" id="btnGuardarIndividual" class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-lg text-sm shadow transition flex items-center justify-center gap-2">
                            <span>Inicializar Cupo del Funcionario</span>
                        </button>
                    </form>
                    <div id="msgIndividual" class="hidden text-xs p-3 rounded-lg"></div>
                </div>

                <!-- Columna Derecha: Zona Drag & Drop Carga Masiva -->
                <div class="lg:col-span-7 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-5">
                    <div class="border-b border-slate-100 pb-3 flex justify-between items-center">
                        <div>
                            <h3 class="font-bold text-slate-900 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-700 text-xs flex items-center justify-center font-bold">2</span>
                                Carga Masiva de Planilla (Drag & Drop)
                            </h3>
                            <p class="text-xs text-slate-500 mt-0.5">Sube una planilla CSV o Excel exportada del sistema de personal.</p>
                        </div>
                    </div>

                    <!-- Dropzone -->
                    <div id="dropzone" ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDrop(event)" onclick="document.getElementById('csvFileInput').click()" class="border-2 border-dashed border-slate-300 hover:border-indigo-500 rounded-2xl p-8 text-center cursor-pointer transition bg-slate-50/50 hover:bg-indigo-50/30">
                        <input type="file" id="csvFileInput" accept=".csv,.txt" class="hidden" onchange="handleFileSelect(event)">
                        <div class="w-14 h-14 mx-auto rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center mb-3 border border-indigo-100">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        </div>
                        <p class="text-sm font-bold text-slate-700">Arrastra tu planilla CSV / Excel aquí o <span class="text-indigo-600 underline">haz clic para examinar</span></p>
                        <p class="text-xs text-slate-400 mt-1">Formato soportado: CSV (delimitado por coma o punto y coma) con encabezados <code>insamu_user_id, dias_administrativos, dias_feriado_legal</code></p>
                        <p class="text-xs text-slate-400 mt-1">Formato soportado: CSV (delimitado por coma o punto y coma) con encabezados <code>insamu_user_id, dias_feriado_legal, horas_compensacion</code> (Los días administrativos se gestionan por Cron)</p>
                    </div>

                    <!-- Previsualización de Datos Cargados -->
                    <div id="previewContainer" class="hidden space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-bold text-slate-700 flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                Planilla detectada: <strong id="previewFileName" class="text-indigo-700"></strong> (<span id="previewCount">0</span> funcionarios)
                            </span>
                            <button type="button" onclick="cancelarArchivo()" class="text-xs text-rose-600 hover:underline">Quitar archivo</button>
                        </div>

                        <div class="overflow-x-auto max-h-56 rounded-lg border border-slate-200">
                            <table class="w-full text-xs text-left text-slate-600">
                                <thead class="bg-slate-100 text-slate-700 uppercase font-semibold sticky top-0">
                                    <tr>
                                        <th class="px-3 py-2">ID Funcionario</th>
                                        <th class="px-3 py-2">Año</th>
                                        <th class="px-3 py-2">Administrativos</th>
                                        <th class="px-3 py-2">Feriado Legal</th>
                                        <th class="px-3 py-2">Compensación</th>
                                        <th class="px-3 py-2">Días Admin.</th>
                                    </tr>
                                </thead>
                                <tbody id="previewTableBody" class="divide-y divide-slate-200 bg-white"></tbody>
                            </table>
                        </div>

                        <button type="button" id="btnProcesarMasivo" onclick="procesarCargaMasiva()" class="w-full py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-sm shadow transition flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span>Procesar e Inyectar Masivamente a todo el Personal</span>
                        </button>
                    </div>

                    <div id="msgMasivo" class="hidden text-xs p-4 rounded-xl"></div>
                </div>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- SUBMÓDULO 2: ABONO DE COMPENSACIONES (HORAS EXTRAORDINARIAS)    -->
        <!-- ============================================================== -->
        <div id="tab-abonos" class="tab-content hidden space-y-6">
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <h2 class="text-xl font-bold text-slate-900">Abono Mensual de Compensación de Tiempo</h2>
                    <p class="text-sm text-slate-500 mt-1">Inyecte de forma transaccional los días u horas a favor ganados por funcionarios por turnos extraordinarios o refuerzos.</p>
                </div>
                <div class="px-3 py-1.5 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-800 font-semibold flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Operación acumulativa: suma al saldo existente sin sobrescribir.
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                <!-- Formulario de Abono Rápido -->
                <div class="lg:col-span-5 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                    <h3 class="font-bold text-slate-900 border-b border-slate-100 pb-3">Inyección Individual de Horas</h3>

                    <form id="formAbonoHoras" onsubmit="ejecutarAbonoHoras(event)" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">ID / RUT del Funcionario</label>
                            <input type="text" id="abonoUserId" required placeholder="Ej: ENFERMERA-02" class="w-full px-3.5 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Horas a Favor a Inyectar</label>
                            <div class="relative">
                                <input type="number" step="0.5" min="0.5" id="abonoHoras" placeholder="Ej: 4.5 u 8.0" required class="w-full px-3.5 py-2 border border-slate-300 rounded-lg text-sm font-bold text-slate-800 focus:ring-2 focus:ring-indigo-500 outline-none">
                                <span class="absolute right-3 top-2.5 text-xs text-slate-400">horas</span>
                            </div>
                            <div class="flex gap-1.5 mt-1.5">
                                <button type="button" onclick="document.getElementById('abonoHoras').value = 4.0" class="px-2 py-0.5 bg-slate-100 hover:bg-slate-200 rounded text-slate-600 text-xs border border-slate-200">+4.0 hrs (Medio turno)</button>
                                <button type="button" onclick="document.getElementById('abonoHoras').value = 8.0" class="px-2 py-0.5 bg-slate-100 hover:bg-slate-200 rounded text-slate-600 text-xs border border-slate-200">+8.0 hrs (Turno completo)</button>
                                <button type="button" onclick="document.getElementById('abonoHoras').value = 12.0" class="px-2 py-0.5 bg-slate-100 hover:bg-slate-200 rounded text-slate-600 text-xs border border-slate-200">+12.0 hrs (Turno nocturno)</button>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Motivo / Justificación del Abono</label>
                            <select id="abonoMotivoSelect" onchange="if(this.value) document.getElementById('abonoMotivo').value = this.value" class="w-full px-3 py-1.5 border border-slate-300 rounded-lg text-xs mb-1.5 bg-slate-50">
                                <option value="">-- Seleccionar motivo habitual --</option>
                                <option value="Turno extraordinario fin de semana operativo">Turno extraordinario fin de semana operativo</option>
                                <option value="Refuerzo campaña de vacunación e invierno">Refuerzo campaña de vacunación e invierno</option>
                                <option value="Turno de reemplazo no programado en Urgencia/SAR">Turno de reemplazo no programado en Urgencia/SAR</option>
                                <option value="Extensión horaria operativa mensual">Extensión horaria operativa mensual</option>
                            </select>
                            <input type="text" id="abonoMotivo" required placeholder="Especifique el motivo para auditoría..." class="w-full px-3.5 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                        </div>

                        <button type="submit" id="btnAbonar" class="w-full py-2.5 px-4 bg-amber-600 hover:bg-amber-700 text-white font-bold rounded-lg text-sm shadow transition flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                            <span>Abonar Horas a Favor</span>
                        </button>
                    </form>
                    <div id="msgAbono" class="hidden text-xs p-3 rounded-lg"></div>
                </div>

                <!-- Historial en Vivo de Abonos -->
                <div class="lg:col-span-7 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                    <h3 class="font-bold text-slate-900 border-b border-slate-100 pb-3 flex justify-between items-center">
                        <span>Abonos Recientes Auditados en Sistema</span>
                        <span class="text-xs bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded font-mono font-medium">logs_sistema</span>
                    </h3>

                    <div class="overflow-x-auto rounded-lg border border-slate-200">
                        <table class="w-full text-xs text-left text-slate-600">
                            <thead class="bg-slate-100 text-slate-700 uppercase font-semibold">
                                <tr>
                                    <th class="px-3 py-2.5">Fecha</th>
                                    <th class="px-3 py-2.5">Funcionario</th>
                                    <th class="px-3 py-2.5">Abonado</th>
                                    <th class="px-3 py-2.5">Motivo</th>
                                    <th class="px-3 py-2.5">Oficial RRHH</th>
                                </tr>
                            </thead>
                            <tbody id="historialAbonosBody" class="divide-y divide-slate-200 bg-white">
                                <tr>
                                    <td colspan="5" class="px-3 py-6 text-center text-slate-400">Los abonos que realice en esta sesión aparecerán aquí inmediatamente.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- SUBMÓDULO 3: SIMULADOR DE FUNCIONARIO (BLOQUEO PREVENTIVO)      -->
        <!-- ============================================================== -->
        <div id="tab-simulador" class="tab-content hidden space-y-6">
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                <div class="max-w-2xl">
                    <h2 class="text-xl font-bold text-slate-900">Formulario de Solicitud de Permisos (Vista Funcionario)</h2>
                    <p class="text-sm text-slate-500 mt-1">
                        Pruebe la <strong>Validación Estricta de Cupo</strong> y el <strong>Bloqueo Preventivo</strong>: Si RRHH aún no le ha inicializado el saldo anual a un funcionario, los campos de fecha quedan inhabilitados y se despliega la advertencia obligatoria.
                    </p>
                </div>
            </div>

            <div class="max-w-2xl mx-auto bg-white p-8 rounded-2xl border border-slate-200 shadow-md space-y-6">
                <!-- Selector de Funcionario y Tipo de Permiso -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">ID del Solicitante</label>
                        <input type="text" id="simFuncionarioId" value="FUNCIONARIO-NUEVO" oninput="verificarBloqueoPreventivo()" class="w-full px-3.5 py-2 border border-slate-300 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-indigo-500 outline-none">
                        <span class="text-[11px] text-slate-400 mt-0.5 block">Pruebe con uno no inicializado o con uno que ya cargó</span>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Tipo de Permiso Solicitado</label>
                        <select id="simTipoPermiso" onchange="verificarBloqueoPreventivo()" class="w-full px-3.5 py-2 border border-slate-300 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-indigo-500 outline-none bg-white">
                            <option value="administrativo">Permiso Administrativo (6 días anuales)</option>
                            <option value="feriado_legal">Feriado Legal / Vacaciones (15 o 20 días)</option>
                            <option value="compensacion_tiempo">Compensación de Tiempo (Horas extra)</option>
                            <option value="duelo">Permiso por Duelo Familiar (No requiere saldo)</option>
                        </select>
                    </div>
                </div>

                <!-- BANNER DE BLOQUEO PREVENTIVO (Se muestra cuando no está inicializado) -->
                <div id="bannerBloqueo" class="hidden p-4 rounded-xl border border-rose-300 bg-rose-50 text-rose-800 flex items-start gap-3 transition-all">
                    <div class="w-8 h-8 rounded-full bg-rose-200 text-rose-700 flex items-center justify-center shrink-0 font-black mt-0.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <div>
                        <h4 class="font-bold text-sm text-rose-900">Bloqueo Preventivo Activado</h4>
                        <p id="textoMensajeBloqueo" class="text-xs text-rose-700 mt-0.5 font-medium">Saldo anual no configurado. Por favor, regularice su situación con Recursos Humanos antes de solicitar este permiso.</p>
                    </div>
                </div>

                <!-- BANNER DE SALDO DISPONIBLE (Se muestra cuando sí está inicializado) -->
                <div id="bannerDisponible" class="hidden p-4 rounded-xl border border-emerald-300 bg-emerald-50 text-emerald-800 flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="text-xs font-bold text-emerald-900">Saldo Anual Vigente y Disponible:</span>
                    </div>
                    <span id="badgeCupoDisponible" class="text-sm font-extrabold text-emerald-700 bg-emerald-100 px-3 py-1 rounded-full border border-emerald-300">0.0 días</span>
                </div>

                <!-- Campos de Fechas y Detalles (Bloqueados si no hay saldo) -->
                <div id="seccionFechas" class="space-y-4 pt-2 border-t border-slate-100">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Fecha de Inicio</label>
                            <input type="date" id="simFechaInicio" class="campo-fecha w-full px-3.5 py-2 border border-slate-300 rounded-lg text-sm outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Fecha de Término</label>
                            <input type="date" id="simFechaFin" class="campo-fecha w-full px-3.5 py-2 border border-slate-300 rounded-lg text-sm outline-none">
                        </div>
                    </div>

                    <div id="campoHorasContainer" class="hidden">
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Horas Solicitadas</label>
                        <input type="number" step="0.5" id="simHorasSolicitadas" value="4.0" class="campo-fecha w-full px-3.5 py-2 border border-slate-300 rounded-lg text-sm outline-none">
                    </div>

                    <div id="campoDiasContainer">
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Días Solicitados</label>
                        <input type="number" step="0.5" id="simDiasSolicitados" value="1.0" class="campo-fecha w-full px-3.5 py-2 border border-slate-300 rounded-lg text-sm outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Motivo / Observaciones</label>
                        <textarea id="simMotivo" rows="2" placeholder="Motivo de la solicitud..." class="campo-fecha w-full px-3.5 py-2 border border-slate-300 rounded-lg text-sm outline-none"></textarea>
                    </div>

                    <button type="button" id="btnEnviarSolicitud" onclick="simularEnvioSolicitud()" class="w-full py-3 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl text-sm shadow-md transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                        <span>Enviar Solicitud a Visación</span>
                    </button>
                </div>

                <div id="msgSimulador" class="hidden text-xs p-3.5 rounded-xl"></div>
            </div>
        </div>

    </main>

    <!-- JavaScript Lógica de Interfaz y Conexión API -->
    <script>
        let archivoCargado = null;
        let filasCsvParsed = [];

        function headersApi() {
            return {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-API-SECRET-KEY': document.getElementById('apiSecretKey').value
            };
        }

        function cambiarTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('border-indigo-600', 'text-indigo-600');
                btn.classList.add('border-transparent', 'text-slate-500');
            });

            document.getElementById('tab-' + tabId).classList.remove('hidden');
            const btn = document.getElementById('tabBtn-' + tabId);
            btn.classList.remove('border-transparent', 'text-slate-500');
            btn.classList.add('border-indigo-600', 'text-indigo-600');

            if (tabId === 'simulador') {
                verificarBloqueoPreventivo();
            }
        }

        // ==========================================
        // SUBMÓDULO 1: INICIALIZACIÓN INDIVIDUAL
        // ==========================================
        async function consultarEstadoFuncionario() {
            const userId = document.getElementById('indivUserId').value.trim();
            const anio = document.getElementById('anioGlobal').value;
            if (!userId) return alert('Ingrese un ID o RUT de funcionario.');

            try {
                const res = await fetch(`/api/saldos/${encodeURIComponent(userId)}?anio=${anio}`, { headers: headersApi() });
                const json = await res.json();
                const container = document.getElementById('indivEstadoPrevia');
                const detalles = document.getElementById('indivEstadoDetalles');
                container.classList.remove('hidden');

                if (json.data && json.data.length > 0) {
                    detalles.innerHTML = json.data.map(s => `• <strong>${s.tipo_permiso}</strong>: ${s.cantidad_disponible} ${s.unidad} disponibles (total: ${s.dias_totales})`).join('<br>');
                } else {
                    detalles.innerHTML = '<span class="text-rose-600 font-semibold">⚠️ Sin saldos inicializados para el año ' + anio + '</span>';
                }
            } catch (err) {
                console.error(err);
            }
        }

        async function inicializarIndividual(e) {
            e.preventDefault();
            const btn = document.getElementById('btnGuardarIndividual');
            const msg = document.getElementById('msgIndividual');
            btn.disabled = true;
            btn.textContent = 'Guardando...';

            const payload = {
                insamu_user_id: document.getElementById('indivUserId').value.trim(),
                anio: parseInt(document.getElementById('anioGlobal').value),
                dias_administrativos: parseFloat(document.getElementById('indivDiasAdmin').value),
                dias_feriado_legal: parseFloat(document.getElementById('indivDiasFeriado').value),
                horas_compensacion: parseFloat(document.getElementById('indivHorasComp').value || 0),
                insamu_rrhh_user_id: document.getElementById('insamuRrhhUserId').value.trim()
            };

            try {
                const res = await fetch('/api/rrhh/saldos/inicializar', {
                    method: 'POST',
                    headers: headersApi(),
                    body: JSON.stringify(payload)
                });
                const json = await res.json();

                msg.classList.remove('hidden');
                if (res.ok && json.status === 'success') {
                    msg.className = 'text-xs p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 font-semibold';
                    msg.textContent = json.message;
                    consultarEstadoFuncionario();
                } else {
                    msg.className = 'text-xs p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 font-semibold';
                    msg.textContent = json.message || 'Error al inicializar saldos.';
                }
            } catch (err) {
                msg.classList.remove('hidden');
                msg.className = 'text-xs p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 font-semibold';
                msg.textContent = 'Error de conexión: ' + err.message;
            } finally {
                btn.disabled = false;
                btn.textContent = 'Inicializar Cupo del Funcionario';
            }
        }

        // ==========================================
        // SUBMÓDULO 1: DRAG & DROP CARGA MASIVA
        // ==========================================
        function handleDragOver(e) {
            e.preventDefault();
            document.getElementById('dropzone').classList.add('dropzone-active');
        }

        function handleDragLeave(e) {
            e.preventDefault();
            document.getElementById('dropzone').classList.remove('dropzone-active');
        }

        function handleDrop(e) {
            e.preventDefault();
            document.getElementById('dropzone').classList.remove('dropzone-active');
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                procesarArchivoSeleccionado(e.dataTransfer.files[0]);
            }
        }

        function handleFileSelect(e) {
            if (e.target.files && e.target.files.length > 0) {
                procesarArchivoSeleccionado(e.target.files[0]);
            }
        }

        function procesarArchivoSeleccionado(file) {
            archivoCargado = file;
            document.getElementById('previewFileName').textContent = file.name;

            const reader = new FileReader();
            reader.onload = function(evt) {
                const text = evt.target.result;
                parsearTextoCsv(text);
            };
            reader.readAsText(file);
        }

        function parsearTextoCsv(texto) {
            const lineas = texto.split(/\r?\n/).filter(l => l.trim().length > 0);
            if (lineas.length < 2) return alert('El archivo no contiene filas de datos.');

            const separador = lineas[0].includes(';') ? ';' : ',';
            const headers = lineas[0].split(separador).map(h => h.trim().toLowerCase().replace(/[\uFEFF]/g, ''));

            filasCsvParsed = [];
            for (let i = 1; i < lineas.length; i++) {
                const celdas = lineas[i].split(separador).map(c => c.trim());
                if (celdas.length < 2) continue;

                const fila = {};
                headers.forEach((h, idx) => {
                    fila[h] = celdas[idx] || '';
                });

                if (fila.insamu_user_id || fila.rut || fila.id_funcionario) {
                    filasCsvParsed.push(fila);
                }
            }

            document.getElementById('previewCount').textContent = filasCsvParsed.length;
            const tbody = document.getElementById('previewTableBody');
            tbody.innerHTML = '';

            filasCsvParsed.slice(0, 10).forEach(f => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="px-3 py-2 font-mono font-bold text-slate-800">${f.insamu_user_id || f.rut}</td>
                    <td class="px-3 py-2">${f.anio || document.getElementById('anioGlobal').value}</td>
                    <td class="px-3 py-2"><span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 font-semibold">${f.dias_administrativos || 6.0} d</span></td>
                    <td class="px-3 py-2"><span class="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 font-semibold">${f.dias_feriado_legal || 15.0} d</span></td>
                    <td class="px-3 py-2"><span class="px-2 py-0.5 rounded bg-amber-50 text-amber-700 font-semibold">${f.horas_compensacion || 0.0} h</span></td>
                    <td class="px-3 py-2"><span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 font-medium text-[11px]" title="Asignado automáticamente por Cron Job anual">6.0 d (Auto)</span></td>
                `;
                tbody.appendChild(tr);
            });

            document.getElementById('previewContainer').classList.remove('hidden');
        }

        function cancelarArchivo() {
            archivoCargado = null;
            filasCsvParsed = [];
            document.getElementById('previewContainer').classList.add('hidden');
            document.getElementById('csvFileInput').value = '';
            document.getElementById('msgMasivo').classList.add('hidden');
        }

        async function procesarCargaMasiva() {
            if (!archivoCargado && filasCsvParsed.length === 0) return alert('Seleccione un archivo primero.');

            const btn = document.getElementById('btnProcesarMasivo');
            const msg = document.getElementById('msgMasivo');
            btn.disabled = true;
            btn.textContent = 'Procesando ' + filasCsvParsed.length + ' registros en BD...';

            const formData = new FormData();
            formData.append('archivo_csv', archivoCargado);
            formData.append('anio_defecto', document.getElementById('anioGlobal').value);
            formData.append('insamu_rrhh_user_id', document.getElementById('insamuRrhhUserId').value.trim());

            try {
                const res = await fetch('/api/rrhh/saldos/carga-masiva', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-API-SECRET-KEY': document.getElementById('apiSecretKey').value
                    },
                    body: formData
                });
                const json = await res.json();

                msg.classList.remove('hidden');
                if (res.ok && json.status === 'success') {
                    msg.className = 'text-xs p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 space-y-1';
                    msg.innerHTML = `<strong>✓ ${json.message}</strong><br>Total procesados: ${json.resumen.total_procesados} de ${json.resumen.total_filas} filas.`;
                } else {
                    msg.className = 'text-xs p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800';
                    msg.textContent = json.message || 'Error en carga masiva.';
                }
            } catch (err) {
                msg.classList.remove('hidden');
                msg.className = 'text-xs p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800';
                msg.textContent = 'Error: ' + err.message;
            } finally {
                btn.disabled = false;
                btn.textContent = 'Procesar e Inyectar Masivamente a todo el Personal';
            }
        }

        // ==========================================
        // SUBMÓDULO 2: ABONO DE COMPENSACIONES
        // ==========================================
        async function ejecutarAbonoHoras(e) {
            e.preventDefault();
            const btn = document.getElementById('btnAbonar');
            const msg = document.getElementById('msgAbono');
            btn.disabled = true;

            const payload = {
                insamu_user_id: document.getElementById('abonoUserId').value.trim(),
                anio: parseInt(document.getElementById('anioGlobal').value),
                horas: parseFloat(document.getElementById('abonoHoras').value),
                motivo: document.getElementById('abonoMotivo').value.trim(),
                insamu_rrhh_user_id: document.getElementById('insamuRrhhUserId').value.trim()
            };

            try {
                const res = await fetch('/api/rrhh/saldos/inyectar-compensacion', {
                    method: 'POST',
                    headers: headersApi(),
                    body: JSON.stringify(payload)
                });
                const json = await res.json();

                msg.classList.remove('hidden');
                if (res.ok && json.status === 'success') {
                    msg.className = 'text-xs p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 font-semibold';
                    msg.textContent = json.message;

                    // Agregar al historial en vivo
                    agregarFilaHistorial(payload.insamu_user_id, payload.horas, payload.motivo, payload.insamu_rrhh_user_id);
                    document.getElementById('formAbonoHoras').reset();
                } else {
                    msg.className = 'text-xs p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 font-semibold';
                    msg.textContent = json.message || 'Error al inyectar compensación.';
                }
            } catch (err) {
                msg.classList.remove('hidden');
                msg.className = 'text-xs p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 font-semibold';
                msg.textContent = 'Error: ' + err.message;
            } finally {
                btn.disabled = false;
            }
        }

        function agregarFilaHistorial(userId, horas, motivo, rrhhId) {
            const tbody = document.getElementById('historialAbonosBody');
            if (tbody.children[0] && tbody.children[0].children.length === 1) {
                tbody.innerHTML = '';
            }

            const tr = document.createElement('tr');
            tr.className = 'bg-emerald-50/50';
            tr.innerHTML = `
                <td class="px-3 py-2 text-slate-500">${new Date().toLocaleTimeString()}</td>
                <td class="px-3 py-2 font-mono font-bold text-slate-800">${userId}</td>
                <td class="px-3 py-2 font-extrabold text-amber-600">+${horas} hrs</td>
                <td class="px-3 py-2 text-slate-600">${motivo}</td>
                <td class="px-3 py-2 text-indigo-700 font-medium">${rrhhId}</td>
            `;
            tbody.prepend(tr);
        }

        // ==============================================================
        // SUBMÓDULO 3: BLOQUEO PREVENTIVO AL FUNCIONARIO
        // ==============================================================
        let temporizadorVerificacion = null;
        function verificarBloqueoPreventivo() {
            clearTimeout(temporizadorVerificacion);
            temporizadorVerificacion = setTimeout(ejecutarVerificacionBloqueo, 300);
        }

        async function ejecutarVerificacionBloqueo() {
            const userId = document.getElementById('simFuncionarioId').value.trim();
            const tipo = document.getElementById('simTipoPermiso').value;
            const anio = document.getElementById('anioGlobal').value;

            // Mostrar/ocultar inputs según sea horas o días
            if (tipo === 'compensacion_tiempo') {
                document.getElementById('campoHorasContainer').classList.remove('hidden');
                document.getElementById('campoDiasContainer').classList.add('hidden');
            } else {
                document.getElementById('campoHorasContainer').classList.add('hidden');
                document.getElementById('campoDiasContainer').classList.remove('hidden');
            }

            if (!userId) return;

            try {
                const res = await fetch(`/api/saldos/${encodeURIComponent(userId)}/verificar?tipo_permiso=${encodeURIComponent(tipo)}&anio=${anio}`, {
                    headers: headersApi()
                });
                const json = await res.json();
                const data = json.data;

                const bannerBloqueo = document.getElementById('bannerBloqueo');
                const bannerDisponible = document.getElementById('bannerDisponible');
                const textoMensaje = document.getElementById('textoMensajeBloqueo');
                const badgeCupo = document.getElementById('badgeCupoDisponible');
                const campos = document.querySelectorAll('.campo-fecha');
                const btnSubmit = document.getElementById('btnEnviarSolicitud');

                if (data.bloqueo_preventivo) {
                    // BLOQUEO PREVENTIVO ACTIVADO
                    bannerBloqueo.classList.remove('hidden');
                    bannerDisponible.classList.add('hidden');
                    textoMensaje.textContent = data.mensaje;

                    campos.forEach(c => {
                        c.disabled = true;
                        c.classList.add('bg-slate-100', 'text-slate-400', 'cursor-not-allowed', 'border-rose-200');
                    });
                    btnSubmit.disabled = true;
                } else {
                    // SALDO DISPONIBLE Y HABILITADO
                    bannerBloqueo.classList.add('hidden');
                    bannerDisponible.classList.remove('hidden');

                    if (data.requiere_saldo) {
                        badgeCupo.textContent = `${data.cantidad_disponible} ${data.unidad} disponibles`;
                    } else {
                        badgeCupo.textContent = 'Permiso no limitado por cupo anual';
                    }

                    campos.forEach(c => {
                        c.disabled = false;
                        c.classList.remove('bg-slate-100', 'text-slate-400', 'cursor-not-allowed', 'border-rose-200');
                    });
                    btnSubmit.disabled = false;
                }
            } catch (err) {
                console.error(err);
            }
        }

        async function simularEnvioSolicitud() {
            const btn = document.getElementById('btnEnviarSolicitud');
            const msg = document.getElementById('msgSimulador');
            btn.disabled = true;

            const tipo = document.getElementById('simTipoPermiso').value;
            const payload = {
                insamu_user_id: document.getElementById('simFuncionarioId').value.trim(),
                tipo_permiso: tipo,
                fecha_inicio: document.getElementById('simFechaInicio').value || '2026-10-15',
                fecha_fin: document.getElementById('simFechaFin').value || '2026-10-15',
                insamu_visador_id: 'JEFE-DEMO',
                motivo: document.getElementById('simMotivo').value.trim() || 'Prueba de solicitud'
            };

            if (tipo === 'compensacion_tiempo') {
                payload.horas_solicitadas = parseFloat(document.getElementById('simHorasSolicitadas').value || 4.0);
            } else {
                payload.dias_solicitados = parseFloat(document.getElementById('simDiasSolicitados').value || 1.0);
            }

            try {
                const res = await fetch('/api/permisos', {
                    method: 'POST',
                    headers: headersApi(),
                    body: JSON.stringify(payload)
                });
                const json = await res.json();

                msg.classList.remove('hidden');
                if (res.ok && json.status === 'success') {
                    msg.className = 'text-xs p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 font-semibold';
                    msg.textContent = `✓ Solicitud #${json.data.id} creada con éxito. Se descontó el saldo respectivo.`;
                    verificarBloqueoPreventivo();
                } else {
                    msg.className = 'text-xs p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 font-semibold';
                    msg.textContent = json.message || 'Error al emitir solicitud.';
                }
            } catch (err) {
                msg.classList.remove('hidden');
                msg.className = 'text-xs p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 font-semibold';
                msg.textContent = 'Error: ' + err.message;
            } finally {
                btn.disabled = false;
            }
        }

        // Ejecutar verificación inicial
        document.addEventListener('DOMContentLoaded', () => {
            verificarBloqueoPreventivo();
        });
    </script>
</body>
</html>
