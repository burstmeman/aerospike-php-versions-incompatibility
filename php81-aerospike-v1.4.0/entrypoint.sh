#!/bin/sh
# Starts the Aerospike Connection Manager (ACM) in the background, waits for
# its unix socket to appear, then runs whatever this container was asked to
# run (write.php, read.php, ...).
set -e

/usr/local/bin/aerospike-connection-manager \
    -config-file /etc/aerospike-connection-manager/asld.toml &

socket=/tmp/asld_grpc.sock
for _ in $(seq 1 100); do
    [ -S "$socket" ] && break
    sleep 0.1
done
[ -S "$socket" ] || { echo "entrypoint: aerospike-connection-manager never created $socket" >&2; exit 1; }

exec "$@"
