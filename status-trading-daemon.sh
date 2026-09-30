#!/usr/bin/env bash
# ==============================================================================
# AFTE 24/7 Autonomous Trading Daemon - Status Inspector
# ==============================================================================

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

PID_FILE="$PROJECT_DIR/storage/framework/trading-daemon.pid"
LOG_FILE="$PROJECT_DIR/storage/logs/trading_daemon.log"

echo "=================================================================="
echo "🔍 AFTE 24/7 Trading Daemon Status Inspector"
echo "=================================================================="

# Check PID file
IS_RUNNING=0
if [ -f "$PID_FILE" ]; then
    PID="$(cat "$PID_FILE" 2>/dev/null || true)"
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
        IS_RUNNING=1
        echo "• Status:     🟢 RUNNING"
        echo "• Process ID: $PID"
        ps -p "$PID" -o %cpu,%mem,etime,cmd 2>/dev/null || true
    fi
fi

if [ "$IS_RUNNING" -eq 0 ]; then
    # Check if running via supervisor, systemd, or alternate process
    ALT_PID="$(pgrep -f "artisan trade:daemon" | head -n 1 || true)"
    if [ -n "$ALT_PID" ] && kill -0 "$ALT_PID" 2>/dev/null; then
        echo "• Status:     🟢 RUNNING (Discovered PID: $ALT_PID)"
        ps -p "$ALT_PID" -o %cpu,%mem,etime,cmd 2>/dev/null || true
        echo "$ALT_PID" > "$PID_FILE"
        IS_RUNNING=1
    else
        echo "• Status:     🔴 STOPPED / INACTIVE"
        echo "• Tip:        Run './start-trading-daemon.sh live' or start your systemd service."
    fi
fi

echo "------------------------------------------------------------------"
echo "📋 Recent Daemon Log Tail (Last 20 lines):"
if [ -f "$LOG_FILE" ]; then
    tail -n 20 "$LOG_FILE"
else
    echo "No daemon log found at $LOG_FILE."
fi
echo "=================================================================="
