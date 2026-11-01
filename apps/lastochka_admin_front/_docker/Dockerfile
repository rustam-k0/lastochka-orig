FROM nginx:alpine3.17

WORKDIR /var/www
COPY . /var/www
COPY --from=node:16-alpine3.17 /usr/local/bin /usr/local/bin
COPY --from=node:16-alpine3.17 /opt /opt
COPY --from=node:16-alpine3.17 /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN apk add libstdc++6 libstdc++
