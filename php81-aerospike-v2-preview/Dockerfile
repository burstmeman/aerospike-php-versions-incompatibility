# The v2-preview client: a thin Rust extension (built with ext-php-rs) that
# talks, over shared memory, to `aerospike-php-daemon` - a long-lived Rust
# process that owns the real connection to the server. The pure-Rust
# successor to v1.4.0's Go-based Connection Manager: no gRPC, no separate
# language runtime.
#
# Both halves come from https://github.com/aerospike/php-client (branch
# `v2-preview`).
#
# > [!WARNING]
# > v2-preview is, per its own README, "a preview codebase, released for
# > customer feedback" - not a released client. That is exactly why it is
# > worth comparing here: it is what a legacy-client bin looks like to the
# > *next* generation of the client, while it is still possible to change
# > that answer.

ARG PHP_IMAGE=php:8.1-cli-bullseye

# ---------------------------------------------------------------------------
# Build stage
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS builder

RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential pkg-config \
        clang libclang-dev llvm-dev \
        git curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

ARG RUST_VERSION=1.87.0
RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs \
        | sh -s -- -y --default-toolchain "${RUST_VERSION}" --no-modify-path
ENV PATH="/root/.cargo/bin:${PATH}"

ARG AEROSPIKE_PHP_CLIENT_REF=v2-preview
RUN git clone --branch "${AEROSPIKE_PHP_CLIENT_REF}" --depth 1 \
        https://github.com/aerospike/php-client.git /usr/src/aerospike-php-client
WORKDIR /usr/src/aerospike-php-client

# The daemon: owns the real client and its connections, serves PHP workers
# over shared memory. Built from the workspace root.
RUN cargo build --release -p aerospike-php-daemon && \
    cp target/release/aerospike-php-daemon /usr/local/bin/aerospike-php-daemon

# The extension: a thin proxy that marshals PHP values and forwards them to
# the daemon above. Deliberately excluded from the workspace, since it links
# the Zend API - it has its own target dir and lockfile under ext/.
RUN cd ext && \
    LIBCLANG_PATH="$(llvm-config --libdir)" cargo build --release && \
    cp target/release/libaerospike_php.so /usr/local/lib/aerospike_php.so

# ---------------------------------------------------------------------------
# Runtime stage
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE}

COPY --from=builder /usr/local/lib/aerospike_php.so /usr/local/lib/aerospike_php.so
RUN echo "extension=/usr/local/lib/aerospike_php.so" > "$PHP_INI_DIR/conf.d/aerospike.ini"

COPY --from=builder /usr/local/bin/aerospike-php-daemon /usr/local/bin/aerospike-php-daemon
COPY php81-aerospike-v2-preview/aerospike-daemon.toml /etc/aerospike-daemon.toml
COPY php81-aerospike-v2-preview/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /app
COPY shared/ shared/
COPY php81-aerospike-v2-preview/*.php ./

ENTRYPOINT ["entrypoint.sh"]
CMD ["php"]
