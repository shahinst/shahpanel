#!/usr/bin/env python3
"""ocserv disconnect-script: adds a closed session's final byte counts to its
user's lifetime totals, which the ShahPanel API reports.

occtl forgets a session the moment it ends, so whatever it moved since the
panel last looked would be lost; ocserv hands the final figures to this hook
instead. It runs inside session teardown, so it must be quick and must never
fail: every error is swallowed and the exit code is always 0.
"""
import fcntl
import json
import os

STORE = "/var/lib/ocserv-api/traffic.json"
KEEP_IDS = 2000


def main():
    username = os.environ.get("USERNAME", "")
    if username in ("", "(none)"):
        return
    received = int(os.environ.get("STATS_BYTES_IN", "0") or 0)
    sent = int(os.environ.get("STATS_BYTES_OUT", "0") or 0)
    session = os.environ.get("ID", "")

    os.makedirs(os.path.dirname(STORE), mode=0o700, exist_ok=True)
    with open(STORE + ".lock", "a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        try:
            with open(STORE) as handle:
                state = json.load(handle)
        except (OSError, ValueError):
            state = {}
        totals = state.get("totals") or {}
        closed = state.get("closed_ids") or []

        # The same session reported twice must not count twice.
        if session and session in closed:
            return

        rx, tx = totals.get(username, [0, 0])
        totals[username] = [rx + received, tx + sent]
        if session:
            closed = (closed + [session])[-KEEP_IDS:]

        tmp = STORE + ".tmp"
        with open(tmp, "w") as handle:
            json.dump({"totals": totals, "closed_ids": closed}, handle)
        os.replace(tmp, STORE)


if __name__ == "__main__":
    try:
        main()
    except Exception:
        pass
