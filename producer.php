<?php

require 'vendor/autoload.php';

$climate = new League\CLImate\CLImate;

$climate->out('Creating event loop...');
$loop = \React\EventLoop\Factory::create();

$client = new \Bunny\Async\Client($loop, [
    'user' => 'proxy',
    'pass' => 'proxy',
    'vhost' => '/api-proxy'
]);

$client->connect()
    ->then(function (\Bunny\Async\Client $client) use ($climate) {
        $climate->out('Connected to RabbitMQ. Opening channel...');

        return $client->channel();
    })
    ->then(function (\Bunny\Channel $channel) use ($climate) {
        $climate->out('Channel opened. Declaring queues...');

        return \React\Promise\all([
            $channel,
            $channel->queueDeclare("proxy"),
            $channel->exchangeDeclare("proxy_exchange"),
            $channel->queueBind("proxy", "proxy_exchange"),
        ]);
    })
    ->then(function ($r) use ($climate, $loop) {
        $channel = $r[0];

        $climate->out('Starting to push messages');
        $count = 0;

        do {
            usleep(mt_rand(1000, 20000));
            if (mt_rand(0, 2) === 1) {
                $message = json_encode([
                    'url' => 'http://jsonplaceholder.typicode.com/photos/' . mt_rand(1, 5000),
                    'method' => rand(0, 10) === 1 ? 'PUT' : 'GET',
                    'data' => [
                        'title' => 'Foo'
                    ]
                ]);
            } else {
                $message = json_encode([
                    'url' => 'http://echo.jsontest.com/key/value/one/two',
                    'method' => 'GET',
                ]);
            }
            $channel->publish($message, [], 'proxy_exchange')->then(function () use ($climate, $count) {
                $climate->out('Published message #' . $count);
            });
            $count++;
            $loop->tick();
        } while (true);
    });

$client->run();
