#!/usr/bin/env python3
"""Deploy explicitly selected www/ files, with checked preimages and recovery.

  python3 run/deploy_site_files.py plan --out /private/path/plan.json www/index.html
  python3 run/deploy_site_files.py deploy /private/path/plan.json --verify-url https://strim.in.ua/
  python3 run/deploy_site_files.py rollback /private/path/plan.receipt.json

Plan reads production without changing it. Deploy requires that reviewed plan,
unchanged local files, and at least one explicit public GET check. Remote python3,
rsync and (for PHP) php are required. New files require existing parent directories.
Choose public check URLs for the changed pages/assets: HTTPS 2xx checks do not
prove visual layout or application behavior, which must be reviewed separately.
Backups stay in REMOTE_ROOT/.site-deploy outside www/. Deploy never deletes a
directory or sends the working tree. A file set is not a single atomic operation;
each replacement is atomic, and a failed check attempts a checked rollback.
"""

import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import shlex
import shutil
import stat
import subprocess
import sys
import tempfile
import urllib.parse
import urllib.request
import uuid


# Self-contained transaction engine, sent through the existing SSH helper. Its
# validation is also used locally so plan files cannot bypass either boundary.
REMOTE_CODE = r'''
import contextlib, fcntl, hashlib, json, os, pathlib, re, shutil, stat, subprocess, sys

def require(condition, message):
    if not condition:
        raise ValueError(message)

def relative(value):
    require(isinstance(value, str) and re.fullmatch(r"www/[A-Za-z0-9_@.+/-]+", value), "invalid selected path")
    parts = value.split("/")
    require(all(p not in ("", ".", "..") for p in parts), "path traversal is forbidden")
    denied = {"vendor", "node_modules", "runtime", "data", "cache", "sessions", "uploads", "logs", "log", "backups", "backup", "tmp", "temp", ".git", ".ssh", "secrets", "credentials"}
    require(not any(p.lower() in denied or p.lower().startswith(".env") for p in parts), "selected path is protected")
    name = parts[-1].lower()
    require(not re.search(r"(^|[._-])(credentials?|secrets?|private[-_]?key|id_rsa|id_ed25519)([._-]|$)", name), "credential files are forbidden")
    require(not re.search(r"\.(sql|dump|sqlite\d?|db|pem|key|p12|pfx|log|bak|backup|old|orig|tmp|swp)(\.(gz|zip|bz2|xz))?$", name), "runtime, dump or credential files are forbidden")
    return pathlib.PurePosixPath(value)

def root_path(value):
    require(isinstance(value, str) and re.fullmatch(r"/[A-Za-z0-9_./-]+", value), "invalid remote root")
    root = pathlib.Path(value)
    require(str(root) == value and ".." not in root.parts and root.is_dir() and root.resolve() == root, "remote root must be an existing canonical directory")
    return root

def checked(base, rel, parent_exists=True):
    path = base
    parts = pathlib.PurePosixPath(rel).parts
    for i, part in enumerate(parts):
        require(part not in ("", ".", ".."), "invalid path component")
        path = path / part
        require(not path.is_symlink(), "symlinks are forbidden")
        if i < len(parts) - 1 and parent_exists:
            require(path.is_dir(), "selected parent directory must exist")
    return path

def snapshot(path):
    try:
        info = path.lstat()
    except FileNotFoundError:
        return {"exists": False}
    require(stat.S_ISREG(info.st_mode), "selected target must be a regular file")
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    after = path.lstat()
    require((info.st_ino, info.st_size, info.st_mtime_ns, info.st_mode) == (after.st_ino, after.st_size, after.st_mtime_ns, after.st_mode), "file changed while reading")
    return {"exists": True, "sha256": digest.hexdigest(), "mode": stat.S_IMODE(info.st_mode), "size": info.st_size}

def validate(plan):
    require(plan.get("version") == 1 and isinstance(plan.get("files"), list) and plan["files"], "invalid deployment plan")
    names = set()
    for item in plan["files"]:
        relative(item["path"])
        require(item["path"] not in names, "duplicate selected path")
        names.add(item["path"])
        for record in (item["local"], item.get("before", {"exists": False})):
            require(type(record.get("exists")) is bool, "invalid file record")
            if record["exists"]:
                require(re.fullmatch(r"[0-9a-f]{64}", str(record.get("sha256"))), "invalid file digest")
                require(type(record.get("mode")) is int and 0 <= record["mode"] <= 0o777, "special permissions are forbidden")
                require(type(record.get("size")) is int and record["size"] >= 0, "invalid file size")
        require(item["local"]["exists"], "local file is missing")

def write_json(path, value):
    temp = path.with_name(path.name + ".new")
    with temp.open("w", encoding="utf-8") as handle:
        os.chmod(temp, 0o600)
        json.dump(value, handle, sort_keys=True)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temp, path)

def private_dir(path):
    require(not path.is_symlink(), "transaction directory is a symlink")
    path.mkdir(mode=0o700, exist_ok=True)
    require(path.is_dir() and path.stat().st_uid == os.getuid() and not path.stat().st_mode & 0o077, "transaction directory must be private and owned by this user")

def acquire(area, token):
    lock = area / "lock"
    try:
        lock.mkdir(mode=0o700)
    except FileExistsError:
        require(not lock.is_symlink() and lock.is_dir(), "invalid deployment lock")
        require((lock / "owner").read_text() == token, "another deployment holds the lock")
    else:
        (lock / "owner").write_text(token)

def release(area, token):
    lock = area / "lock"
    require(not lock.is_symlink() and (lock / "owner").read_text() == token, "deployment lock ownership changed")
    (lock / "owner").unlink()
    lock.rmdir()

def expected_after(item):
    record = dict(item["local"])
    record["mode"] = item["before"].get("mode", record["mode"])
    return record

def all_current(root, plan, kind):
    for item in plan["files"]:
        want = item["before"] if kind == "before" else expected_after(item)
        require(snapshot(checked(root, item["path"])) == want, "preimage or deployed-file conflict: " + item["path"])

def engine(request):
    action = request["action"]
    plan = request["plan"]
    validate(plan)
    root = root_path(plan["target"]["root"])
    if action == "inspect":
        return {item["path"]: snapshot(checked(root, item["path"])) for item in plan["files"]}
    if action == "prepare":
        all_current(root, plan, "before")  # Stale plans do not create a lock.
    token = request["token"]
    require(re.fullmatch(r"[a-zA-Z0-9_-]{12,80}", token), "invalid transaction id")
    area = root / ".site-deploy"
    private_dir(area)
    acquire(area, token)
    tx = area / token
    private_dir(tx)
    lockpath = tx / "operation.lock"
    require(not lockpath.is_symlink(), "invalid transaction operation lock")
    with lockpath.open("a") as operation_lock:
        fcntl.flock(operation_lock, fcntl.LOCK_EX)
        state_path = tx / "manifest.json"
        if action == "prepare":
            require(not state_path.exists(), "transaction already exists; use its receipt for recovery")
            state = {"plan": plan, "status": "preparing"}
            write_json(state_path, state)
            all_current(root, plan, "before")
            private_dir(tx / "backup")
            private_dir(tx / "stage")
            for item in plan["files"]:
                if item["before"]["exists"]:
                    source = checked(root, item["path"])
                    require(snapshot(source) == item["before"], "preimage changed before backup")
                    backup = tx / "backup" / item["path"]
                    backup.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
                    shutil.copyfile(source, backup)
                    os.chmod(backup, 0o600)
                    require(snapshot(backup)["sha256"] == item["before"]["sha256"], "backup verification failed")
                    with backup.open("rb") as handle:
                        os.fsync(handle.fileno())
            all_current(root, plan, "before")
            state["status"] = "prepared"
            write_json(state_path, state)
            return {"stage": str(tx / "stage"), "backup": str(tx / "backup")}
        if action == "rollback" and not state_path.exists():
            # prepare can fail before saving state, but cannot have touched www.
            release(area, token)
            return {"status": "cancelled"}
        require(state_path.is_file() and not state_path.is_symlink(), "missing transaction manifest; inspect the deployment lock manually")
        state = json.loads(state_path.read_text())
        require(state["plan"] == plan, "receipt does not match remote transaction")
        if action == "install":
            require(state["status"] == "prepared", "transaction is not prepared")
            all_current(root, plan, "before")
            for item in plan["files"]:
                staged = checked(tx / "stage", item["path"])
                actual = snapshot(staged)
                require(actual["exists"] and actual["sha256"] == item["local"]["sha256"] and actual["size"] == item["local"]["size"], "staged file verification failed: " + item["path"])
                if item["path"].lower().endswith((".php", ".phtml", ".php5", ".inc")):
                    result = subprocess.run([request.get("php", "php"), "-l", str(staged)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    require(result.returncode == 0, "PHP lint failed: " + item["path"])
                os.chmod(staged, expected_after(item)["mode"])
                with staged.open("rb") as handle:
                    os.fsync(handle.fileno())
            # Nothing in www is changed until every backup, digest and lint passes.
            all_current(root, plan, "before")
            state["status"] = "installing"
            write_json(state_path, state)
            for item in plan["files"]:
                target = checked(root, item["path"])
                require(snapshot(target) == item["before"], "preimage changed during installation: " + item["path"])
                os.replace(checked(tx / "stage", item["path"]), target)
            all_current(root, plan, "after")
            state["status"] = "awaiting_public_check"
            write_json(state_path, state)
            return {"status": state["status"]}
        if action == "finish":
            require(state["status"] == "awaiting_public_check", "transaction has not been installed")
            all_current(root, plan, "after")
            state["status"] = "deployed"
            write_json(state_path, state)
            release(area, token)
            return {"status": state["status"]}
        if action == "rollback":
            if state["status"] in ("preparing", "prepared", "cancelled"):
                # No replacement starts before the durable 'installing' state.
                # Independent website edits during staging need no restoration.
                state["status"] = "cancelled"
                write_json(state_path, state)
                release(area, token)
                return {"status": state["status"]}
            # Inspect all files before restoring anything. A subsequent deployment
            # or independent edit must never be silently overwritten by recovery.
            restore = []
            for item in plan["files"]:
                target = checked(root, item["path"])
                current = snapshot(target)
                if current == item["before"]:
                    continue
                require(current == expected_after(item), "rollback conflict: " + item["path"])
                backup = checked(tx / "backup", item["path"], parent_exists=False)
                if item["before"]["exists"]:
                    require(snapshot(backup)["sha256"] == item["before"]["sha256"], "backup missing or damaged")
                restore.append((item, target, backup))
            for item, target, backup in restore:
                require(snapshot(target) == expected_after(item), "file changed during rollback")
                if item["before"]["exists"]:
                    restored = tx / "restore" / item["path"]
                    restored.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
                    require(not restored.exists() and not restored.is_symlink(), "rollback staging path already exists")
                    shutil.copyfile(backup, restored)
                    os.chmod(restored, item["before"]["mode"])
                    with restored.open("rb") as handle:
                        os.fsync(handle.fileno())
                    os.replace(restored, target)
                else:
                    target.unlink()
            all_current(root, plan, "before")
            state["status"] = "rolled_back"
            write_json(state_path, state)
            release(area, token)
            return {"status": state["status"]}
        raise ValueError("unknown remote operation")

def remote_main():
    try:
        result = engine(json.load(sys.stdin))
        print(json.dumps({"ok": True, "result": result}))
    except Exception as error:
        # Never print PHP output, file contents or credentials on failure.
        message = str(error) if isinstance(error, ValueError) else type(error).__name__
        print(json.dumps({"ok": False, "error": message}))
        sys.exit(1)
'''

_remote = {}
exec(compile(REMOTE_CODE, "<deployment-engine>", "exec"), _remote)


class DeployError(Exception):
    pass


def connection():
    user = os.environ.get("DEPLOY_USER", "bot20")
    host = os.environ.get("DEPLOY_HOST", "bot20.ftp.tools")
    port = os.environ.get("DEPLOY_SSH_PORT", "22")
    if not all(re.fullmatch(r"[A-Za-z0-9_.-]+", part) for part in (user, host)) or not port.isdecimal():
        raise DeployError("Invalid SSH connection settings")
    return {"host": user + "@" + host, "port": port}


def remote(repo, action, plan, token=None):
    request = {"action": action, "plan": plan, "token": token, "php": os.environ.get("DEPLOY_PHP", "php")}
    env = dict(os.environ, SSH_PROD_RETRIES="1")
    try:
        result = subprocess.run(
            [str(repo / "run/ssh_prod.sh"), "python3 -c " + shlex.quote(REMOTE_CODE + "\nremote_main()\n")],
            input=json.dumps(request), text=True, capture_output=True, env=env, timeout=300,
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        raise DeployError("SSH operation failed: " + type(error).__name__) from error
    try:
        response = json.loads(result.stdout)
    except (ValueError, TypeError):
        raise DeployError("SSH operation failed or returned an invalid response (exit %s)" % result.returncode)
    if result.returncode != 0 or not response.get("ok"):
        raise DeployError("Remote operation failed (exit %s): %s" % (result.returncode, response.get("error", "transport error")))
    return response["result"]


def write_private(path, value, exclusive=False):
    path.parent.mkdir(parents=True, exist_ok=True)
    flags = os.O_WRONLY | os.O_CREAT | (os.O_EXCL if exclusive else os.O_TRUNC)
    with os.fdopen(os.open(path, flags, 0o600), "w", encoding="utf-8") as handle:
        os.chmod(path, 0o600)
        json.dump(value, handle, indent=2, sort_keys=True)
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())


def load_plan(path):
    plan = json.loads(path.read_text())
    _remote["validate"](plan)
    live_connection = connection()
    if any(plan["target"].get(key) != value for key, value in live_connection.items()):
        raise DeployError("SSH target differs from the plan; restore DEPLOY_USER/HOST/SSH_PORT")
    return plan


def local_path(repo, name):
    _remote["relative"](name)
    path = _remote["checked"](repo, name)
    if not path.is_file():
        raise DeployError("Selected local file is missing: " + name)
    return path


def make_plan(repo, names, out, root):
    files = [{"path": name, "local": _remote["snapshot"](local_path(repo, name))} for name in names]
    plan = {"version": 1, "created": datetime.datetime.now(datetime.timezone.utc).isoformat(), "target": dict(connection(), root=root), "files": files}
    _remote["validate"](plan)
    before = remote(repo, "inspect", plan)
    for item in files:
        item["before"] = before[item["path"]]
    _remote["validate"](plan)
    write_private(out, plan, exclusive=True)
    for item in files:
        print(("replace " if item["before"]["exists"] else "create  ") + item["path"])
    print("Reviewed plan saved: " + str(out))


def verify_public(urls):
    for url in urls:
        parsed = urllib.parse.urlsplit(url)
        if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password:
            raise DeployError("Public verification requires an HTTPS URL without credentials")
        request = urllib.request.Request(url, headers={"User-Agent": "StrIM-selected-deploy/1", "Cache-Control": "no-cache"}, method="GET")
        with urllib.request.urlopen(request, timeout=30) as response:
            if not 200 <= response.status < 300 or urllib.parse.urlsplit(response.url).scheme != "https":
                raise DeployError("Public GET verification failed")
            response.read(1024)  # Do not log response bodies or request credentials.


def deploy(repo, plan_path, urls, receipt_path):
    plan = load_plan(plan_path)
    # Reject invalid check URLs before acquiring a remote deployment lock.
    for url in urls:
        parsed = urllib.parse.urlsplit(url)
        if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password:
            raise DeployError("Public verification requires an HTTPS URL without credentials")
    token = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ_") + uuid.uuid4().hex[:16]
    receipt = {"plan": plan, "token": token, "status": "pending"}
    with tempfile.TemporaryDirectory(prefix="strim-selected-") as staging_dir:
        staging = Path(staging_dir)
        for item in plan["files"]:
            source = local_path(repo, item["path"])
            if _remote["snapshot"](source) != item["local"]:
                raise DeployError("Local file changed since the plan: " + item["path"])
            copied = staging / item["path"]
            copied.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, copied)
            os.chmod(copied, 0o600)
            if _remote["snapshot"](copied)["sha256"] != item["local"]["sha256"]:
                raise DeployError("Local file changed while preparing deployment")
        write_private(receipt_path, receipt, exclusive=True)
        prepared = False
        try:
            prepared = True  # A lost SSH response can still leave a remote transaction.
            result = remote(repo, "prepare", plan, token)
            upload = subprocess.run(
                [str(repo / "run/rsync_prod.sh"), str(staging) + "/", plan["target"]["host"] + ":" + result["stage"] + "/"],
                text=True, capture_output=True, env=dict(os.environ, SSH_PROD_RETRIES="1"), timeout=300,
            )
            if upload.returncode:
                raise DeployError("Staging upload failed (exit %s)" % upload.returncode)
            remote(repo, "install", plan, token)
            verify_public(urls)
            remote(repo, "finish", plan, token)
            receipt["status"] = "deployed"
        except BaseException as error:
            receipt["status"] = "recovery_required"
            if prepared:
                try:
                    recovery = remote(repo, "rollback", plan, token)
                    receipt["status"] = recovery["status"]
                except Exception:
                    pass
            write_private(receipt_path, receipt)
            if receipt["status"] == "rolled_back":
                suffix = "; rollback verified"
            elif receipt["status"] == "cancelled":
                suffix = "; cancelled before any website replacement"
            else:
                suffix = "; recovery could not be verified; use the receipt to retry rollback"
            raise DeployError("Deployment failed (" + type(error).__name__ + ")" + suffix + ": " + str(receipt_path)) from error
        write_private(receipt_path, receipt)
    print("Selected files deployed; hashes, applicable PHP lint and public HTTP checks passed.")
    print("Rollback receipt: " + str(receipt_path))
    print("Remote originals: " + plan["target"]["root"] + "/.site-deploy/" + token + "/backup")


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    commands = parser.add_subparsers(dest="command", required=True)
    plan = commands.add_parser("plan", help="Read-only production preimages; never upload or deploy")
    plan.add_argument("--out", required=True, type=Path)
    plan.add_argument("--remote-root", default="/home/bot20/strim.in.ua")
    plan.add_argument("files", nargs="+")
    send = commands.add_parser("deploy", help="Deploy only the files in an unchanged plan")
    send.add_argument("plan", type=Path)
    send.add_argument("--verify-url", action="append", required=True, help="Relevant changed public page/asset; HTTPS 2xx only, repeat for multiple checks")
    send.add_argument("--receipt", type=Path)
    undo = commands.add_parser("rollback", help="Restore only if current files still match this deployment")
    undo.add_argument("receipt", type=Path)
    args = parser.parse_args()
    repo = Path(__file__).resolve().parents[1]
    try:
        if args.command == "plan":
            make_plan(repo, args.files, args.out.resolve(), args.remote_root)
        elif args.command == "deploy":
            deploy(repo, args.plan, args.verify_url, args.receipt or args.plan.with_suffix(".receipt.json"))
        else:
            receipt = json.loads(args.receipt.read_text())
            _remote["validate"](receipt["plan"])
            if any(receipt["plan"]["target"].get(k) != v for k, v in connection().items()):
                raise DeployError("SSH target differs from receipt")
            recovery = remote(repo, "rollback", receipt["plan"], receipt["token"])
            receipt["status"] = recovery["status"]
            write_private(args.receipt, receipt)
            if recovery["status"] == "cancelled":
                print("Transaction cancelled before any website replacement.")
            else:
                print("Rollback verified: original contents, file existence and permissions restored.")
    except (DeployError, ValueError, OSError, KeyError, subprocess.TimeoutExpired) as error:
        print("ERROR: " + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
