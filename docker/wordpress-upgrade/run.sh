#!/usr/bin/env bash
# Host-side entry point; web PHP never invokes this script or the Docker daemon.
set -euo pipefail
exec python3 "$(cd -- "$(dirname -- "$0")" && pwd)/runner.py" "$@"
