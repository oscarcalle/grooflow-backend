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

## Producción

`https://gestionveterinariagroomers.com/grooflow/api/`

CORS: el API refleja el `Origin` del SPA (Hostinger, Vite local y `https://*.vercel.app`). Ver `lib/grooflow_cors.php`.

**GitHub ≠ Hostinger.** Un push a este repo no actualiza el servidor. El deploy es un paso aparte (`./deploy/hostinger/deploy-ssh.sh` en el panel Gestión, con `ssh.env` local que no se sube a git).
