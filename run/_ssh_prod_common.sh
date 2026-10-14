#!/usr/bin/env bash
# Shared StrIM prod SSH profile (source from deploy / ssh helpers).
# Decision: always IPv4 (-4 / AddressFamily=inet) — dual-stack AAAA caused
# intermittent "No route to host" from macOS clients.

# shellcheck disable=SC2034
DEPLOY_USER="${DEPLOY_USER:-bot20}"
DEPLOY_HOST="${DEPLOY_HOST:-bot20.ftp.tools}"
DEPLOY_SSH_PORT="${DEPLOY_SSH_PORT:-22}"
DEPLOY_IDENTITY="${DEPLOY_IDENTITY:-$HOME/.ssh/cursor_bot20}"

ssh_prod_add_key() {
  if [[ "$(uname -s)" == "Darwin" && -f "${DEPLOY_IDENTITY}" ]]; then
    ssh-add --apple-use-keychain "${DEPLOY_IDENTITY}" 2>/dev/null || true
  fi
}

# Populate global SSH_BASE as bash array (ssh / rsync -e).
ssh_prod_ssh_base() {
  SSH_BASE=(
    ssh
    -4
    -p "${DEPLOY_SSH_PORT}"
    -o AddressFamily=inet
    -o IdentitiesOnly=yes
    -o AddKeysToAgent=yes
    -o PubkeyAuthentication=yes
    -o PasswordAuthentication=no
    -o ConnectTimeout=30
    -o ConnectionAttempts=3
    -o ServerAliveInterval=15
    -o ServerAliveCountMax=3
  )
  if [[ "$(uname -s)" == "Darwin" ]]; then
    SSH_BASE+=(-o UseKeychain=yes)
  fi
  if [[ -f "${DEPLOY_IDENTITY}" ]]; then
    SSH_BASE+=(-i "${DEPLOY_IDENTITY}")
  fi
}

# Retry transport failures only. Generic rsync errors (including openrsync's 1)
# and remote application failures must remain visible without repeating work.
ssh_prod_retry() {
  if (( $# == 0 )); then
    echo "ssh_prod_retry: a command is required" >&2
    return 2
  fi
  local command="${1##*/}"
  local max="${SSH_PROD_RETRIES-5}"
  if [[ ! "$max" =~ ^[1-9][0-9]*$ || ${#max} -gt 10 ]] || (( max > 2147483647 )); then
    echo "ssh_prod_retry: SSH_PROD_RETRIES must be an integer from 1 to 2147483647" >&2
    return 2
  fi
  local delay=2
  local n=1
  local ec=0
  while (( n <= max )); do
    if "$@"; then
      return 0
    else
      ec=$?
    fi
    case "${command}:${ec}" in
      ssh:255|rsync:255|rsync:10|rsync:30|rsync:35) ;;
      *) return "$ec" ;;
    esac
    if (( n >= max )); then
      return "$ec"
    fi
    echo "ssh_prod_retry: try ${n}/${max} failed (exit ${ec}), sleep ${delay}s…" >&2
    sleep "$delay"
    delay=$((delay + 2))
    n=$((n + 1))
  done
  return "$ec"
}
