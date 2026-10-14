#!/usr/bin/env bash
# rsync на prod через той самий SSH-профіль, що ssh_prod.sh (IPv4 + keepalives).
#
# Usage:
#   ./run/rsync_prod.sh -avz local/ bot20@bot20.ftp.tools:/remote/path/

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=run/_ssh_prod_common.sh
source "${ROOT}/_ssh_prod_common.sh"

ssh_prod_add_key
ssh_prod_ssh_base

# rsync -e wants a single command string
RSYNC_RSH="${SSH_BASE[*]}"

ssh_prod_retry rsync -avz -e "${RSYNC_RSH}" "$@"
