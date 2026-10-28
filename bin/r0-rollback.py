#!/usr/bin/env python3
"""Explicit rollback: restores versioned pre-release application files and cron."""
from pathlib import Path
import sys,tarfile,subprocess,os
app=Path('/home/bathokwe/fyndet-app').resolve()
if os.getuid()==0:raise SystemExit('Run as bathokwe')
if len(sys.argv)!=2:raise SystemExit('Usage: r0-rollback.py /home/bathokwe/fyndet-app/var/r0-backup-<stamp>')
backup=Path(sys.argv[1]).resolve()
if backup.parent!=(app/'var').resolve() or not backup.name.startswith('r0-backup-'):raise SystemExit('Invalid backup path')
archive=backup/'application-before.tar.gz'
live=Path((backup/'live-entry-path.txt').read_text().strip()) if (backup/'live-entry-path.txt').is_file() else None
if not archive.is_file():raise SystemExit('Backup archive missing')
for rel in ['app/discovery_r0_base.php','app/discovery_search_links.php','app/discovery_url_intake.php','app/r0_storage.php','app/r0_ui.php','bin/r0-intake-worker.php','bin/fixture-check.php','bin/r0-rollback.py','tests/discovery-r0-offline.php','README-R0.md','var/fyndet-r0-flags.ini']:
 p=app/rel
 if p.is_file():p.unlink()
with tarfile.open(archive) as t:
 for member in t.getmembers():
  if member.issym() or member.islnk() or member.name.startswith('/') or '..' in Path(member.name).parts:raise SystemExit('Unsafe archive')
 t.extractall(app)
if live is not None and (backup/'live-index-before.php').is_file():
 import shutil;shutil.copy2(backup/'live-index-before.php',live)
subprocess.run(['crontab',str(backup/'crontab-before.txt')],check=True)
print('PASS: restored files and cron from',backup)
print('NOTE: additive fyndet_ tables are retained; no database drops were performed.')
