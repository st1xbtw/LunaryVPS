FROM php:8.2-cli

# Устанавливаем cURL для PHP
RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# Копируем файлы проекта
WORKDIR /app
COPY . .

# Открываем порт (Render сам подставит нужный через $PORT)
EXPOSE 10000

# Запускаем встроенный PHP-сервер
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} index.php"]
