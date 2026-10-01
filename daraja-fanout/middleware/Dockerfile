FROM php:8.2-cli-alpine
RUN docker-php-ext-install curl
WORKDIR /app
COPY index.php helpers.php ./
RUN mkdir -p data logs && chmod -R 775 data logs
EXPOSE 8080
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} index.php"]
