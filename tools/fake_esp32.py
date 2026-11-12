#!/usr/bin/env python3
"""Generate illustrative ASCII uplink lines for local bridge testing only."""

from __future__ import annotations

import argparse
import math
import re
import sys
import time


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Generate fake ESP32 U lines (no radio) ")
    parser.add_argument("--count", type=int, default=12)
    parser.add_argument("--interval-s", type=float, default=5)
    parser.add_argument("--node-id", default="H1")
    parser.add_argument("--start-seq", type=int, default=1)
    parser.add_argument("--lat", "--latitude", dest="latitude", type=float, default=14.825014)
    parser.add_argument("--lon", "--longitude", dest="longitude", type=float, default=121.1801059)
    parser.add_argument("--battery", type=int, default=84)
    parser.add_argument("--flags", default="00001", help="five bits: link-lost, low-battery, emergency, turn-back-received, test-mode")
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    if args.count < 1 or args.interval_s < 0:
        print("count must be positive and interval-s cannot be negative", file=sys.stderr)
        return 2
    if not re.fullmatch(r"[A-Za-z0-9_-]{1,16}", args.node_id):
        print("node-id must be 1 to 16 letters, digits, underscores or hyphens", file=sys.stderr)
        return 2
    if not 0 <= args.start_seq <= 65535 or args.start_seq + args.count - 1 > 65535:
        print("sequence range must fit 0..65535", file=sys.stderr)
        return 2
    if not math.isfinite(args.latitude) or not -90 <= args.latitude <= 90:
        print("latitude must be from -90 to 90", file=sys.stderr)
        return 2
    if not math.isfinite(args.longitude) or not -180 <= args.longitude <= 180:
        print("longitude must be from -180 to 180", file=sys.stderr)
        return 2
    if not 0 <= args.battery <= 100 or not re.fullmatch(r"[01]{5}", args.flags):
        print("battery must be 0..100 and flags must be five 0/1 bits", file=sys.stderr)
        return 2

    # Sequence numbers must be unique within a test session. Choose a new
    # --start-seq for every replay in that session to avoid duplicate detection.
    print("# Fake ESP32 lines only; no radio delivery is implied.")
    print("# Use a unique --start-seq for each run in the same test session.")
    for offset in range(args.count):
        sequence = args.start_seq + offset
        print(
            f"U,{args.node_id},{sequence},{args.latitude:.7f},{args.longitude:.7f},"
            f"7.5,7,{args.battery},{args.flags},DIRECT",
            flush=True,
        )
        if offset + 1 < args.count and args.interval_s:
            time.sleep(args.interval_s)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
