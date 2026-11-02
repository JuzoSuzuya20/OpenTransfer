# Development image based on Alpine - matches production architecture
FROM alpine:3.24

LABEL org.opencontainers.image.source="https://github.com/JuzoSuzuya20/OpenTransfer"

# Multi-arch support - TARGETARCH is automatically set by Docker buildx
ARG TARGETARCH

WORKDIR /var/www/html

ENV TZ=UTC
ENV SUPERVISOR_PHP_FPM_COMMAND="/usr/sbin/php-fpm85 -F"
ENV SUPERVISOR_CADDY_COMMAND="/usr/sbin/caddy run --config /etc/caddy/Caddyfile"
ENV SUPERVISOR_PHP_USER="erugo"


# Install production requirements + development tools
RUN apk add --no-cache \
    curl \
    su-exec \
    supervisor \
    cronie \
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
    libcap \
    zip \
    unzip \
    git \
    php85-fpm \
    caddy \
    nodejs \
    npm \
    && ln -sf /usr/bin/php85 /usr/bin/php \
    && rm -rf /var/cache/apk/*

# Install Composer
COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer

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
RUN mkdir -p /run/php /var/www/html/public /etc/php85/php-fpm.d /etc/caddy

COPY ./www.conf /etc/php85/php-fpm.d/www.conf
COPY ./Caddyfile /etc/caddy/Caddyfile

# Set timezone
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone


# Create erugo user and group
RUN addgroup erugo && \
    adduser -D -G erugo erugo

# Set permissions
RUN chown -R erugo:erugo /run/php

# Copy configuration files
COPY ./start-container /usr/local/bin/start-container
COPY ./supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY ./php.ini /etc/php85/conf.d/99-erugo.ini
RUN chmod +x /usr/local/bin/start-container

# Create supervisor log directory
RUN mkdir -p /var/log/supervisor

# Create npm cache directory with correct permissions
RUN mkdir -p /.npm && chown -R erugo:erugo /.npm

EXPOSE 80/tcp
EXPOSE 5173/tcp

ENTRYPOINT ["start-container"]

