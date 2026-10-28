#!/usr/bin/env bash
set -Eeuo pipefail
APP=/home/bathokwe/fyndet-app
PHP=/opt/cpanel/ea-php83/root/usr/bin/php
OWNER=bathokwe
SOURCES="$APP/app/discovery_sources.php"
PIPELINE="$APP/app/discovery_pipeline.php"
BACKUP="$APP/var/github-removal-$(date -u +%Y%m%dT%H%M%SZ)"
STAGE="$(mktemp -d)"
APPLIED=0
trap 'rm -rf "$STAGE"' EXIT
echo '===== FYNDET — REMOVE GITHUB DISCOVERY ====='
test "$(id -u)" -eq 0
test -x "$PHP"
test -f "$SOURCES"
test -f "$PIPELINE"
test -f "$APP/bin/process-investigations.php"
install -d -m 700 "$BACKUP"
cp -a "$SOURCES" "$BACKUP/discovery_sources.php"
cp -a "$PIPELINE" "$BACKUP/discovery_pipeline.php"
rollback(){
  if test "$APPLIED" = 1; then
    cp -a "$BACKUP/discovery_sources.php" "$SOURCES"
    cp -a "$BACKUP/discovery_pipeline.php" "$PIPELINE"
    echo 'ROLLBACK: original discovery components restored.'
  fi
}
trap 'rollback' ERR
python3 - "$SOURCES" "$PIPELINE" "$STAGE" <<'PY'
import sys,re
from pathlib import Path
sources=Path(sys.argv[1]).read_text()
pipeline=Path(sys.argv[2]).read_text()
stage=Path(sys.argv[3])
marker="function fyndetDGitHub("
start=sources.find(marker)
if start >= 0:
    brace=sources.find("{",start)
    if brace<0: raise SystemExit("FAIL: GitHub function has no opening brace")
    depth=0
    end=None
    for i in range(brace,len(sources)):
        if sources[i]=="{":depth+=1
        elif sources[i]=="}":
            depth-=1
            if depth==0:
                end=i+1
                break
    if end is None:raise SystemExit("FAIL: GitHub function unbalanced")
    sources=sources[:start]+sources[end:].lstrip("\n")
sources=sources.replace("'api.github.com',","")
sources=sources.replace(",'api.github.com'","")
pipeline=pipeline.replace("'github'=>'technical_associations',","")
pipeline=pipeline.replace("'github'=>fn()=>fyndetDGitHub($email,$env),","")
if re.search(r"fyndetDGitHub|FYNDET_GITHUB_ENABLED|GITHUB_SEARCH_TOKEN|api\.github\.com|search/commits",sources+pipeline,re.I):
    raise SystemExit("FAIL: GitHub integration remains")
if "'openpgp'=>fn()=>fyndetDOpenPgp" not in pipeline:
    raise SystemExit("FAIL: other source orchestration differs")
(stage/"discovery_sources.php").write_text(sources)
(stage/"discovery_pipeline.php").write_text(pipeline)
print("PASS: GitHub source and orchestrator removed; other sources retained")
PY
"$PHP" -l "$STAGE/discovery_sources.php"
"$PHP" -l "$STAGE/discovery_pipeline.php"
echo '===== APPLY WITH ROLLBACK ====='
APPLIED=1
install -o "$OWNER" -g "$OWNER" -m 640 "$STAGE/discovery_sources.php" "$SOURCES"
install -o "$OWNER" -g "$OWNER" -m 640 "$STAGE/discovery_pipeline.php" "$PIPELINE"
"$PHP" -l "$SOURCES"
"$PHP" -l "$PIPELINE"
"$PHP" -l "$APP/bin/process-investigations.php"
runuser -u "$OWNER" -- "$PHP" -r '
require $argv[1]."/app/discovery_verifier.php";
require $argv[1]."/app/discovery_sources.php";
if(function_exists("fyndetDGitHub")) exit(2);
if(!function_exists("fyndetDOpenPgp")) exit(3);
echo "PASS: GitHub removed; OpenPGP preserved.\n";
' "$APP"
echo '===== NO GITHUB DISCOVERY IN ACTIVE PIPELINE ====='
if grep -Ein 'github|fyndetDGitHub|GITHUB_SEARCH_TOKEN' "$SOURCES" "$PIPELINE"; then
  echo 'FAIL: GitHub reference found'
  exit 1
fi
APPLIED=0
trap - ERR
echo 'PASS: GitHub removed from active FyndEt discovery.'
echo "Backup: $BACKUP"
echo 'No customer database, credit, PayFast or subscription changes.'
