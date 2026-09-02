#!/usr/bin/env bash
#
# Builds all three client images, writes the canonical dataset with each one,
# reads it back with each one, and saves the raw output under results/.
#
# Usage: ./run-matrix.sh
#
# Requires only Docker with Compose v2 (`docker compose`, no hyphen).

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

mkdir -p results

writers=(php74 php81-v1-4-0 php81-v2-preview)

echo "==> building images and starting Aerospike"
docker compose up -d --build --wait aerospike

for service in "${writers[@]}"; do
    echo "==> writing the dataset with $service"
    docker compose run --rm "$service" php write.php | tee "results/write-$service.txt"
done

for service in "${writers[@]}"; do
    echo "==> reading every writer's data back with $service"
    docker compose run --rm "$service" php read.php | tee "results/read-$service.txt"
done

echo "==> done - raw output saved under results/"
