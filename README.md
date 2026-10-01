# Microservicio API RESTful - Permisos Administrativos INSAMU

Microservicio desarrollado en **Laravel** para la gestión integral y trazabilidad de **Permisos Administrativos** de la plataforma **INSAMU**.

---

## 🏛 Arquitectura y Separación de Responsabilidades

- **INSAMU (Frontend / Core)**:
  - Gestiona la identidad de los usuarios mediante inicio de sesión único (**SSO Microsoft / Entra ID**).
  - Custodia y valida el **PIN secreto de 6 dígitos** ingresado por los usuarios para firmar.
- **Microservicio Permisos (Esta API Laravel)**:
  - **Confianza Ciega**: No almacena contraseñas ni valida PINs.
  - **Seguridad Simétrica**: Todas las peticiones son autenticadas contra la clave simétrica compartida configurada en `API_SECRET_KEY` en el `.env`.
  - Gestiona el ciclo de vida de las solicitudes, trazabilidad de firmas (FES), subrogancia autogestionada, lógica de anulación con restitución de saldos, generación de PDFs inalterables y auditoría.

---

## 🗄️ Estructura de Base de Datos

### 1. `permisos_saldos`
Control de saldos disponibles por funcionario, año calendario y tipo de permiso:
- `id` (PK)
- `insamu_user_id` (string 64, index)
- `anio` (unsignedSmallInteger)
- `tipo_permiso` (`administrativo`, `feriado_legal`, `compensacion_tiempo`)
- `unidad` (`dias` o `horas`)
- `dias_totales` (decimal 6,2 - cantidad total asignada o acumulada)
- `dias_usados` (decimal 6,2 - cantidad consumida)
- *Restricción única:* `(insamu_user_id, anio, tipo_permiso)`
- *Campo virtual:* `cantidad_disponible = dias_totales - dias_usados`
- *Valores por defecto anuales:*
  - **Permisos Administrativos:** 6.0 días.
  - **Feriados Legales (Vacaciones):** 15.0 días.
  - **Compensación de Tiempo:** 0.0 horas iniciales (se incrementan con horas extra).

### 2. `permisos_solicitudes`
Registro maestro de solicitudes de permiso.
- `id` (PK)
- `insamu_user_id` (string 64, index - solicitante)
- `nombre_solicitante`, `rut_solicitante`, `cargo_solicitante`, `unidad_solicitante`
- `tipo_permiso` (string)
- `fecha_inicio` (date)
- `fecha_fin` (date)
- `dias_solicitados` (decimal 4,1)
- `estado` (`pendiente_visatura`, `pendiente_direccion`, `en_rrhh`, `decretado`, `pendiente_anulacion`, `anulado`, `rechazado`)
- `motivo` (text, nullable)
- `estado_previo_anulacion` (string 32, nullable)
- `decreto_numero`, `fecha_decreto` (nullable)

### 3. `permiso_trazabilidad_firmas`
Historial de firmas electrónicas, visaciones y subrogancias.
- `id` (PK)
- `permiso_id` (FK -> `permisos_solicitudes.id` cascade)
- `insamu_visador_id` (string 64, index)
- `nombre_visador`, `rut_visador`, `cargo_visador`
- `rol_firma` (Ej. Jefatura, Dirección, RRHH)
- `estado_firma` (`pendiente`, `aprobado`, `rechazado`, `subrogado`)
- `es_subrogante` (boolean, default false)
- `motivo_rechazo` (text, nullable)
- `fecha_accion` (timestamp de la firma)
- `token_correo` (string 255 nullable, token seguro para Deep Linking por correo)

### 4. `logs_sistema`
Auditoría y registro inmutable de eventos.
- `id` (PK)
- `insamu_user_id` (string 64, nullable)
- `accion` (string 64 - Ej: `CREACION_SOLICITUD`, `FIRMA_ELECTRONICA_APROBADA`, `SUBROGANCIA_VISADOR`, `SOLICITUD_ANULACION`, etc.)
- `entidad_tipo`, `entidad_id`
- `detalles` (json)
- `ip_origen`, `user_agent`
- `created_at` (timestamp)

---

## 🔒 Autenticación API

Toda petición debe enviar la clave simétrica en cualquiera de los siguientes encabezados:

```http
X-API-SECRET-KEY: <API_SECRET_KEY>
```
o
```http
Authorization: Bearer <API_SECRET_KEY>
```

Si el encabezado no coincide con el valor de `API_SECRET_KEY` configurado en el microservicio, la API retorna inmediatamente `HTTP 401 Unauthorized`.

---

## 🚀 Endpoints de la API

### 1. Gestión de Solicitudes
- **Listar Solicitudes**: `GET /api/permisos`
  - Filtros query opcionales: `?insamu_user_id=...&estado=...&anio=...&fecha_inicio=...&fecha_fin=...`
- **Crear Solicitud**: `POST /api/permisos`
  - Valida saldo suficiente en `permisos_saldos`.
  - Descuenta preventivamente los días.
  - Inicializa en estado `pendiente_visatura` y genera la primera firma en `permiso_trazabilidad_firmas` con `token_correo`.
- **Ver Solicitud**: `GET /api/permisos/{id}`
- **Editar / Subrogar**: `PUT /api/permisos/{id}` o `POST /api/permisos/{id}/subrogar-visador`
  - Solo permitido en estado pendiente (`pendiente_visatura` o `pendiente_direccion`).
  - **Invalida inmediatamente el token del visador original** y genera uno nuevo para el subrogante con `es_subrogante = true`.

### 2. Firma Electrónica Simple (FES)
- **Confirmar Firma**: `POST /api/firmas/confirmar`
  - Recibe la confirmación enviada por INSAMU tras validar el PIN.
  - Parámetros:
    - `token_correo` (o bien `permiso_id` + `insamu_visador_id`)
    - `accion`: `'aprobar'` o `'rechazar'`
    - `motivo_rechazo`: Obligatorio si la acción es `'rechazar'`
    - `nombre_firmante`, `rut_firmante`, `cargo_firmante`: Datos para la estampilla
  - **Aprobación**: Registra timestamp, avanza de `pendiente_visatura` a `pendiente_direccion`, y luego a `en_rrhh`.
  - **Rechazo**: Pasa a `rechazado`, registra motivo y **restituye automáticamente el saldo** al usuario.
- **Verificar Token Deep Linking**: `GET /api/firmas/verificar-token/{token}`
  - Permite al frontend de INSAMU precargar la interfaz de firma cuando el usuario hace clic en el enlace del correo.

### 3. Lógica de Anulación
- **Solicitar Anulación (Funcionario)**: `POST /api/permisos/{id}/solicitar-anulacion`
  - Cambia el estado a `pendiente_anulacion`.
  - **Bloquea inmediatamente la descarga del PDF** (`HTTP 403 Forbidden`).
- **Aprobar Anulación (RRHH)**: `POST /api/rrhh/permisos/{id}/aprobar-anulacion`
  - Pasa a estado `anulado`.
  - **Restituye el saldo** de días en `permisos_saldos`.
- **Rechazar Anulación (RRHH)**: `POST /api/rrhh/permisos/{id}/rechazar-anulacion`
  - Restaura la solicitud a su estado previo (ej. si ya fue decretado externamente).
- **Decretar (RRHH)**: `POST /api/rrhh/permisos/{id}/decretar`
  - Registra número y fecha de decreto formal.

### 4. Generación de PDF Inalterable
- **Descarga de Certificado**: `GET /api/permisos/{id}/pdf`
  - Genera un PDF inalterable vía **DomPDF**.
  - **Bloqueado** con `HTTP 403` si la solicitud está en `pendiente_anulacion`.
  - Incluye estampilla visual con la glosa obligatoria:
    > *"Documento firmado electrónicamente mediante autenticación de PIN en INSAMU por [Nombre], RUT [RUT] con fecha [DD/MM/AAAA] a las [HH:MM]."*
  - Si el firmante fue subrogante, indica explícitamente:
    > *"[Cargo] Subrogante"*

### 5. Módulo "Gestor de Saldos" para Recursos Humanos
- **Interfaz Web RRHH**: `GET /rrhh/gestor-saldos`
  - Vista exclusiva para RRHH con submódulos de **Inicialización de Permisos** (individual y Drag & Drop CSV para todo el CESFAM), **Abono de Compensaciones** e interfaz interactiva con **Bloqueo Preventivo**.
- **Descargar Plantilla CSV**: `GET /rrhh/plantilla-saldos.csv`
  - Descarga planilla formateada con columnas `insamu_user_id,anio,dias_feriado_legal,horas_compensacion`.
- **Inicializar Saldos Individual (Feriado Legal & Compensación)**: `POST /api/rrhh/saldos/inicializar`
  - Permite a RRHH fijar topes anuales variables de Feriado Legal (ej. 15, 20 o 25 días según antigüedad) y saldo inicial de compensación.
- **Carga Masiva de Planilla**: `POST /api/rrhh/saldos/carga-masiva`
  - Procesa planillas masivas del CESFAM/DESAM vía archivo CSV (Drag & Drop) o array de objetos JSON para Feriado Legal y Compensación.
- **Inyección Transaccional de Compensación**: `POST /api/rrhh/saldos/inyectar-compensacion`
  - Suma acumulativamente las horas a favor por turnos extraordinarios (sin sobrescribir) y audita al usuario de RRHH en `logs_sistema`.
- **Verificación y Bloqueo Preventivo**: `GET /api/saldos/{userId}/verificar?tipo_permiso=...&anio=...`
  - Evalúa si el funcionario tiene saldo configurado. Si no está inicializado, reporta `bloqueo_preventivo: true` con el mensaje oficial para bloquear el formulario.
- **Consultar Saldos**: `GET /api/saldos/{userId}?anio=2026`
- **Configurar / Ajustar Saldo**: `POST /api/saldos`
- **Consultar Logs de Auditoría**: `GET /api/logs`

---

### 6. Automatización de Días Administrativos (Cron Job & Vencimiento Estricto)

- **Comando Artisan**:
  ```bash
  php artisan permisos:asignar-administrativos-anuales [--anio=2027] [--user=MEDICO-01]
  ```
- **Task Scheduling (Programador de Tareas)**:
  - Ejecución programada en `routes/console.php`:
    ```php
    Schedule::command('permisos:asignar-administrativos-anuales')
        ->yearlyOn(1, 1, '00:00');
    ```
    (Expresión Cron: `0 0 1 1 *`).
- **Lógica de Asignación y Caducidad**:
  - Cada **1 de enero a las 00:00**, asigna exactamente **6.0 días totales** y **0.0 días usados** a todos los funcionarios activos.
  - **Vencimiento Estricto al 31 de Diciembre**: El saldo del año anterior caduca indefectiblemente. Los días no utilizados **no se traspasan ni se suman** a los 6 nuevos del año siguiente.
### 7. Gestión de Archivos Adjuntos (Storage Seguro)

- **Cálculo Simple de Fechas**:
  - Se descarta la integración de calendarios de feriados nacionales. La API confía plenamente en `dias_solicitados` (o `horas_solicitadas`) enviado por el cliente y lo descuenta de forma lineal del saldo del funcionario.
- **Validación Condicional de Adjuntos**:
  - En la creación de la solicitud (`POST /api/permisos`), el campo `archivo` es `nullable` por defecto.
  - Pasa a ser estrictamente **`required`** si `tipo_permiso` corresponde a:
    - `fallecimiento_familiar` (Certificado de defunción)
    - `nacimiento_hijo` (Certificado de nacimiento)
    - `capacitacion_autogestionada` (Certificado de curso / asistencia)
- **Seguridad y Renombrado**:
  - Los archivos subidos se renombran automáticamente con identificadores únicos **UUID** (`adjunto_{uuid}.{extension}`) en carpetas temporales/mensuales (`adjuntos_permisos/YYYY/MM`), previniendo ataques de Path Traversal o colisiones de nombre.
  - Se valida tipo MIME (`pdf, jpg, jpeg, png, doc, docx`) y tamaño máximo (10 MB).
  - La ruta se almacena en la columna `archivo_adjunto_url` de la tabla `permisos_solicitudes`.
- **Endpoints de Adjuntos**:
  - **Subida en Creación**: `POST /api/permisos` (enviar como `multipart/form-data` con campo `archivo`).
  - **Subida / Actualización Dedicada**: `POST /api/permisos/{id}/adjunto` (actualiza el adjunto de la solicitud existente).
  - **Descarga / Visualización Segura**: `GET /api/permisos/{id}/adjunto` (sirve el archivo con las cabeceras MIME correspondientes; opcional `?download=1`).

---

## 🧪 Pruebas Automatizadas

El proyecto cuenta con un suite completo de pruebas unitarias y de integración en PHPUnit que validan cada requerimiento:

```bash
php artisan test
```

Resultado:
- **24 pruebas completas**, **170 aserciones**, 100% de éxito.
