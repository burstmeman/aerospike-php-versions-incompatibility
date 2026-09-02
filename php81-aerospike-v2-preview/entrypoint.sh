#!/bin/sh
# Starts aerospike-php-daemon in the background, waits for it to report that
# it is actually serving (not just running - the shared-memory service takes
# a moment to come up), then runs whatever this container was asked to run
# (write.php, read.php, ...).
set -e

instance=${AEROSPIKE_INSTANCE:-default}
log=/tmp/aerospike-php-daemon.log

/usr/local/bin/aerospike-php-daemon --config /etc/aerospike-daemon.toml > "$log" 2>&1 &

for _ in $(seq 1 100); do
    grep -q "serving '$instance'" "$log" 2>/dev/null && break
    sleep 0.1
done
grep -q "serving '$instance'" "$log" 2>/dev/null || {
    echo "entrypoint: aerospike-php-daemon never started serving '$instance'" >&2
    cat "$log" >&2
    exit 1
}

exec "$@"
