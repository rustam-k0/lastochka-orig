FROM nginx

WORKDIR /var/www

COPY . /var/www
COPY ./nginx.conf /etc/nginx/nginx.conf

RUN apt-get update && apt-get install -y curl
RUN curl -sL https://deb.nodesource.com/setup_16.x | bash -
RUN apt-get install -y nodejs
RUN npm install -g npm yarn

RUN yarn --silent
RUN yarn build

EXPOSE 80
