# PHP + Apache para la aplicación y el worker de sincronización.
# El código no se copia a la imagen: se monta desde el proyecto (ver docker-compose.yml),
# así config/local.php nunca queda guardado dentro de una imagen.
FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

COPY app.ini /usr/local/etc/php/conf.d/zz-app.ini
# El envío masivo pausa entre cada mensaje; el Timeout de Apache por defecto
# (300s en la imagen base, pero puede ser menor en otras) debe cubrir eso.
RUN echo 'Timeout 600' > /etc/apache2/conf-available/zz-timeout.conf \
    && a2enconf zz-timeout

# Document root = public/ (el resto del proyecto no se expone al navegador).
ENV APACHE_DOCUMENT_ROOT=/var/www/app/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}/!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri 's!^ServerTokens .*!ServerTokens Prod!; s!^ServerSignature .*!ServerSignature Off!' /etc/apache2/conf-available/security.conf

WORKDIR /var/www/app
