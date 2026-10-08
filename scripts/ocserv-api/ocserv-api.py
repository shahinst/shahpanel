#!/usr/bin/env python3
"""ShahPanel ocserv API: the HTTP contract of OcservClient, backed by ocpasswd and occtl."""

import base64
import hmac
import json
import os
import re
import ssl
import subprocess
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import unquote

CONFIG = json.load(open("/etc/ocserv-api/config.json"))
PASSWD = CONFIG.get("passwd_file", "/etc/ocserv/ocpasswd")
PER_USER = CONFIG.get("per_user_dir", "/etc/ocserv/config-per-user")
OCSERV_CONF = CONFIG.get("ocserv_conf", "/etc/ocserv/ocserv.conf")
USERNAME_RE = re.compile(r"^[A-Za-z0-9._@-]{1,64}$")
LOCK = threading.Lock()


class ApiError(Exception):
    def __init__(self, status, message):
        super().__init__(message)
        self.status = status


def run(args, stdin=None):
    done = subprocess.run(args, input=stdin, capture_output=True, text=True, timeout=30)
    if done.returncode != 0:
        raise ApiError(500, (done.stderr or done.stdout).strip()[:300] or "command failed")
    return done.stdout


def entries():
    """username -> (group, locked) from the ocpasswd file."""
    out = {}
    if os.path.exists(PASSWD):
        for line in open(PASSWD):
            parts = line.rstrip("\n").split(":")
            if len(parts) >= 3 and parts[0]:
                group = parts[1] if parts[1] not in ("", "*") else None
                out[parts[0]] = (group, parts[2].startswith("!"))
    return out


def max_sessions(username):
    path = os.path.join(PER_USER, username)
    if os.path.exists(path):
        for line in open(path):
            match = re.match(r"\s*max-same-clients\s*=\s*(\d+)", line)
            if match:
                return int(match.group(1))
    return None


def write_limit(username, value):
    path = os.path.join(PER_USER, username)
    if value is None:
        if os.path.exists(path):
            os.remove(path)
        return
    os.makedirs(PER_USER, exist_ok=True)
    with open(path, "w") as handle:
        handle.write("max-same-clients = %d\n" % max(0, min(1000, int(value))))


def user(username):
    found = entries().get(username)
    if found is None:
        raise ApiError(404, "user not found")
    return {"username": username, "group": found[0], "locked": found[1], "max_sessions": max_sessions(username)}


def set_password(username, password, group):
    if not isinstance(password, str) or not 1 <= len(password) <= 128 or "\n" in password or "\r" in password:
        raise ApiError(422, "invalid password")
    args = ["ocpasswd", "-c", PASSWD]
    if group:
        args += ["-g", group]
    run(args + [username], stdin=password + "\n" + password + "\n")


def disconnect(username):
    subprocess.run(["occtl", "disconnect", "user", username], capture_output=True, timeout=30)


def sessions():
    raw = subprocess.run(["occtl", "-j", "show", "users"], capture_output=True, text=True, timeout=30).stdout
    try:
        rows = json.loads(raw or "[]")
    except ValueError:
        rows = []
    return [row for row in rows if isinstance(row, dict) and row.get("Username") not in (None, "(none)")]


def pool_size():
    """Client addresses ocserv can hand out (ipv4-netmask, as dotted or /bits)."""
    try:
        text = open(OCSERV_CONF).read()
    except OSError:
        return 0
    match = re.search(r"^\s*ipv4-netmask\s*=\s*(\S+)", text, re.M)
    if not match:
        match = re.search(r"^\s*ipv4-network\s*=\s*\S+/(\d+)", text, re.M)
    if not match:
        return 0
    value = match.group(1).lstrip("/")
    bits = int(value) if value.isdigit() else sum(bin(int(octet)).count("1") for octet in value.split("."))
    # Network, broadcast and the server's own address are not for clients.
    return max(0, 2 ** (32 - bits) - 3)


def reload_ocserv():
    subprocess.run(["occtl", "reload"], capture_output=True, timeout=30)


def handle(method, parts, body):
    if parts == ["health"] and method == "GET":
        active = subprocess.run(["systemctl", "is-active", "ocserv"], capture_output=True, text=True).stdout.strip()
        return 200, {"ok": active == "active", "ocserv": active, "pool_size": pool_size(), "sessions": len(sessions())}

    if parts == ["users"] and method == "GET":
        return 200, {"users": [user(name) for name in entries()]}

    if parts == ["users"] and method == "POST":
        username = str(body.get("username", ""))
        if not USERNAME_RE.match(username) or username in (".", ".."):
            raise ApiError(422, "invalid username")
        if username in entries():
            raise ApiError(409, "user exists")
        group = body.get("group") or None
        set_password(username, body.get("password"), group)
        write_limit(username, body.get("max_sessions"))
        reload_ocserv()
        return 201, user(username)

    if parts[:1] == ["users"] and len(parts) in (2, 3):
        username = parts[1]
        if not USERNAME_RE.match(username) or username in (".", ".."):
            raise ApiError(404, "user not found")
        current = user(username)
        action = parts[2] if len(parts) == 3 else None

        if action is None and method == "GET":
            return 200, current
        if action is None and method == "DELETE":
            disconnect(username)
            run(["ocpasswd", "-c", PASSWD, "-d", username])
            write_limit(username, None)
            return 200, {"ok": True}
        if action == "password" and method == "PUT":
            set_password(username, body.get("password"), current["group"])
            if current["locked"]:
                run(["ocpasswd", "-c", PASSWD, "-l", username])
            return 200, user(username)
        if action == "limits" and method == "PUT":
            write_limit(username, body.get("max_sessions"))
            reload_ocserv()
            return 200, user(username)
        if action == "lock" and method == "POST":
            if not current["locked"]:
                run(["ocpasswd", "-c", PASSWD, "-l", username])
            disconnect(username)
            return 200, user(username)
        if action == "unlock" and method == "POST":
            if current["locked"]:
                run(["ocpasswd", "-c", PASSWD, "-u", username])
            return 200, user(username)

    if parts == ["sessions"] and method == "GET":
        return 200, {"sessions": sessions()}

    if parts[:1] == ["sessions"] and len(parts) == 3 and parts[2] == "disconnect" and method == "POST":
        if not any(row.get("Username") == parts[1] for row in sessions()):
            raise ApiError(404, "no session")
        disconnect(parts[1])
        return 200, {"ok": True}

    if parts == ["traffic"] and method == "GET":
        totals = {}
        for row in sessions():
            name = row["Username"]
            rx, tx = totals.get(name, (0, 0))
            totals[name] = (rx + int(row.get("RX", 0) or 0), tx + int(row.get("TX", 0) or 0))
        return 200, {"users": [{"username": n, "rx": v[0], "tx": v[1]} for n, v in totals.items()]}

    if parts == ["tunnels"] and method == "GET":
        return 200, {"tunnels": len(sessions())}

    raise ApiError(404, "not found")


class Handler(BaseHTTPRequestHandler):
    server_version = "ocserv-api"
    sys_version = ""

    def authorized(self):
        header = self.headers.get("Authorization", "")
        token = ""
        if header.startswith("Bearer "):
            token = header[7:]
        elif header.startswith("Basic "):
            try:
                name, _, token = base64.b64decode(header[6:]).decode().partition(":")
            except Exception:
                return False
            if not hmac.compare_digest(name, CONFIG["username"]):
                return False
        return hmac.compare_digest(token, CONFIG["token"])

    def reply(self, status, payload):
        data = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def dispatch(self, method):
        if self.client_address[0] not in CONFIG["allow"]:
            return self.reply(403, {"error": "forbidden"})
        if not self.authorized():
            return self.reply(401, {"error": "unauthorized"})
        path = self.path.split("?", 1)[0]
        if not path.startswith("/api/"):
            return self.reply(404, {"error": "not found"})
        length = int(self.headers.get("Content-Length") or 0)
        if length > 65536:
            return self.reply(413, {"error": "too large"})
        try:
            body = json.loads(self.rfile.read(length) or b"{}") if length else {}
            if not isinstance(body, dict):
                body = {}
            with LOCK:
                status, payload = handle(method, [unquote(p) for p in path[5:].split("/") if p], body)
        except ApiError as error:
            status, payload = error.status, {"error": str(error)}
        except Exception as error:
            status, payload = 500, {"error": str(error)[:300]}
        self.reply(status, payload)

    def do_GET(self):
        self.dispatch("GET")

    def do_POST(self):
        self.dispatch("POST")

    def do_PUT(self):
        self.dispatch("PUT")

    def do_DELETE(self):
        self.dispatch("DELETE")

    def log_message(self, fmt, *args):
        pass


if __name__ == "__main__":
    server = ThreadingHTTPServer(("0.0.0.0", int(CONFIG.get("port", 9443))), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(CONFIG["cert"], CONFIG["key"])
    server.socket = context.wrap_socket(server.socket, server_side=True)
    server.serve_forever()
