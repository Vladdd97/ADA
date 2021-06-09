FROM composer
RUN docker-php-ext-install sockets
WORKDIR /lab2
COPY composer.json .
RUN composer install
# COPY . .
CMD ["/bin/bash",  "-c", "php", "rpc_slave.php"]
# ENTRYPOINT ["/bin/bash",  "-c", "php", "rpc_slave.php"]