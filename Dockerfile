FROM php:8.2-cli

# Устанавливаем cURL для PHP
RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# Копируем файлы проекта
WORKDIR /app
COPY . .

EXPOSE 10000

# Запускаем PHP-сервер на порту Render
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} index.php"]
