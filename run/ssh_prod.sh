#!/usr/bin/env bash
# SSH на prod без «Too many authentication failures» і з Keychain (macOS).
# IPv4-only + retry на обривах мережі (див. run/_ssh_prod_common.sh).
#
# Usage:
#   ./run/ssh_prod.sh                    # інтерактивна сесія
#   ./run/ssh_prod.sh 'php -v'           # одна команда (з retry)
#   ./run/ssh_prod.sh -t                 # sudo/pty
#
# Альтернатива: ssh strim-prod  (див. ~/.ssh/config)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=run/_ssh_prod_common.sh
source "${ROOT}/_ssh_prod_common.sh"

if [[ ! -f "${DEPLOY_IDENTITY}" ]]; then
  echo "ssh_prod: key not found: ${DEPLOY_IDENTITY}" >&2
  exit 1
fi

ssh_prod_add_key
ssh_prod_ssh_base

REMOTE="${DEPLOY_USER}@${DEPLOY_HOST}"

# Interactive / flags-only → single attempt. Remote command → retry.
if [[ $# -eq 0 ]] || [[ "${1:-}" == -* ]]; then
  exec "${SSH_BASE[@]}" "${REMOTE}" "$@"
fi

ssh_prod_retry "${SSH_BASE[@]}" "${REMOTE}" "$@"
