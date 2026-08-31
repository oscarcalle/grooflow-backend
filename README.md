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

Claves KV: `settings:asistencia`, `data:asistencia-snapshots`, `data:asistencia-operational`.
DDL de referencia: `sql/asistencia_schema.sql`.

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
