<?php

namespace App\Services;

use App\Models\OnlineUsers;
use App\Models\User;

class RelayHandle
{
    private mixed $data;
    private $clientSocket;

    private $connection;

    private $onlineUsers;

    private array $users = [];



    public function __construct(OnlineUsers $onlineUsers, User $user)
    {
        $this->onlineUsers = $onlineUsers;
//        $this->users = $user;


    }

    public function relay(String $message, $clientSocket, $connection): static
    {
        echo 'actual_meesage: ' . $message . '\n';
//        $this->data = json_decode(strstr(mb_convert_encoding($message, 'UTF-8', 'UTF-8'), '{'));
        $this->data = json_decode($message);
//        echo dump($this->data);
        echo json_last_error();
//        dd(gettype($clientSocket));
        $this->clientSocket = $clientSocket;
        $this->connection = $connection;
        var_dump($this->data);
        echo "data ----------  \n";

        return $this;
    }

        private function encodeWebSocketFrame($payload, $type = 'text'): string
        {
            $frame = '';
            $frame .= chr(0x81); // Text frame opcode
            $length = strlen($payload);

            if ($length <= 125) {
                $frame .= chr($length);
            } elseif ($length >= 126 && $length <= 65535) {
                $frame .= chr(126) . pack('n', $length);
            } else {
                $frame .= chr(127) . pack('J', $length);
            }

            $frame .= $payload;
            return $frame;
    }

    public function handle(): self
    {
        echo 'now in handle';
        // var_dump('hello', $this->data['action']);
        $action = $this->data?->action;
        echo $action;
        if ($action == 'ping') {
            echo 'process ping';
            return $this->pingHandle();
        } else if($action == 'send_message') {
            return $this->sendMessageHandle();
        } else if($action == 'typing') {
            return $this->typingHandle();
        } else if($action == 'notification') {
            return $this->notificationHandle();
        } else if($action == 'send_message_in_group') {
            return $this->sendMessageInGroupHandle();
        } else if($action == "private_chat_request") {
            return $this->privateChatRequestHandle();
        } else if($action == "accept_or_reject_chat_request") {
            return $this->acceptOrRejectChatRequestHandle();
        } else {
            return $this;
        }

//        return $this;

    }

    public function pingHandle(): self
    {
        echo 'now in ping';

        $this->users[$this->data->user_id] =  $this->clientSocket;
        $this->onlineUsers->users_available = $this->data->user_id;
        $this->onlineUsers->save();

        $onlineUsers = $this->onlineUsers->get()->filter(function ($user) {
            return $user->users_available == $this->data->user;
        })->map(function ($user) {
            return $user->users_available;
        });

        $response =  json_encode([
            'event' => 'ping_success',
            'success' => true,
            'data' => [
                'online_users' => $onlineUsers
            ]
        ]);


        $this->sendMessage($this->clientSocket, $response);
        return $this;
    }

    public function sendMessage(mixed $clientSocket, String $message): self
    {
        $clientSocket->write($this->encodeWebSocketFrame($message));

        return $this;
    }

    public function sendMessageHandle(): self
    {

        if (count($this->users) < 1) {
            return $this;
        }
        $responseData = $this->data;
        $to = $responseData->data->to;

        $this->onlineUsers->where('users_available', $to)->get()->each(function ($user) use ($responseData){
            $response =  json_encode([
                'event' => 'receive_message',
                'action' => 'messageReceived',
                'data' => $responseData->data
            ]);

            $this->sendMessage($this->users[$user->users_available], $response);
        });


        return $this;
    }

    public function typingHandle(): static
    {
        if (count($this->users) < 1) {
            return $this;
        }
        $responseData = $this->data;
        $receivers = $responseData->data->members;

        $payload =  json_encode([
            'event' => 'lis_typing',
            'action' => 'messageReceived',
            'data' => $responseData->data
        ]);

        $this->getEnumeratesValues($receivers, $responseData, $payload);



       return $this;

    }

    public function notificationHandle(): static
    {
        if (count($this->users) < 1) {
            return $this;
        }
        $responseData = $this->data;
        $receivers = $responseData->data->receivers;
        $payload =  json_encode([
            'event' => 'receive_notification',
            'action' => 'messageReceived',
            'data' => $responseData->data
        ]);

        $this->getEnumeratesValues($receivers, $responseData, $payload);
        return $this;

    }

    public function sendMessageInGroupHandle(): static
    {
        if (count($this->users) < 1) {
            return $this;
        }

        $responseData = $this->data;
        $receivers = $responseData->data->receivers;
        $payload =  json_encode([
            'event' => 'receive_message',
            'action' => 'messageReceived',
            'data' => $responseData->data
        ]);

        $this->getEnumeratesValues($receivers, $responseData, $payload);

        return $this;
    }

    public function privateChatRequestHandle(): static
    {
        if (count($this->users) < 1) {
            return $this;
        }

        $responseData = $this->data;
        $to = $responseData->data->to;

        $this->onlineUsers->where('users_available', $to)->get()->each(function ($user) use ($responseData) {
            $response =  json_encode([
                'event' => 'receive_private_chat_request',
                'action' => 'messageReceived',
                'data' => $responseData->data
            ]);

            $this->sendMessage($this->users[$user->users_available], $response);
            return $this;
        });
        return $this;
    }

    public function acceptOrRejectChatRequestHandle(): static
    {
        if (count($this->users) < 1) {
            return $this;
        }

        $responseData = $this->data;
        $to = $responseData->data->to;

        $status = $responseData->data->accept_or_reject == '1' ? 'Accepted' : 'Rejected';

        if (in_array($to, $this->onlineUsers->users)) {
            $response =  json_encode([
                'event' => 'private_chat_request_receive',
                'action' => 'messageReceived',
                'data' => $responseData->data
            ]);

            // $response = make_response($response);
            // socket_write($users[$to], $response);
            $this->sendMessage($this->users[$to], $response);

        }

        return $this;
    }

    /**
     * @param $receivers
     * @param mixed $responseData
     * @return void
     */
    public function getEnumeratesValues($receivers, mixed $responseData, $payload): void
    {
        collect($receivers)->each(function ($to) use ($responseData, $payload) {
            echo 'hello: \n';
            if ($this->onlineUsers->where('users_available', $to)->exists()) {
                if ($this->users[$to] != $this->connection) {
                    $this->sendMessage($this->users[$to], $payload);
                }
            }
        });
    }

    public function disconnect($client)
    {
       $index = array_search($client, $this->users);
       $this->users = collect($this->users)->filter(fn($user) => $user == $client)->toArray();
       $this->onlineUsers->where('users_available', $index)->delete();

    }

    

}
