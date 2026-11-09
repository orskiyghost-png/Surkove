#!/usr/bin/env bash
set -Eeuo pipefail

MODE="${1:-}"
FTP_PORT="${FTP_PORT:-21}"
: "${FTP_HOST:?FTP_HOST repository variable is required}"
: "${FTP_USERNAME:?FTP_USERNAME secret is required}"
: "${FTP_PASSWORD:?FTP_PASSWORD secret is required}"
: "${FTP_REMOTE_DIR:?FTP_REMOTE_DIR repository variable is required}"

if [[ ! "$FTP_HOST" =~ ^[A-Za-z0-9.-]+$ || "$FTP_HOST" == .* || "$FTP_HOST" == *..* ]]; then
  echo "FTP_HOST must be a hostname or IPv4 address (IPv6 literals are not supported)." >&2
  exit 2
fi
if [[ ! "$FTP_PORT" =~ ^[0-9]{1,5}$ ]] || (( FTP_PORT < 1 || FTP_PORT > 65535 )); then
  echo "FTP_PORT must be an integer from 1 to 65535." >&2
  exit 2
fi
if [[ -z "$FTP_REMOTE_DIR" || "$FTP_REMOTE_DIR" == *$'\n'* || "$FTP_REMOTE_DIR" == *$'\r'* ]]; then
  echo "FTP_REMOTE_DIR must be a non-empty path without control characters." >&2
  exit 2
fi
case "/$FTP_REMOTE_DIR/" in
  */../*) echo "FTP_REMOTE_DIR must not contain parent-directory segments." >&2; exit 2 ;;
esac
if [[ "$FTP_USERNAME" == *$'\n'* || "$FTP_USERNAME" == *$'\r'* || "$FTP_PASSWORD" == *$'\n'* || "$FTP_PASSWORD" == *$'\r'* ]]; then
  echo "FTP credentials must not contain line breaks." >&2
  exit 2
fi
if [[ ! -d dist || ! -f dist/index.html ]]; then
  echo "Production build is missing: dist/index.html was not found." >&2
  exit 1
fi

TEMP_DIR="$(mktemp -d)"
chmod 700 "$TEMP_DIR"
AUTH_HOME="$TEMP_DIR/home"
mkdir -m 700 "$AUTH_HOME"
AUTH_FILE="$AUTH_HOME/.netrc"
LFTP_FILE="$TEMP_DIR/session.lftp"

FTP_AUTH_FILE="$AUTH_FILE" python3 - <<'PY'
import os

def quote_netrc(value: str) -> str:
    if any(ord(char) < 32 or ord(char) == 127 for char in value):
        raise SystemExit("FTP credentials contain unsupported control characters.")
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'

with open(os.environ["FTP_AUTH_FILE"], "w", encoding="utf-8") as handle:
    handle.write(
        f"machine {os.environ['FTP_HOST']} "
        f"login {quote_netrc(os.environ['FTP_USERNAME'])} "
        f"password {quote_netrc(os.environ['FTP_PASSWORD'])}\n"
    )
os.chmod(os.environ["FTP_AUTH_FILE"], 0o600)
PY

lftp_quote() {
  local value="$1"
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  value="${value//$'\n'/\\n}"
  value="${value//$'\r'/\\r}"
  printf '"%s"' "$value"
}

write_session() {
  local command_text="${1:-}"
  {
    printf '%s\n' \
      'set cmd:fail-exit yes' \
      'set net:max-retries 1' \
      'set net:timeout 30' \
      'set ftp:ssl-force yes' \
      'set ftp:ssl-protect-data yes' \
      'set ftp:ssl-auth TLS' \
      'set ftp:passive-mode yes' \
      'set ssl:verify-certificate yes'
    printf 'open ftp://%s:%s\n' "$FTP_HOST" "$FTP_PORT"
    printf 'cd %s\n' "$(lftp_quote "$FTP_REMOTE_DIR")"
    if [[ -n "$command_text" ]]; then
      printf '%s\n' "$command_text"
    fi
    printf '%s\n' 'bye'
  } > "$LFTP_FILE"
  chmod 600 "$LFTP_FILE"
}

run_lftp() {
  write_session "${1:-}"
  HOME="$AUTH_HOME" lftp --norc -f "$LFTP_FILE"
}

cleanup() {
  local result=$?
  trap - EXIT
  if [[ -n "${PROBE_REMOTE:-}" ]]; then
    local remove_cmd
    remove_cmd="rm $(lftp_quote "$PROBE_REMOTE")"
    if ! run_lftp "$remove_cmd"; then
      echo "::error::Smoke-test cleanup could not be confirmed. Inspect this exact remote path: ${FTP_REMOTE_DIR%/}/${PROBE_REMOTE##*/}" >&2
      result=1
    fi
  fi
  rm -rf "$TEMP_DIR"
  exit "$result"
}
trap cleanup EXIT

case "$MODE" in
  connection-test)
    run_lftp 'pwd'
    echo "FTPS connection, TLS certificate validation, login, and remote directory check succeeded."
    ;;
  smoke-test)
    PROBE_REMOTE=".surkove-ftps-smoke-${GITHUB_RUN_ID:-manual}-$(python3 -c 'import secrets; print(secrets.token_hex(8))').tmp"
    local_probe="$TEMP_DIR/probe.txt"
    local_copy="$TEMP_DIR/probe-copy.txt"
    printf 'Surkove FTPS smoke test %s\n' "${GITHUB_RUN_ID:-manual}" > "$local_probe"
    put_cmd="put $(lftp_quote "$local_probe") -o $(lftp_quote "$PROBE_REMOTE"); get $(lftp_quote "$PROBE_REMOTE") -o $(lftp_quote "$local_copy")"
    run_lftp "$put_cmd"
    cmp -s "$local_probe" "$local_copy" || { echo "FTPS smoke-test verification failed." >&2; exit 1; }
    run_lftp "rm $(lftp_quote "$PROBE_REMOTE")"
    PROBE_REMOTE=""
    echo "FTPS write, read-back verification, and cleanup succeeded; no smoke-test file remains."
    ;;
  dry-run)
    run_lftp "mirror --dry-run --reverse --verbose --parallel=1 --transfer-all $(lftp_quote "$GITHUB_WORKSPACE/dist") ."
    ;;
  deploy)
    run_lftp "mirror --reverse --verbose --parallel=1 --transfer-all $(lftp_quote "$GITHUB_WORKSPACE/dist") ."
    echo "FTPS upload succeeded. No remote files were deleted; only files from dist were transferred."
    ;;
  *)
    echo "Usage: $0 {connection-test|smoke-test|dry-run|deploy}" >&2
    exit 2
    ;;
esac
