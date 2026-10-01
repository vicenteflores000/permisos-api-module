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
Control de días administrativos disponibles por funcionario y año calendario.
- `id` (PK)
- `insamu_user_id` (string 64, index)
- `anio` (unsignedSmallInteger)
- `dias_totales` (decimal 4,1 - default 6.0)
- `dias_usados` (decimal 4,1 - default 0.0)
- *Restricción única:* `(insamu_user_id, anio)`
- *Campo virtual:* `dias_disponibles = dias_totales - dias_usados`

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

### 5. Saldos y Auditoría
- **Consultar Saldo**: `GET /api/saldos/{userId}?anio=2026`
- **Configurar / Ajustar Saldo**: `POST /api/saldos`
- **Consultar Logs de Auditoría**: `GET /api/logs`

---

## 🧪 Pruebas Automatizadas

El proyecto cuenta con un suite completo de pruebas unitarias y de integración en PHPUnit que validan cada requerimiento:

```bash
php artisan test --testdox
```

Resultado:
- **14 pruebas completas**, **83 aserciones**, 100% de éxito.
