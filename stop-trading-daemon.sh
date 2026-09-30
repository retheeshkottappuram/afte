#!/usr/bin/env bash
# ==============================================================================
# AFTE 24/7 Autonomous Trading Daemon - Graceful Stopper
# ==============================================================================

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

PID_FILE="$PROJECT_DIR/storage/framework/trading-daemon.pid"
STOP_FILE="$PROJECT_DIR/storage/framework/stop-trading-daemon"

echo "🛑 Stopping AFTE Trading Daemon..."
date +%s > "$STOP_FILE"

# Resolve PHP CLI Binary
PHP_BIN="php"
if [ -n "$PHP_BINARY_PATH" ] && [ -x "$PHP_BINARY_PATH" ]; then
    PHP_BIN="$PHP_BINARY_PATH"
elif command -v php8.4 >/dev/null 2>&1; then
    PHP_BIN="$(command -v php8.4)"
elif command -v php >/dev/null 2>&1; then
    PHP_BIN="$(command -v php)"
fi

# Run artisan stop if available
"$PHP_BIN" artisan tinker --execute="App\Models\TradingAccount::where('mode', 'live')->update(['is_running' => false]); Illuminate\Support\Facades\Cache::put('trading:daemon:stop', true, 120);" >/dev/null 2>&1 || true

if [ -f "$PID_FILE" ]; then
    PID="$(cat "$PID_FILE" 2>/dev/null || true)"
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
        echo "• Sending SIGTERM to PID $PID..."
        kill -15 "$PID" 2>/dev/null || true
        for i in {1..5}; do
            if ! kill -0 "$PID" 2>/dev/null; then
                break
            fi
            sleep 1
        done
        if kill -0 "$PID" 2>/dev/null; then
            echo "• Process still running; sending SIGKILL..."
            kill -9 "$PID" 2>/dev/null || true
        fi
        rm -f "$PID_FILE"
        echo "✅ Trading Daemon stopped successfully."
        exit 0
    fi
    rm -f "$PID_FILE"
fi

# Fallback: check pkill for trade:daemon
pkill -f "artisan trade:daemon" 2>/dev/null || true
echo "✅ Stop signal emitted. Trading engine paused."
