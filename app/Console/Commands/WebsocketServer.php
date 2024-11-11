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
    public function handle(RelayHandle $relayHandle): void
    {

            $host = $this->argument('host');
            $port = $this->argument('port');
            $context = [
                'tls' => [
                    'local_cert' => '/var/cpanel/ssl/cpanel/mycpanel.pem',
                ]
            ];

            if (extension_loaded('ext-pcntl')) {
                $this->trap(SIGINT, function () use ($relayHandle) {
                    echo 'grace shutdown';
                    $relayHandle->resetUsers();
                });
            }

//            register_shutdown_function([$this, 'shutdownHandler']);


            $loop = Loop::get();
            $socket = new SocketServer("$host:$port", (array)$loop);

            echo "WebSocket server started at ws://$host:$port\n";

            $clients = [];




            $socket->on('connection', function ($connection) use ($relayHandle, &$clients, $host, $port) {
                echo "New connection from {$connection->getRemoteAddress()}\n";

                // Add this new connection to the list of clients
                    $clients[] = $connection;

                    // Handle the WebSocket handshake
                    $connection->on('data', function ($data) use ($relayHandle, $connection, &$clients, $host, $port) {
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
                            "WebSocket-Location: ws://$host:$port",
                            "Sec-WebSocket-Accept:$secAccept"
                            //'Sec-WebSocket-Accept: ' . base64_encode(pack('H*', sha1(preg_replace('/Sec-WebSocket-Key: (.*)\r\n/', '$1', $data) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)))
                        ];
                        $connection->write(implode("\r\n", $headers) . "\r\n\r\n");
                    } else {
                        // Handle regular WebSocket frames


//                        $decodedData = $this->decodeWebSocketFrame($data);
                        $decodedData = $this->check_websocket_frame_from_payload($data);
                        if ($decodedData) {
                            $message = "Client says: " . $decodedData;
                            echo $message . "\n";
                            // Broadcast message to all connected clients
                            foreach ($clients as $client) {
//                                $client->write($this->encodeWebSocketFrame($decodedData));
                              echo 'notify me \n';
                             $relays = $relayHandle->relay($decodedData, $client, $connection);

                             if ($relays->getAction() != 'ping' && ($client != $connection)) {
                                 $relays->handle();
                                 break;
                             }

                             $relays->handle();

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

/**
     * Shutdown handler function to execute on command termination.
     *
     * @return void
     */
    public function shutdownHandler(): void
    {
        $error = error_get_last();
        if ($error) {
            // Log the error if there was one
            $this->error('Command terminated unexpectedly', $error);
            $this->error('Command terminated due to an error.');
        } else {
            // Log successful shutdown
            $this->info('Command completed successfully');
            $this->info('Shutdown function executed successfully.');
        }
    }

    public function check_websocket_frame_from_payload($payload) {
        // Convert the payload into an array of bytes for easier processing
        $payloadBytes = array_map('ord', str_split($payload));

        // Step 1: Extract the first byte (FIN and Opcode)
        $firstByte = $payloadBytes[0];
        $fin = ($firstByte >> 7) & 0x01;
        $opcode = $firstByte & 0x0F;

        // Step 2: Extract the second byte (Mask bit and Payload Length)
        $secondByte = $payloadBytes[1];
        $masked = ($secondByte >> 7) & 0x01;
        $payloadLength = $secondByte & 0x7F;

        $offset = 2; // Start reading from the third byte

        // Step 3: Handle extended payload lengths
        if ($payloadLength == 126) {
            $payloadLength = ($payloadBytes[2] << 8) + $payloadBytes[3];
            $offset += 2;
        } elseif ($payloadLength == 127) {
            $payloadLength = 0;
            for ($i = 0; $i < 8; $i++) {
                $payloadLength = ($payloadLength << 8) + $payloadBytes[$offset + $i];
            }
            $offset += 8;
        }

        // Step 4: Extract the masking key if present
        $maskingKey = '';
        if ($masked) {
            $maskingKey = array_slice($payloadBytes, $offset, 4);
            $offset += 4;
        }

        // Step 5: Extract the actual payload data
        $payloadData = array_slice($payloadBytes, $offset, $payloadLength);

        // Step 6: Unmask the payload if it's masked
        if ($masked) {
            for ($i = 0; $i < $payloadLength; $i++) {
                $payloadData[$i] = chr($payloadData[$i] ^ $maskingKey[$i % 4]);
            }
        } else {
            $payloadData = array_map('chr', $payloadData); // Convert back to characters if not masked
        }

        // Step 7: Join the payload data into a string
        $payloadData = implode('', $payloadData);

        // Step 8: Check the opcode and respond accordingly
        switch ($opcode) {
            case 0x1: // Text frame
                echo "Received text message: $payloadData\n";
                return $payloadData;
                break;
            case 0x2: // Binary frame
                echo "Received binary data\n";
                break;
            case 0x8: // Close frame
                echo "Received close frame\n";
                break;
            case 0x9: // Ping frame
                echo "Received ping frame\n";
                // Respond with Pong (handle accordingly in real scenario)
                break;
            case 0xA: // Pong frame
                echo "Received pong frame\n";
                break;
            default:
                echo "Unknown frame received\n";
                break;
        }

        return null;
}

}


