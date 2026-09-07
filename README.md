# GrooFlow backend (PHP)

API REST para el frontend React. Reutiliza `config.php` y `app_usuarios` del panel Gestión.

## Arranque local

```bash
php -S 127.0.0.1:8091 -t grooflow-backend/public grooflow-backend/public/index.php
```

En GrooFlow:

```
VITE_BACKEND=rest
VITE_GROOFLOW_API_URL=http://127.0.0.1:8091
```

## Asistencia (tablas MySQL)

Al primer request autenticado, `grooflow_ensure_schema()` crea:

| Tabla | Contenido |
|-------|-----------|
| `grooflow_asistencia_meta` | Buk + keywords |
| `grooflow_asistencia_staff` | Personal / organigrama |
| `grooflow_asistencia_requirements` | Dotación mínima |
| `grooflow_asistencia_sede_profiles` | Horarios / columnas por sede |
| `grooflow_asistencia_sede_mappings` | Sede ↔ recinto Buk |
| `grooflow_asistencia_snapshots` | Historial diario |
| `grooflow_asistencia_operational` | Contexto alertas |
| `grooflow_asistencia_buk_records` | Historial marcaciones |
| `grooflow_buk_empleados` | Maestro RRHH (Buk.pe) |

Claves KV: `settings:asistencia`, `settings:rrhh`, `data:asistencia-snapshots`, `data:asistencia-operational`.
DDL de referencia: `sql/asistencia_schema.sql`.

## Pipelines (Fase 3)

Jobs automatizados sin depender del clic en la UI:

| Pipeline | Fuente | Destino |
|----------|--------|---------|
| RRHH | Buk.pe | `grooflow_buk_empleados` + vínculos / bajas |
| Marcaciones | Ctrlit | `grooflow_asistencia_buk_records` |
| Enrich usuarios | Ctrlit | `app_usuarios` (no crea altas) |
| Organigrama (Fase 4) | Buk.pe maestro | `grooflow_asistencia_staff` (preserva área/crítico/manager) |

**HTTP (cron Hostinger / curl):**

```bash
# Definir en config.php o entorno:
# define('GROOFLOW_CRON_KEY', 'tu-clave-secreta');

curl -X POST "https://gestionveterinariagroomers.com/grooflow/api/jobs/pipelines" \
  -H "X-Grooflow-Cron-Key: tu-clave-secreta" \
  -H "Content-Type: application/json" \
  -d '{}'
```

También acepta sesión admin (`Authorization: Bearer …`).

**CLI:**

```bash
php grooflow-backend/bin/run-pipelines.php
# php grooflow-backend/bin/run-pipelines.php --force
```

Crontab sugerido (cada 15 min; cada pipeline respeta su propio intervalo):

```cron
*/15 * * * * /usr/bin/php /ruta/grooflow-backend/bin/run-pipelines.php >> /tmp/grooflow-pipelines.log 2>&1
```

Salud: `GET /rrhh/pipeline-health` (autenticado) o `GET /jobs/pipelines/health` (cron/admin).

## Producción

`https://gestionveterinariagroomers.com/grooflow/api/`

CORS: el API refleja el `Origin` del SPA (Hostinger, Vite local y `https://*.vercel.app`). Ver `lib/grooflow_cors.php`.

**GitHub ≠ Hostinger.** Un push no actualiza el servidor.

Desde **este repo** (solo PHP GrooFlow, no el panel ni el SPA):

```bash
cp deploy/ssh.env.example deploy/ssh.env   # completar; no commitear
./deploy/deploy-hostinger.sh              # solo backend (rsync/bash)
python deploy/deploy-hostinger-sftp.py    # frontend dist + backend (SFTP)
```

El script SFTP verifica que `index.html` en el servidor coincida con el build local (evita servir bundles viejos).
