<?php

namespace App\Services;

use App\Models\OnlineUsers;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class RelayHandle
{
    private mixed $data;
    private mixed $clientSocket;

    private mixed $connection;
    private array $users = [];

    private string $action = '';

    public function __construct()
    {


    }

    public function relay(String $message, $clientSocket, $connection): static
    {
        echo 'actual_meesage: ' . $message . '\n';
//        $this->data = json_decode(strstr(mb_convert_encoding($message, 'UTF-8', 'UTF-8'), '{'));
        $this->data = json_decode($message);
//        echo dump($this->data);
        echo json_last_error();
//        dd(gettype($clientSocket));
         $this->action = $this->data->action;
        $this->clientSocket = $clientSocket;
        $this->connection = $connection;
        dump($this->data);
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
//        $action = $this->data?->action;
        echo $this->action;
//        $this->users

//        dump('list of user: ', $this->users);
//        die();
       return match ($this->action) {
            'ping' => $this->pingHandle(),
            'send_message' => $this->sendMessageHandle(),
            'typing' => $this->typingHandle(),
            'notification' => $this->notificationHandle(),
            'send_message_in_group' => $this->sendMessageInGroupHandle(),
            'private_chat_request' => $this->privateChatRequestHandle(),
            'accept_or_reject_chat_request' => $this->acceptOrRejectChatRequestHandle(),
            'default' => $this
        };
//        if ($action == 'ping') {
//            echo 'process ping';
//            $this->action = 'ping';
//            return $this->pingHandle();
//        } else if($action == 'send_message') {
//            $this->action = 'send_message';
//            return $this->sendMessageHandle();
//        } else if($action == 'typing') {
//            $this->action = 'typing';
//            return $this->typingHandle();
//        } else if($action == 'notification') {
//            $this->action = 'typing';
//            return $this->notificationHandle();
//        } else if($action == 'send_message_in_group') {
//            $this->action = 'typing';
//            return $this->sendMessageInGroupHandle();
//        } else if($action == "private_chat_request") {
//            $this->action = 'typing';
//            return $this->privateChatRequestHandle();
//        } else if($action == "accept_or_reject_chat_request") {
//            $this->action = 'typing';
//            return $this->acceptOrRejectChatRequestHandle();
//        } else {
//            return $this;
//        }

//        return $this;

    }

    public function pingHandle(): self
    {
        echo 'now in ping';

        $this->users[(int)$this->data->user_id] =  $this->clientSocket;
        $onlineUsers = array_keys($this->users);

        Cache::put('online_users', $onlineUsers);

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

        collect(Cache::get('online_users'))->filter(function ($user) use ($to) {
            return $user === $to;
        })->each(function ($user) use ($responseData, $to){
            $response =  json_encode([
                'event' => 'receive_message',
                'action' => 'messageReceived',
                'data' => $responseData->data
            ]);

            $this->sendMessage($this->users[$to], $response);
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

        collect(cache('online_users'))->filter(function($user) use ($to) {
            return $user === $to;
        })->each(function ($user) use ($responseData) {
            $response =  json_encode([
                'event' => 'receive_private_chat_request',
                'action' => 'messageReceived',
                'data' => $responseData->data
            ]);

            $this->sendMessage($this->users[$user], $response);
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

        if (in_array($to, \cache('online_users'))) {
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
            if (in_array($to, cache('online_users'))) {
                $this->sendMessage($this->users[$to], $payload);
            }
        });
    }

    public function disconnect($client)
    {
       $index = array_search($client, $this->users);
       $this->users = collect($this->users)->filter(fn($user) => $user == $client)->toArray();
       \cache()->delete('online_users');

    }

    public function resetUsers()
    {
        $this->users = [];
        \cache()->delete('online_users');
    }

    public function getAction(): string
    {
        return $this->action;
    }
}
