# Build Stage for PHP dependencies
FROM php:8.5-cli-alpine AS composer
WORKDIR /app
COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer
RUN apk add --no-cache git unzip
COPY . .
# --no-dev : PHPUnit, Faker, Mockery… n'ont rien à faire dans l'image de production
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

# Build Stage for Node.js dependencies and assets
FROM node:24-alpine AS node
WORKDIR /app
COPY . .

RUN npm ci --no-audit --no-fund && \
    npx vite build \
    && rm -rf node_modules

# Final production image
FROM alpine:3.24

LABEL org.opencontainers.image.title="OpenTransfer" \
      org.opencontainers.image.description="Plateforme auto-hébergée de partage de fichiers (fork maintenu d'Erugo)" \
      org.opencontainers.image.source="https://github.com/JuzoSuzuya20/OpenTransfer" \
      org.opencontainers.image.licenses="MIT"

# Multi-arch support - TARGETARCH is automatically set by Docker buildx
ARG TARGETARCH

# Version passed from build system (CI)
ARG VERSION=unknown
ENV APP_VERSION=${VERSION}

WORKDIR /var/www/html

ENV TZ=UTC
# Port Caddy listens on inside the container (non-root: Docker allows binding 80)
ENV ERUGO_PORT=80
# Caddy keeps its state in /tmp: nothing outside storage and /tmp is written at runtime
ENV XDG_CONFIG_HOME=/tmp/caddy/config XDG_DATA_HOME=/tmp/caddy/data

# Install production requirements
# (imap, mongodb, soap et ldap retirés : aucune utilisation dans le code)
RUN apk add --no-cache \
    curl \
    su-exec \
    supervisor \
    ffmpeg \
    php85 \
    php85-pdo_pgsql \
    php85-pdo_sqlite \
    php85-gd \
    php85-curl \
    php85-pdo_mysql \
    php85-mbstring \
    php85-xml \
    php85-zip \
    php85-bcmath \
    php85-intl \
    php85-pecl-redis \
    php85-pecl-memcached \
    php85-phar \
    php85-openssl \
    php85-dom \
    php85-tokenizer \
    php85-fileinfo \
    php85-ctype \
    php85-session \
    php85-simplexml \
    php85-xmlwriter \
    php85-sodium \
    tzdata \
    php85-fpm \
    caddy \
    && ln -sf /usr/bin/php85 /usr/bin/php \
    && rm -rf /var/cache/apk/*

# Download and install tusd for the correct architecture.
# Les empreintes sont épinglées ici : une archive altérée fait échouer le build.
ENV TUSD_VERSION=2.10.1
ARG TUSD_SHA256_AMD64=e8c8d7738275d2d9377db953aecbc242262d3620e77fbd3c1491361d9cea6ac8
ARG TUSD_SHA256_ARM64=134e575a6524698b54d989958c680f0a4ec243d9622c1926e30e27c2a86e4752
RUN if [ "$TARGETARCH" = "amd64" ]; then TUSD_SHA256="$TUSD_SHA256_AMD64"; \
    elif [ "$TARGETARCH" = "arm64" ]; then TUSD_SHA256="$TUSD_SHA256_ARM64"; \
    else echo "Unsupported architecture: $TARGETARCH" && exit 1; fi && \
    curl -fsSL -o /tmp/tusd.tar.gz "https://github.com/tus/tusd/releases/download/v${TUSD_VERSION}/tusd_linux_${TARGETARCH}.tar.gz" && \
    echo "${TUSD_SHA256}  /tmp/tusd.tar.gz" | sha256sum -c - && \
    tar -xzf /tmp/tusd.tar.gz -C /tmp && \
    mv /tmp/tusd_linux_${TARGETARCH}/tusd /usr/local/bin/tusd && \
    chmod +x /usr/local/bin/tusd && \
    rm -rf /tmp/tusd.tar.gz /tmp/tusd_linux_${TARGETARCH}

# Create necessary directories
RUN mkdir -p /var/www/html/public /etc/php85/php-fpm.d /etc/caddy

COPY ./docker/alpine/www.conf /etc/php85/php-fpm.d/www.conf
COPY ./docker/alpine/php-fpm-erugo.conf /etc/php85/php-fpm-erugo.conf
COPY ./docker/alpine/Caddyfile /etc/caddy/Caddyfile

# Set timezone
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Create erugo user and group (uid/gid 1000, as in Erugo: existing volumes keep their owner)
RUN addgroup -g 1000 erugo && \
    adduser -D -u 1000 -G erugo erugo

# Copy necessary files from build stages and local context.
# The code stays owned by root: the erugo user can read it, not change it.
COPY . /var/www/html/
COPY --from=composer /app/vendor /var/www/html/vendor
COPY --from=node /app/public/build /var/www/html/public/build
RUN rm -rf /var/www/html/.env /var/www/html/.git /var/www/html/node_modules /var/www/html/storage \
    # Owned by erugo before VOLUME, so a new named volume starts writable for uid 1000
    && mkdir -p /var/www/html/storage && chown erugo:erugo /var/www/html/storage \
    # Public links into storage, made here because public/ is read-only at runtime
    && cd /var/www/html/public && rm -rf storage backgrounds images \
    && ln -s ../storage/app/public storage \
    && ln -s ../storage/app/backgrounds backgrounds \
    && ln -s ../storage/app/public/images images \
    # Package manifests pre-built: bootstrap/cache is not writable at runtime
    && cd /var/www/html && php artisan package:discover --no-ansi > /dev/null

# Copy configuration files
COPY ./docker/alpine/start-container /usr/local/bin/start-container
COPY ./docker/alpine/ot-artisan /usr/local/bin/ot-artisan
COPY ./docker/alpine/opentransfer-env /usr/local/lib/opentransfer-env
COPY ./docker/alpine/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY ./docker/alpine/php.ini /etc/php85/conf.d/99-erugo.ini
RUN chmod +x /usr/local/bin/start-container /usr/local/bin/ot-artisan

# Shares, database, keys and logs live here: mount it to keep them across upgrades
VOLUME ["/var/www/html/storage"]

EXPOSE 80/tcp

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS "http://localhost:${ERUGO_PORT}/up" || exit 1

ENTRYPOINT ["start-container"]
