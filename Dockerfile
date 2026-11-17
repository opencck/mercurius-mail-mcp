# mail-mcp: an MCP server of mail with its own IMAP and SMTP (a module of the Mercurius agent).
FROM php:8.2-cli-bookworm AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts
COPY src ./src
COPY bin ./bin

FROM php:8.2-cli-bookworm
RUN apt-get update \
    && docker-php-ext-install pcntl \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --uid 10042 --home /app mailmcp \
    && echo 'memory_limit=256M' > /usr/local/etc/php/conf.d/mail.ini
WORKDIR /app
COPY --from=build /app /app
USER mailmcp
EXPOSE 8090
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8090/health") === false ? 1 : 0);'
CMD ["php", "bin/mail-mcp"]
