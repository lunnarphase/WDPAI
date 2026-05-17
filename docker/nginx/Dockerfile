FROM nginx:1.17.8-alpine

RUN apk add --no-cache openssl && \
    mkdir -p /etc/nginx/ssl && \
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout /etc/nginx/ssl/nginx.key \
        -out /etc/nginx/ssl/nginx.crt \
        -subj "/C=PL/ST=Poland/L=Warsaw/O=MediSchedule/OU=IT/CN=localhost"

COPY . /app/
COPY ./docker/nginx/nginx.conf /etc/nginx/conf.d/default.conf