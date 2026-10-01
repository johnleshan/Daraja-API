FROM php:8.2-cli-alpine
RUN docker-php-ext-install curl
WORKDIR /app
COPY index.php ./
RUN mkdir -p data && chmod -R 775 data
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} index.php"]
