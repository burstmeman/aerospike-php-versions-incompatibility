# The legacy client: a C extension for PHP 7, predating Aerospike's native
# boolean bin type and every "foreign blob" particle type this repo cares
# about. Talks to the server directly over the native wire protocol - no
# sidecar, no daemon.
#
# Base image is Debian 10 (buster) rather than a newer Debian: the Aerospike
# C client SDK this extension links against only ships prebuilt binaries for
# specific OS/arch combinations, and the version this extension's build
# script defaults to (4.6.9) has no build for anything newer. See
# https://artifacts.aerospike.com/aerospike-client-c/4.6.9/ for what actually
# exists - it is x86_64-only too, hence the platform pin in
# docker-compose.yml.
FROM php:7.4-cli-buster

# buster is EOL - its packages moved to the Debian archive.
RUN echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check-valid-until \
    && sed -i -e 's|deb.debian.org/debian|archive.debian.org/debian|g' \
              -e 's|security.debian.org/debian-security|archive.debian.org/debian-security|g' \
       /etc/apt/sources.list

RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential \
        autoconf \
        git \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# https://github.com/aerospike-community/aerospike-client-php - a community
# continuation of the archived official aerospike/aerospike-client-php.
# build.sh downloads the matching Aerospike C client SDK, then phpize's,
# configures and makes the extension.
RUN git clone --depth 1 https://github.com/aerospike-community/aerospike-client-php.git /usr/src/aerospike-client-php \
    && cd /usr/src/aerospike-client-php/src \
    && ./build.sh --default \
    && make install \
    && docker-php-ext-enable aerospike \
    && rm -rf /usr/src/aerospike-client-php

WORKDIR /app
COPY shared/ shared/
COPY php74-aerospike-legacy/*.php ./
