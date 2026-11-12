#!/usr/bin/env python3
"""Focused contract and local-stub tests for the stdlib serial bridge."""

from __future__ import annotations

import json
import os
import socket
import subprocess
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from tools.serial_bridge import (  # noqa: E402
    build_ingest_payload,
    format_downlink_line,
    parse_uplink_line,
    validate_base_url,
)

PASS = 0
FAIL = 0


def check(condition: bool, description: str) -> None:
    global PASS, FAIL
    if condition:
        PASS += 1
        print(f"PASS {description}")
    else:
        FAIL += 1
        print(f"FAIL {description}", file=sys.stderr)


def rejects(callable_, description: str) -> None:
    try:
        callable_()
    except (ValueError, TypeError):
        check(True, description)
    else:
        check(False, description)


def test_parser_and_contract() -> None:
    direct = parse_uplink_line("U,H1,12,14.8,121.0,7.5,7,84,00001,DIRECT\n")
    check(direct is not None and direct["sequence_number"] == 12, "parses required direct line")
    check(direct is not None and direct["flags"] == {
        "link_lost": False, "low_battery": False, "emergency": False,
        "turn_back_received": False, "test_mode": True,
    }, "maps the five flag bits in the required order")

    optional = parse_uplink_line("U,H1,13,14.8,121.0,7.5,7,84,00001,DIRECT,-90,8.5,,,\n")
    check(optional is not None and optional.get("rssi_dbm") == -90 and optional.get("snr_db") == 8.5,
          "parses optional direct RSSI and SNR while omitting blanks")
    relay = parse_uplink_line("U,H2,14,14.8,121.0,7.5,7,84,00001,CHECKPOINT_RELAY,-90,8.5,CP1,-80,125\n")
    check(relay is not None and relay.get("checkpoint_id") == "CP1"
          and relay.get("checkpoint_rssi_dbm") == -80 and relay.get("relay_hop_delay_ms") == 125,
          "parses relay reception metadata")
    check(parse_uplink_line("  # comment\n") is None and parse_uplink_line(" \r\n") is None,
          "ignores comments and blank lines")

    rejects(lambda: parse_uplink_line("U,H1,1,14,121,7,7,84,00001\n"), "rejects incorrect field count")
    rejects(lambda: parse_uplink_line("U,H1,1,14,121,7,7,84,00x01,DIRECT\n"), "rejects malformed flags")
    rejects(lambda: parse_uplink_line("U,H1,1,14,121,7,7,84,00001,RELAY\n"), "rejects unknown path")
    rejects(lambda: parse_uplink_line("U,H1,nope,14,121,7,7,84,00001,DIRECT\n"), "rejects nonnumeric sequence")
    rejects(lambda: parse_uplink_line("U,H1,1,14,121,7,7,84,00001,CHECKPOINT_RELAY\n"),
            "rejects relay without checkpoint metadata")

    assert direct is not None
    payload = build_ingest_payload(direct, "BASE7")
    check(set(payload) == {"uplink", "reception"}, "ingest body contains only uplink and reception")
    check(set(payload["uplink"]) == {
        "node_id", "sequence_number", "latitude", "longitude", "gps_accuracy_m",
        "satellite_count", "battery_level", "flags",
    }, "uplink body uses exact API field names")
    check(set(payload["reception"]) == {"base_id", "path_type", "received_at"}
          and payload["reception"]["base_id"] == "BASE7",
          "reception body contains required API keys and UTC timestamp")
    check(format_downlink_line({"hiker_node_id": "H1", "sequence_number": 12,
                                "command_type": "ACK"}) == "D,H1,12,ACK\n",
          "formats dashboard command as a D line")
    rejects(lambda: format_downlink_line({"hiker_node_id": "H1", "sequence_number": 1,
                                          "command_type": "UNKNOWN"}), "rejects unknown downlink command")
    rejects(lambda: validate_base_url("http://192.168.1.4:8000/api"), "refuses non-loopback server URL")
    rejects(lambda: validate_base_url("https://127.0.0.1/api"), "refuses HTTPS URL")


def test_fake_generator() -> None:
    result = subprocess.run(
        [sys.executable, str(ROOT / "tools" / "fake_esp32.py"), "--count", "2", "--interval-s", "0"],
        text=True, capture_output=True, check=False,
    )
    lines = [line for line in result.stdout.splitlines() if line.startswith("U,")]
    check(result.returncode == 0 and len(lines) == 2 and lines[0].split(",")[2] == "1"
          and lines[1].split(",")[2] == "2", "fake generator outputs requested increasing sequences")
    check("no radio delivery" in result.stdout.lower(), "fake generator labels output as illustrative")


def test_local_stub_end_to_end() -> None:
    token = "unit-test-secret-do-not-print"
    requests: list[tuple[str, dict[str, object] | None, str | None]] = []
    lock = threading.Lock()

    class Handler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, _format: str, *_args: object) -> None:
            return

        def do_POST(self) -> None:  # noqa: N802
            length = int(self.headers.get("Content-Length", "0"))
            raw_body = self.rfile.read(length).decode("utf-8") if length else ""
            try:
                body = json.loads(raw_body) if raw_body else None
            except json.JSONDecodeError:
                body = None
            with lock:
                requests.append((self.path, body, self.headers.get("X-Bridge-Token")))
                index = len(requests)
            if self.path.endswith("/bridge/ingest"):
                sequence = body.get("uplink", {}).get("sequence_number") if isinstance(body, dict) else None
                error_statuses = {3: 400, 4: 401, 5: 409, 6: 422, 7: 500}
                if sequence == 2:
                    status, response = 200, {"duplicate": True, "command": None}
                elif sequence in error_statuses:
                    status = error_statuses[sequence]
                    response = {"error": f"stub HTTP {status} response"}
                else:
                    command_sequence = sequence if isinstance(sequence, int) else 1
                    status, response = 201, {
                        "duplicate": False,
                        "command": {
                            "id": 40 + command_sequence, "command_type": "ACK", "type": "ACK",
                            "route_type": "DIRECT", "route_via_node_id": None,
                            "hiker_node_id": body["uplink"]["node_id"],
                            "sequence_number": command_sequence, "base_id": "BASE",
                        },
                    }
            elif self.path.startswith("/api/commands/") and self.path.endswith("/status"):
                status, response = 200, {"ok": True, "status": "SENT_TO_BASE"}
            else:
                status, response = 404, {"error": "not found"}
            encoded = json.dumps(response).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(encoded)))
            self.end_headers()
            self.wfile.write(encoded)

    server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        port = server.server_address[1]
        env = os.environ.copy()
        env["TRAILWATCH_BRIDGE_TOKEN"] = token
        uplinks = "\n".join([
            "# ignored comment",
            "U,H1,1,14.8,121.0,7.5,7,84,00001,DIRECT",
            "U,H1,2,14.8,121.0,7.5,7,84,00001,DIRECT",
            "U,H1,3,14.8,121.0,7.5,7,84,00001,DIRECT",
            "U,H1,4,14.8,121.0,7.5,7,84,00001,DIRECT",
            "U,H1,5,14.8,121.0,7.5,7,84,00001,DIRECT",
            "U,H1,6,14.8,121.0,7.5,7,84,00001,DIRECT",
            "U,H1,7,14.8,121.0,7.5,7,84,00001,DIRECT",
            "U,H2,8,14.8,121.0,7.5,7,84,00001,CHECKPOINT_RELAY,-90,8.5,CP1,-80,125",
            "",
        ])
        result = subprocess.run(
            [sys.executable, str(ROOT / "tools" / "serial_bridge.py"), "--base-url",
             f"http://127.0.0.1:{port}/api", "--stdin"],
            input=uplinks, text=True, capture_output=True, env=env, timeout=10, check=False,
        )
        downlinks = [line for line in result.stdout.splitlines() if line.startswith("D,")]
        check(result.returncode == 0, "bridge completes against local-only stub HTTP server")
        check(downlinks == ["D,H1,1,ACK", "D,H2,8,ACK"],
              f"bridge continues after errors, writes D for commands and skips duplicate ({downlinks!r}; {result.stderr.strip()})")
        check(token not in result.stdout and token not in result.stderr, "bridge never prints bridge token")
        with lock:
            recorded = list(requests)
        ingest = [(path, body, sent_token) for path, body, sent_token in recorded
                  if path == "/api/bridge/ingest"]
        statuses = [(path, body, sent_token) for path, body, sent_token in recorded
                    if path.startswith("/api/commands/") and path.endswith("/status")]
        check(len(ingest) == 8 and len(statuses) == 2,
              f"bridge continues after HTTP 400/401/409/422/500; status only follows commands ({len(ingest)} ingest, {len(statuses)} statuses, {[r[0] for r in recorded]!r})")
        check(all(sent_token == token for _, _, sent_token in recorded), "bridge sends X-Bridge-Token header on API calls")
        check(len(ingest) >= 1 and isinstance(ingest[0][1], dict)
              and set(ingest[0][1]) == {"uplink", "reception"}, "stub receives exact bridge JSON envelope")
        check(len(statuses) == 2 and all(body == {"status": "SENT_TO_BASE"} for _, body, _ in statuses),
              f"bridge reports SENT_TO_BASE after writing D line ({[r[1] for r in statuses]!r})")
        check(any(isinstance(body, dict) and body.get("reception", {}).get("path_type") == "CHECKPOINT_RELAY"
                  and body["reception"].get("checkpoint_id") == "CP1" for _, body, _ in ingest),
              "bridge sends checkpoint reception metadata on relay line")
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=2)


def main() -> int:
    test_parser_and_contract()
    test_fake_generator()
    test_local_stub_end_to_end()
    print(f"RESULT: {PASS} PASS, {FAIL} FAIL")
    return 0 if FAIL == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
