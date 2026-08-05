#!/usr/bin/env bash
# Deploy the Weyney CRM to Hostinger.
#
# Sends only files tracked by git, so anything untracked or ignored stays local.
# Refuses to run on a dirty tree unless --force is given, which keeps what is on
# the server identical to a commit you can point at.
#
#   ./deploy.sh            deploy HEAD
#   ./deploy.sh --dry-run  show what would change, send nothing
#   ./deploy.sh --force    deploy with uncommitted changes

set -euo pipefail
cd "$(dirname "$0")"

REMOTE=hostinger
DEST='domains/weyney.com/public_html/apps/crm'

# Repo-only files that must never land in the web root. The attachment PDFs do
# ship — the mailer reads them off disk — but their print sources do not.
EXCLUDE='^(deploy\.sh|\.gitignore|README\.md|attachments/src/.*)$'

DRY=""
FORCE=""
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY="--dry-run" ;;
    --force)   FORCE=1 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

if [ -n "$(git status --porcelain)" ] && [ -z "$FORCE" ]; then
  echo "Working tree is dirty. Commit first, or re-run with --force." >&2
  git status --short >&2
  exit 1
fi

if ! node --check assets/app.js; then
  echo "assets/app.js failed its syntax check — refusing to deploy." >&2
  exit 1
fi

git ls-files -z | grep -zEv "$EXCLUDE" |
  rsync -az --relative --files-from=- --from0 $DRY ./ "$REMOTE:$DEST/"

if [ -n "$DRY" ]; then
  echo "Dry run only — nothing was sent."
else
  echo "Deployed $(git rev-parse --short HEAD) to $REMOTE:$DEST"
  echo "Check: https://apps.weyney.com/crm/"
fi
