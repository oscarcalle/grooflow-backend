#!/usr/bin/env python3
"""Deploy GrooFlow frontend (dist) + grooflow-backend to Hostinger via SFTP."""
from __future__ import annotations

import os
import sys
from pathlib import Path

import paramiko

BACKEND_ROOT = Path(__file__).resolve().parents[1]
WORKSPACE = BACKEND_ROOT.parent
ENV_FILE = BACKEND_ROOT / "deploy" / "env.ssh"
FRONTEND_DIST = WORKSPACE / "GrooFlow" / "dist"
API_GATEWAY = BACKEND_ROOT / "deploy" / "hostinger-grooflow-api"

BACKEND_EXCLUDE = {
    ".git",
    ".env",
    ".env.local",
    "deploy/ssh.env",
    "deploy/env.ssh",
}


def load_env(path: Path) -> dict[str, str]:
    data: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        data[key.strip()] = value.strip()
    return data


def should_skip(rel: str) -> bool:
    parts = Path(rel).parts
    if parts and parts[0] == ".git":
        return True
    if rel.replace("\\", "/") in BACKEND_EXCLUDE:
        return True
    return rel.endswith(".log")


def upload_dir(sftp: paramiko.SFTPClient, local: Path, remote: str) -> int:
    count = 0
    for root, dirs, files in os.walk(local):
        rel_root = Path(root).relative_to(local)
        rel_root_posix = rel_root.as_posix()
        remote_root = remote if rel_root_posix == "." else f"{remote}/{rel_root_posix}"

        try:
            sftp.stat(remote_root)
        except OSError:
            sftp.mkdir(remote_root)

        dirs[:] = [d for d in dirs if not should_skip((rel_root / d).as_posix())]

        for name in files:
            rel = (rel_root / name).as_posix()
            if should_skip(rel):
                continue
            sftp.put(str(Path(root) / name), f"{remote_root}/{name}")
            count += 1
    return count


def upload_file(sftp: paramiko.SFTPClient, local: Path, remote: str) -> None:
    sftp.put(str(local), remote)


def normalize_html(text: str) -> str:
    return text.replace("\r\n", "\n").strip()


def remote_text(sftp: paramiko.SFTPClient, remote: str) -> str:
    with sftp.open(remote) as handle:
        return handle.read().decode("utf-8", errors="replace")


def prune_remote_assets(sftp: paramiko.SFTPClient, local_dist: Path, remote_frontend: str) -> int:
    """Elimina assets en el servidor que ya no están en el build local."""
    local_assets = local_dist / "assets"
    if not local_assets.is_dir():
        return 0
    keep = {f"assets/{p.name}" for p in local_assets.iterdir() if p.is_file()}
    remote_assets = f"{remote_frontend}/assets"
    removed = 0
    try:
        for entry in sftp.listdir_attr(remote_assets):
            rel = f"assets/{entry.filename}"
            if rel in keep:
                continue
            sftp.remove(f"{remote_assets}/{entry.filename}")
            removed += 1
            print(f"    eliminado asset obsoleto: {entry.filename}")
    except OSError as exc:
        print(f"    aviso prune assets: {exc}", file=sys.stderr)
    return removed


def verify_index_html(sftp: paramiko.SFTPClient, local: Path, remote: str) -> bool:
    upload_file(sftp, local, remote)
    remote_index = normalize_html(remote_text(sftp, remote))
    local_index = normalize_html(local.read_text(encoding="utf-8"))
    if remote_index == local_index:
        return True
    upload_file(sftp, local, remote)
    return normalize_html(remote_text(sftp, remote)) == local_index


def upload_api_gateway(sftp: paramiko.SFTPClient, remote_frontend: str) -> None:
    remote_api = f"{remote_frontend}/api"
    try:
        sftp.stat(remote_api)
    except OSError:
        sftp.mkdir(remote_api)
    for name in (".htaccess", "index.php"):
        local = API_GATEWAY / name
        if local.exists():
            sftp.put(str(local), f"{remote_api}/{name}")
            print(f"    api/{name}")


def main() -> int:
    if not ENV_FILE.exists():
        print(f"Falta {ENV_FILE}", file=sys.stderr)
        return 1
    if not FRONTEND_DIST.exists():
        print(f"Falta build frontend: {FRONTEND_DIST}", file=sys.stderr)
        print("  cd ../GrooFlow && npm run build", file=sys.stderr)
        return 1

    env = load_env(ENV_FILE)
    host = env["SSH_HOST"]
    port = int(env.get("SSH_PORT", "22"))
    user = env["SSH_USER"]
    password = env["SSH_PASS"]
    remote_base = env["SSH_REMOTE_DIR"].rstrip("/")
    remote_backend = f"{remote_base}/grooflow-backend"
    remote_frontend = f"{remote_base}/grooflow"

    print(f"==> Conectando a {user}@{host}:{port}")
    transport = paramiko.Transport((host, port))
    transport.connect(username=user, password=password)
    sftp = paramiko.SFTPClient.from_transport(transport)
    if sftp is None:
        print("No se pudo abrir SFTP", file=sys.stderr)
        return 1

    try:
        for remote in (remote_backend, remote_frontend):
            try:
                sftp.stat(remote)
            except OSError:
                sftp.mkdir(remote)

        print(f"==> Subiendo backend -> {remote_backend}")
        print(f"    {upload_dir(sftp, BACKEND_ROOT, remote_backend)} archivos")

        print(f"==> Subiendo frontend -> {remote_frontend}")
        print(f"    {upload_dir(sftp, FRONTEND_DIST, remote_frontend)} archivos")

        print("==> Limpiando assets obsoletos en servidor")
        print(f"    {prune_remote_assets(sftp, FRONTEND_DIST, remote_frontend)} eliminados")

        print("==> Configurando gateway API /grooflow/api")
        upload_api_gateway(sftp, remote_frontend)

        index_local = FRONTEND_DIST / "index.html"
        index_remote = f"{remote_frontend}/index.html"
        if index_local.exists():
            print("==> Verificando index.html")
            if not verify_index_html(sftp, index_local, index_remote):
                print("ERROR: index.html en servidor no coincide con el build local", file=sys.stderr)
                return 1
            for line in index_local.read_text(encoding="utf-8").splitlines():
                if "index-" in line and ".js" in line:
                    print(f"    {line.strip()}")
                    break
    finally:
        sftp.close()
        transport.close()

    print("Listo:")
    print("  App:  https://gestionveterinariagroomers.com/grooflow")
    print("  API:  https://gestionveterinariagroomers.com/grooflow/api/")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
