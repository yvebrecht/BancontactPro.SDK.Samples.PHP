# syntax=docker/dockerfile:1
# The package is not published yet and the sample installs the sister folder ../BancontactPro.PHP (a Composer path repository), so
# the build context is the folder that holds both repositories. From this folder: docker compose up --build (see docker-compose.yml).

FROM composer:2 AS build
WORKDIR /work
COPY BancontactPro.PHP/ BancontactPro.PHP/
COPY BancontactPro.SDK.Samples.PHP/composer.json BancontactPro.SDK.Samples.PHP/
RUN composer install --working-dir=BancontactPro.SDK.Samples.PHP --no-dev --no-interaction --no-progress --classmap-authoritative
COPY BancontactPro.SDK.Samples.PHP/src/ BancontactPro.SDK.Samples.PHP/src/
RUN composer dump-autoload --working-dir=BancontactPro.SDK.Samples.PHP --no-dev --classmap-authoritative

FROM php:8.3-cli-alpine
WORKDIR /app
COPY --from=build /work/BancontactPro.SDK.Samples.PHP/vendor vendor
COPY --from=build /work/BancontactPro.SDK.Samples.PHP/src src
COPY BancontactPro.SDK.Samples.PHP/public public

# PHP serves each request in a fresh process, so the debug log and the callbacks live in files under var/.
RUN mkdir var && chown nobody var
USER nobody
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
