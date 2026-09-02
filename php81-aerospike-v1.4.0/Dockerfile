# The v1.4.0 client: a thin Rust extension (built with ext-php-rs) that
# forwards every call, over gRPC, to the Aerospike Connection Manager (ACM) -
# a separate Go process, vendored in the same repo under
# aerospike-connection-manager/, that owns the real connection to the server.
#
# Both halves come from https://github.com/aerospike/php-client (branch
# `main`, the v1.4.0 line).

ARG PHP_IMAGE=php:8.1-cli-bullseye

# ---------------------------------------------------------------------------
# Build stage: Rust (the extension) + Go (the connection manager)
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS builder

RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential pkg-config \
        clang libclang-dev llvm-dev \
        protobuf-compiler \
        git curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

ARG TARGETARCH
ARG GO_VERSION=1.22.5
RUN curl -fsSL "https://go.dev/dl/go${GO_VERSION}.linux-${TARGETARCH}.tar.gz" | tar -C /usr/local -xz
ENV PATH="/usr/local/go/bin:${PATH}"

ARG RUST_VERSION=1.82.0
RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs \
        | sh -s -- -y --default-toolchain "${RUST_VERSION}" --no-modify-path
ENV PATH="/root/.cargo/bin:${PATH}"

ARG AEROSPIKE_PHP_CLIENT_REF=main
RUN git clone --branch "${AEROSPIKE_PHP_CLIENT_REF}" --depth 1 \
        https://github.com/aerospike/php-client.git /usr/src/aerospike-php-client
WORKDIR /usr/src/aerospike-php-client

# The extension. build.rs compiles aerospike-connection-manager/asld_kvs.proto
# via tonic-build, which is why protobuf-compiler is installed above.
RUN LIBCLANG_PATH="$(llvm-config --libdir)" cargo build --release && \
    cp target/release/libaerospike_php.so /usr/local/lib/aerospike_php.so

# The connection manager.
RUN cd aerospike-connection-manager && \
    go build -o /usr/local/bin/aerospike-connection-manager .

# ---------------------------------------------------------------------------
# Runtime stage
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE}

COPY --from=builder /usr/local/lib/aerospike_php.so /usr/local/lib/aerospike_php.so
RUN echo "extension=/usr/local/lib/aerospike_php.so" > "$PHP_INI_DIR/conf.d/aerospike.ini"

COPY --from=builder /usr/local/bin/aerospike-connection-manager /usr/local/bin/aerospike-connection-manager
COPY php81-aerospike-v1.4.0/asld.toml /etc/aerospike-connection-manager/asld.toml
COPY php81-aerospike-v1.4.0/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /app
COPY shared/ shared/
COPY php81-aerospike-v1.4.0/*.php ./

ENTRYPOINT ["entrypoint.sh"]
CMD ["php"]
