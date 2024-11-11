<?php

require __DIR__ . '/vendor/autoload.php';

$socket = new React\Socket\SocketServer('127.0.0.1:5000');

        $socket = new React\Socket\LimitingServer($socket, null);

        $socket->on('connection', function (React\Socket\ConnectionInterface $connection) use ($socket) {
            echo '[' . $connection->getRemoteAddress() . ' connected]' . PHP_EOL;

            // whenever a new message comes in
            $connection->on('data', function ($data) use ($connection, $socket) {
                // remove any non-word characters (just for the demo)
                $data = trim(preg_replace('/[^\w\d \.\,\-\!\?]/u', '', $data));

                // ignore empty messages
                if ($data === '') {
                    return;
                }

                // prefix with client IP and broadcast to all connected clients
                $data = trim(parse_url($connection->getRemoteAddress(), PHP_URL_HOST), '[]') . ': ' . $data . PHP_EOL;
                foreach ($socket->getConnections() as $connection) {
                    $connection->write($data);
                }
            });

            $connection->on('close', function () use ($connection) {
                echo '[' . $connection->getRemoteAddress() . ' disconnected]' . PHP_EOL;
            });
        });

        $socket->on('error', function (Exception $e) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
        });

        echo 'Listening on ' . $socket->getAddress() . PHP_EOL;
