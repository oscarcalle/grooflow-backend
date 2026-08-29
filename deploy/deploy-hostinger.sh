#!/usr/bin/env bash
# Sube SOLO este repo (grooflow-backend) a Hostinger.
# No toca Angular, GrooFlow SPA ni el panel Gestión.
#
# Uso:
#   cp deploy/ssh.env.example deploy/ssh.env   # completar credenciales
#   ./deploy/deploy-hostinger.sh
#   ./deploy/deploy-hostinger.sh --dry-run
#
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
ENV_FILE="$DIR/ssh.env"
DRY_RUN=0

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    -h|--help)
      sed -n '2,12p' "$0"
      exit 0
      ;;
    *)
      echo "Opción desconocida: $arg" >&2
      exit 1
      ;;
  esac
done

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Falta $ENV_FILE"
  echo "  cp $DIR/ssh.env.example $DIR/ssh.env"
  exit 1
fi

set -a
# shellcheck source=/dev/null
source "$ENV_FILE"
set +a

: "${SSH_HOST:?Define SSH_HOST en ssh.env}"
: "${SSH_USER:?Define SSH_USER en ssh.env}"
: "${SSH_PASS:?Define SSH_PASS en ssh.env}"
: "${SSH_REMOTE_DIR:?Define SSH_REMOTE_DIR en ssh.env}"
SSH_PORT="${SSH_PORT:-65002}"
REMOTE="${SSH_REMOTE_DIR%/}/grooflow-backend"

ASKPASS="$(mktemp)"
chmod 700 "$ASKPASS"
printf "#!/bin/sh\nprintf '%%s\\n' '%s'\n" "${SSH_PASS//\'/\'\\\'\'}" > "$ASKPASS"
trap 'rm -f "$ASKPASS"' EXIT

RSYNC_RSH="ssh -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ServerAliveInterval=15 -o ServerAliveCountMax=4"
RSYNC_FLAGS=(-az --human-readable --info=stats1,progress2 --timeout=90)
[[ "$DRY_RUN" -eq 1 ]] && RSYNC_FLAGS+=(-n)

echo "==> Subiendo grooflow-backend → ${SSH_USER}@${SSH_HOST}:${REMOTE}"

export DISPLAY="${DISPLAY:-:0}"
export SSH_ASKPASS="$ASKPASS"
export SSH_ASKPASS_REQUIRE=force

setsid -w rsync "${RSYNC_FLAGS[@]}" \
  --exclude='.git/' \
  --exclude='deploy/ssh.env' \
  --exclude='.env' \
  --exclude='.env.local' \
  --exclude='*.log' \
  "$ROOT/" \
  -e "$RSYNC_RSH" \
  "${SSH_USER}@${SSH_HOST}:${REMOTE}/"

echo "Listo → https://gestionveterinariagroomers.com/grooflow/api/"
