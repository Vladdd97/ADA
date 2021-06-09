

docker build -t php .



./cluster_start
docker-compose exec lab2_producer ruby ruby_server.rb



docker run --rm -it --net=host --name slave1 -v path to php :/lab2_m php php /lab2_m/rpc_slave.php


docker exec -it slave1 /bin/bash -c top
