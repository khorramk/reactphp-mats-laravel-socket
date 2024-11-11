<?php

namespace App\Console\Commands;

use App\Models\OnlineUsers;
use App\Services\RelayHandle;
use Illuminate\Console\Command;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use React\Stream\WritableResourceStream;

class WebsocketServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wss:server {host=0.0.0.0} {port=5000}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Websocket Server in Laravel';

    /**
     * Execute the console command.
     */
    public function handle(RelayHandle $relayHandle)
    {

            $host = $this->argument('host');
            $port = $this->argument('port');

            $context = [
                'tls' => [
                    'local_cert' => '/var/cpanel/ssl/cpanel/mycpanel.pem',
                ]
            ];



            $loop = Loop::get();
            $socket = new SocketServer('0.0.0.0:8080', (array)$loop);

            echo "WebSocket server started at ws://localhost:8080\n";

            $clients = [];

            $socket->on('connection', function ($connection) use ($relayHandle, &$clients) {
                echo "New connection from {$connection->getRemoteAddress()}\n";

                // Add this new connection to the list of clients
                    $clients[] = $connection;
                    $this->trap(SIGTERM, function() {

                    } );
                    // Handle the WebSocket handshake
                    $connection->on('data', function ($data) use ($relayHandle, $connection, &$clients) {
                    // Check if this is a WebSocket handshake request
                    if (str_contains($data, 'GET') && str_contains($data, 'Upgrade: websocket')) {
                        // Send the handshake response
                   //                $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
                 $headers = array();
                 $lines = preg_split("/\r\n/", $data);
                 foreach($lines as $line) {
                     $line = rtrim($line);
                     if(preg_match('/\A(\S+): (.*)\z/', $line, $matches)){
                         $headers[$matches[1]] = $matches[2];
                     }
                 }
                        $secKey = $headers['Sec-WebSocket-Key'];
                        $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

                        $headers = [
                            "HTTP/1.1 101 Web Socket Protocol Handshake",
                            'Upgrade: websocket',
                            'Connection: Upgrade',
                            "Sec-WebSocket-Version: 13",
                            "WebSocket-Location: ws://0.0.0.0:8080",
                            "Sec-WebSocket-Accept:$secAccept"
                            //'Sec-WebSocket-Accept: ' . base64_encode(pack('H*', sha1(preg_replace('/Sec-WebSocket-Key: (.*)\r\n/', '$1', $data) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)))
                        ];
                        $connection->write(implode("\r\n", $headers) . "\r\n\r\n");
                    } else {
                        // Handle regular WebSocket frames
                        $decodedData = $this->decodeWebSocketFrame($data);
                        if ($decodedData !== null) {
                            $message = "Client says: " . $decodedData;
                            echo $message . "\n";
                            // Broadcast message to all connected clients
                            foreach ($clients as $client) {
//                                $client->write($this->encodeWebSocketFrame($decodedData));

                                $relayHandle->relay($decodedData, $client, $connection)->handle();


                         }
                        }
                    }
                });

                // Remove the connection when it closes
                $connection->on('close', function () use ($connection, &$clients, $relayHandle) {
                    echo "Connection {$connection->getRemoteAddress()} has disconnected\n";
                    $index = array_search($connection, $clients);
                    if ($index !== false) {
                        $relayHandle->disconnect($clients[$index]);
                        unset($clients[$index]);
                    }

                });
            });

            $loop->run();

            // WebSocket frame encoding/decoding functions


    }

    public function decodeWebSocketFrame($text): string
    {
        $length = @ord($text[1]) & 127;
        if($length == 126) {    $masks = substr($text, 4, 4);    $data = substr($text, 8); }
        elseif($length == 127) {    $masks = substr($text, 10, 4); $data = substr($text, 14); }
        else { $masks = substr($text, 2, 4); $data = substr($text, 6); }
        $text = "";
        for ($i = 0; $i < strlen($data); ++$i) { $text .= $data[$i] ^ $masks[$i % 4];    }
        return $text;



    }

   


}


