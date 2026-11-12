#!/usr/bin/env python3
"""DEMO-oriented line bridge for the Trailwatch logical JSON API.

The serial line is a local integration contract, not a LoRa packet or firmware
framing specification. The firmware owner must confirm the serial settings.
"""

from __future__ import annotations

import argparse
import datetime as dt
import http.client
import json
import math
import os
import re
import sys
import time
from collections.abc import Callable, Iterable, Mapping
from typing import TextIO
from urllib.parse import urlsplit


COMMAND_TYPES = frozenset({"ACK", "OFF_TRAIL_ALERT", "SOS_ACK", "STORM_TURN_BACK"})
FLAG_NAMES = ("link_lost", "low_battery", "emergency", "turn_back_received", "test_mode")
OPTIONAL_LINE_FIELDS = (
    ("rssi_dbm", "int", -200, 100),
    ("snr_db", "float", -50, 50),
    ("checkpoint_id", "text", None, None),
    ("checkpoint_rssi_dbm", "int", -200, 100),
    ("relay_hop_delay_ms", "int", 0, 300000),
)


def _integer(value: str, label: str, low: int, high: int) -> int:
    if not re.fullmatch(r"-?\d+", value):
        raise ValueError(f"{label} must be an integer")
    number = int(value, 10)
    if not low <= number <= high:
        raise ValueError(f"{label} must be between {low} and {high}")
    return number


def _number(value: str, label: str, low: float, high: float) -> float:
    try:
        number = float(value)
    except ValueError as exc:
        raise ValueError(f"{label} must be numeric") from exc
    if not math.isfinite(number) or not low <= number <= high:
        raise ValueError(f"{label} must be a finite number from {low} to {high}")
    return number


def parse_uplink_line(line: str) -> dict[str, object] | None:
    """Parse one U line; return None for comments and blank lines."""
    if not isinstance(line, str):
        raise ValueError("line must be text")
    text = line.rstrip("\r\n")
    if not text.strip() or text.lstrip().startswith("#"):
        return None

    fields = text.split(",")
    if len(fields) < 10 or len(fields) > 15:
        raise ValueError("U line must contain 10 to 15 comma-separated fields")
    if fields[0] != "U":
        raise ValueError("line must start with U")

    node_id = fields[1].strip()
    if not node_id or len(node_id) > 16:
        raise ValueError("node_id must contain 1 to 16 characters")
    sequence = _integer(fields[2], "sequence", 0, 65535)
    latitude = _number(fields[3], "latitude", -90, 90)
    longitude = _number(fields[4], "longitude", -180, 180)
    accuracy = _number(fields[5], "accuracy_m", 0, float("inf"))
    satellites = _integer(fields[6], "satellites", 0, 255)
    battery = _integer(fields[7], "battery", 0, 100)

    flags_text = fields[8]
    if not re.fullmatch(r"[01]{5}", flags_text):
        raise ValueError("flags must be exactly five 0/1 characters")
    flags = {name: bit == "1" for name, bit in zip(FLAG_NAMES, flags_text, strict=True)}

    path_type = fields[9]
    if path_type not in {"DIRECT", "CHECKPOINT_RELAY"}:
        raise ValueError("path must be DIRECT or CHECKPOINT_RELAY")

    parsed: dict[str, object] = {
        "node_id": node_id,
        "sequence_number": sequence,
        "latitude": latitude,
        "longitude": longitude,
        "gps_accuracy_m": accuracy,
        "satellite_count": satellites,
        "battery_level": battery,
        "flags": flags,
        "path_type": path_type,
    }
    for offset, (name, kind, low, high) in enumerate(OPTIONAL_LINE_FIELDS, start=10):
        value = fields[offset] if offset < len(fields) else ""
        if value == "":
            continue
        if kind == "int":
            parsed[name] = _integer(value, name, int(low), int(high))
        elif kind == "float":
            parsed[name] = _number(value, name, float(low), float(high))
        else:
            parsed[name] = value.strip()
            if not parsed[name]:
                raise ValueError("checkpoint_id cannot be blank when supplied")

    if path_type == "CHECKPOINT_RELAY":
        if not parsed.get("checkpoint_id") or "checkpoint_rssi_dbm" not in parsed:
            raise ValueError("CHECKPOINT_RELAY requires checkpoint_id and checkpoint_rssi_dbm")
    elif "checkpoint_id" in parsed or "checkpoint_rssi_dbm" in parsed:
        raise ValueError("DIRECT lines cannot include checkpoint relay fields")

    return parsed


def build_ingest_payload(
    uplink: Mapping[str, object], base_id: str = "BASE", now: dt.datetime | None = None
) -> dict[str, object]:
    """Build exactly the bridge ingest envelope accepted by ApiService."""
    stamp = now or dt.datetime.now(dt.timezone.utc)
    if stamp.tzinfo is None:
        raise ValueError("now must be timezone-aware")
    received_at = stamp.astimezone(dt.timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    flags = uplink["flags"]
    if not isinstance(flags, Mapping):
        raise ValueError("flags must be a mapping")

    payload: dict[str, object] = {
        "uplink": {
            "node_id": uplink["node_id"],
            "sequence_number": uplink["sequence_number"],
            "latitude": uplink["latitude"],
            "longitude": uplink["longitude"],
            "gps_accuracy_m": uplink["gps_accuracy_m"],
            "satellite_count": uplink["satellite_count"],
            "battery_level": uplink["battery_level"],
            "flags": {name: bool(flags[name]) for name in FLAG_NAMES},
        },
        "reception": {
            "base_id": base_id,
            "path_type": uplink["path_type"],
            "received_at": received_at,
        },
    }
    reception = payload["reception"]
    assert isinstance(reception, dict)
    for key in ("rssi_dbm", "snr_db", "checkpoint_id", "checkpoint_rssi_dbm", "relay_hop_delay_ms"):
        if key in uplink:
            reception[key] = uplink[key]
    return payload


def format_downlink_line(command: Mapping[str, object]) -> str:
    """Format the immediate ingest command as one newline-terminated D line."""
    node_id = str(command.get("hiker_node_id", ""))
    command_type = str(command.get("command_type", ""))
    sequence = command.get("sequence_number")
    if not node_id or len(node_id) > 16 or any(ch in node_id for ch in ",\r\n"):
        raise ValueError("command hiker_node_id is invalid for a D line")
    if command_type not in COMMAND_TYPES:
        raise ValueError("command_type is not a supported dashboard command")
    if isinstance(sequence, bool) or not isinstance(sequence, int) or not 0 <= sequence <= 65535:
        raise ValueError("command sequence_number must be an integer from 0 to 65535")
    return f"D,{node_id},{sequence},{command_type}\n"


def validate_base_url(base_url: str) -> tuple[str, int, str]:
    """Return (host, port, API path prefix) after enforcing loopback-only HTTP."""
    try:
        parsed = urlsplit(base_url)
        port = parsed.port or 80
    except ValueError as exc:
        raise ValueError("base-url must be a valid loopback HTTP URL") from exc
    if (
        parsed.scheme.lower() != "http"
        or (parsed.hostname or "").lower() not in {"127.0.0.1", "localhost"}
        or parsed.username is not None
        or parsed.password is not None
        or parsed.query
        or parsed.fragment
    ):
        raise ValueError("base-url must use http://127.0.0.1 or http://localhost")
    return parsed.hostname or "127.0.0.1", port, parsed.path.rstrip("/")


def _api_request(
    connection: http.client.HTTPConnection,
    path: str,
    token: str,
    method: str,
    payload: Mapping[str, object] | None = None,
) -> tuple[int, dict[str, object] | None, str]:
    body = json.dumps(payload, separators=(",", ":")).encode("utf-8") if payload is not None else None
    headers = {"X-Bridge-Token": token, "Connection": "keep-alive"}
    if body is not None:
        headers["Content-Type"] = "application/json"
    connection.request(method, path, body=body, headers=headers)
    response = connection.getresponse()
    raw = response.read().decode("utf-8", errors="replace")
    try:
        data = json.loads(raw) if raw else None
    except json.JSONDecodeError:
        data = None
    return response.status, data if isinstance(data, dict) else None, raw


def _safe_message(value: object, token: str) -> str:
    message = str(value).replace("\r", " ").replace("\n", " ")
    return message.replace(token, "[redacted]") if token else message


def _http_error_text(data: Mapping[str, object] | None, raw: str, token: str) -> str:
    if data and data.get("error") is not None:
        return _safe_message(data["error"], token)
    return _safe_message(raw[:300] or "empty response", token)


def _process_line(
    raw_line: str | bytes,
    connection: http.client.HTTPConnection,
    api_prefix: str,
    base_id: str,
    token: str,
    write_downlink: Callable[[str], None],
    log: TextIO,
) -> None:
    line_started = time.perf_counter()
    if isinstance(raw_line, bytes):
        try:
            line = raw_line.decode("ascii")
        except UnicodeDecodeError:
            print("rejected line: input is not ASCII", file=log, flush=True)
            return
    else:
        line = raw_line

    try:
        uplink = parse_uplink_line(line)
    except ValueError as exc:
        print(f"rejected line: {_safe_message(exc, token)}", file=log, flush=True)
        return
    if uplink is None:
        return

    node_id = str(uplink["node_id"])
    sequence = int(uplink["sequence_number"])
    ingest_status: int | str = "connection-error"
    d_line_ms: str = "n/a (no command)"
    extra = ""
    try:
        payload = build_ingest_payload(uplink, base_id)
        ingest_status, data, raw = _api_request(connection, f"{api_prefix}/bridge/ingest", token, "POST", payload)
    except (OSError, http.client.HTTPException, TimeoutError) as exc:
        connection.close()
        extra = f" error={_safe_message(exc, token)}"
        print(f"node={node_id} seq={sequence} HTTP={ingest_status} D-line-ms={d_line_ms}{extra}", file=log, flush=True)
        return

    if not 200 <= ingest_status < 300:
        extra = f" error={_http_error_text(data, raw, token)}"
    elif data is None:
        extra = " error=invalid JSON response"
    else:
        command = data.get("command")
        if not data.get("duplicate") and isinstance(command, dict):
            try:
                d_line = format_downlink_line(command)
                write_downlink(d_line)
                d_line_ms = f"{(time.perf_counter() - line_started) * 1000:.1f}"
            except (ValueError, OSError, UnicodeError) as exc:
                extra = f" downlink-error={_safe_message(exc, token)}"
                d_line = ""
            if d_line:
                command_id = command.get("id")
                if isinstance(command_id, bool) or not isinstance(command_id, int) or command_id <= 0:
                    extra = " status-error=ingest command has invalid id"
                else:
                    try:
                        status_code, status_data, status_raw = _api_request(
                            connection,
                            f"{api_prefix}/commands/{command_id}/status",
                            token,
                            "POST",
                            {"status": "SENT_TO_BASE"},
                        )
                        if not 200 <= status_code < 300 or not status_data or not status_data.get("ok"):
                            extra += f" status-http={status_code} status-error={_http_error_text(status_data, status_raw, token)}"
                    except (OSError, http.client.HTTPException, TimeoutError) as exc:
                        connection.close()
                        extra += f" status-error={_safe_message(exc, token)}"
        elif data.get("duplicate"):
            d_line_ms = "n/a (duplicate; no command)"

    print(f"node={node_id} seq={sequence} HTTP={ingest_status} D-line-ms={d_line_ms}{extra}", file=log, flush=True)


def _serial_lines(port: str, baud: int) -> tuple[Iterable[bytes], object]:
    # The firmware owner must confirm this default baud rate before hardware use.
    try:
        import serial  # type: ignore[import-not-found]  # loaded only for --port
    except ImportError as exc:
        raise RuntimeError("--port requires pyserial; install it in the project environment first") from exc
    connection = serial.Serial(port=port, baudrate=baud, timeout=1)
    return iter(connection.readline, b""), connection


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Local-only Trailwatch ASCII line bridge")
    parser.add_argument("--base-url", required=True, help="local dashboard API, e.g. http://127.0.0.1:8002/api")
    parser.add_argument("--base-id", default="BASE")
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--port", help="serial port such as COM4 (requires pyserial)")
    source.add_argument("--stdin", action="store_true", help="read U lines from stdin; write D lines to stdout")
    # 115200 is a placeholder default only; the firmware owner must confirm it.
    parser.add_argument("--baud", type=int, default=115200, help="serial baud rate (default 115200; firmware owner must confirm)")
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    try:
        host, port, api_prefix = validate_base_url(args.base_url)
    except ValueError as exc:
        print(f"serial bridge refused: {exc}", file=sys.stderr)
        return 2
    if args.baud <= 0:
        print("serial bridge refused: --baud must be positive", file=sys.stderr)
        return 2
    token = os.environ.get("TRAILWATCH_BRIDGE_TOKEN", "")
    if not token:
        print("serial bridge refused: TRAILWATCH_BRIDGE_TOKEN is not set", file=sys.stderr)
        return 2
    if not args.base_id or any(ch in args.base_id for ch in ",\r\n"):
        print("serial bridge refused: --base-id must be a non-empty single field", file=sys.stderr)
        return 2

    serial_handle = None
    try:
        if args.stdin:
                        
            if hasattr(sys.stdin, "reconfigure"):
                sys.stdin.reconfigure(encoding="utf-8-sig")
            lines: Iterable[str | bytes] = sys.stdin
            write_downlink = lambda text: (sys.stdout.write(text), sys.stdout.flush())
        else:
            try:
                serial_lines, serial_handle = _serial_lines(args.port, args.baud)
            except (RuntimeError, OSError, ValueError) as exc:
                print(f"serial bridge refused: {_safe_message(exc, token)}", file=sys.stderr)
                return 2
            lines = serial_lines

            def write_downlink(text: str) -> None:
                serial_handle.write(text.encode("ascii"))
                serial_handle.flush()

        connection = http.client.HTTPConnection(host, port, timeout=1.5)
        try:
            print("serial bridge ready (local HTTP only)", file=sys.stderr, flush=True)
            for raw_line in lines:
                if isinstance(raw_line, bytes):
                    if not raw_line.endswith(b"\n") and raw_line.strip() and not raw_line.lstrip().startswith(b"#"):
                        print("rejected line: missing newline terminator", file=sys.stderr, flush=True)
                        continue
                elif not raw_line.endswith("\n") and raw_line.strip() and not raw_line.lstrip().startswith("#"):
                    print("rejected line: missing newline terminator", file=sys.stderr, flush=True)
                    continue
                _process_line(raw_line, connection, api_prefix, args.base_id, token, write_downlink, sys.stderr)
        except KeyboardInterrupt:
            print("serial bridge stopped", file=sys.stderr, flush=True)
        finally:
            connection.close()
    finally:
        if serial_handle is not None:
            serial_handle.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
