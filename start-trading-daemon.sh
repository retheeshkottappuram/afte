#!/usr/bin/env bash
# ==============================================================================
# AFTE 24/7 Autonomous Trading Daemon - Linux VPS Background Launcher
# ==============================================================================

set -e
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

MODE="${1:-live}"
PID_FILE="$PROJECT_DIR/storage/framework/trading-daemon.pid"
STOP_FILE="$PROJECT_DIR/storage/framework/stop-trading-daemon"
LOG_FILE="$PROJECT_DIR/storage/logs/trading_daemon.log"

mkdir -p "$PROJECT_DIR/storage/framework" "$PROJECT_DIR/storage/logs"

# 1. Resolve PHP CLI Binary
PHP_BIN="php"
if [ -n "$PHP_BINARY_PATH" ] && [ -x "$PHP_BINARY_PATH" ]; then
    PHP_BIN="$PHP_BINARY_PATH"
elif command -v php8.4 >/dev/null 2>&1; then
    PHP_BIN="$(command -v php8.4)"
elif command -v php >/dev/null 2>&1; then
    PHP_BIN="$(command -v php)"
elif [ -x "/usr/bin/php8.4" ]; then
    PHP_BIN="/usr/bin/php8.4"
elif [ -x "/usr/bin/php" ]; then
    PHP_BIN="/usr/bin/php"
fi

echo "=================================================================="
echo "🚀 AFTE 24/7 Autonomous Trading Daemon Launcher"
echo "=================================================================="
echo "• Project Directory: $PROJECT_DIR"
echo "• Target Mode:       $MODE"
echo "• PHP CLI Binary:    $PHP_BIN"
echo "• Log Output:        $LOG_FILE"
echo "------------------------------------------------------------------"

# 2. Check if already running
if [ -f "$PID_FILE" ]; then
    EXISTING_PID="$(cat "$PID_FILE" 2>/dev/null || true)"
    if [ -n "$EXISTING_PID" ] && kill -0 "$EXISTING_PID" 2>/dev/null; then
        echo "⚠️  Trading Daemon is ALREADY RUNNING (PID: $EXISTING_PID)."
        echo "   Use './stop-trading-daemon.sh' to stop it, or './status-trading-daemon.sh' to inspect."
        exit 0
    else
        rm -f "$PID_FILE"
    fi
fi

# 3. Clear any stop flags
rm -f "$STOP_FILE"

# 4. Launch with nohup and detached standard streams
nohup "$PHP_BIN" artisan trade:daemon --mode="$MODE" --start </dev/null >> "$LOG_FILE" 2>&1 &
NEW_PID=$!
echo "$NEW_PID" > "$PID_FILE"

# 5. Spinup verification
sleep 1.5
if kill -0 "$NEW_PID" 2>/dev/null; then
    echo "✅ 24/7 Trading Daemon successfully spawned in background!"
    echo "• Process ID (PID): $NEW_PID"
    echo "• Running in 24/7 autonomous mode with AI Sentinel active."
    echo "------------------------------------------------------------------"
    echo "Recent log output:"
    tail -n 12 "$LOG_FILE" 2>/dev/null || true
    echo "=================================================================="
else
    echo "❌ Failed to start daemon. Inspect storage/logs/trading_daemon.log for errors."
    rm -f "$PID_FILE"
    tail -n 20 "$LOG_FILE" 2>/dev/null || true
    exit 1
fi
