<?php
namespace Proxy;

use Bunny\Async\Client;
use Bunny\Channel;
use Bunny\Message;
use League\CLImate\CLImate;
use React\EventLoop\LoopInterface;
use React\HttpClient\Factory;
use React\HttpClient\Response;
use React\Promise;

/**
 * Class Consumer
 */
class Consumer
{
    /**
     * @var \Bunny\Async\Client
     */
    private $_client;
    /**
     * @var \Bunny\Channel
     */
    private $_channel;
    private $_dnsResolver;
    /**
     * @var \League\CLImate\CLImate
     */
    private $_climate;

    public function __construct(LoopInterface $loop, $dnsResolver, CLImate $climate)
    {
        $this->_climate = $climate;
        $this->_dnsResolver = $dnsResolver;
        $this->_loop = $loop;
        $this->_client = new Client($loop, [
            'user' => 'proxy',
            'pass' => 'proxy',
            'vhost' => '/api-proxy'
        ]);
    }

    public function connect()
    {
        $this->_client->connect()
            ->then(function (Client $client) {
                $this->_climate->out('Connected to RabbitMQ. Opening channel...');

                return $client->channel();
            })
            ->then(function (Channel $channel) {
                $this->_climate->out('Channel opened. Declaring queues...');
                $this->_channel = $channel;

                return Promise\all([
                    $this->_channel,
                    $this->_channel->queueDeclare("proxy"),
                    $this->_channel->exchangeDeclare("proxy_exchange"),
                    $this->_channel->queueBind("proxy", "proxy_exchange"),
                ]);
            })
            ->then([$this, 'consume']);

        $this->_climate->out('Starting event loop...');
        $this->_client->run();
    }

    public function consume()
    {
        $this->_climate->out('Starting queue consumer');
        $this->_channel->qos(0, 25);

        $this->requests = 0;
        $this->time = microtime(true);
        return $this->_channel->consume(function (Message $message, Channel $channel, Client $client) {
            $this->performHttpCall($message, $channel);
        }, 'proxy')->then(function () {
            $this->_climate->out('Now consuming.');
        });
    }

    public function performHttpCall(Message $message, Channel $channel)
    {
        $factory = new Factory();
        $client = $factory->create($this->_loop, $this->_dnsResolver);

        $requestInfo = json_decode($message->content, true);
        $postData = '';

        $request = $client->request($requestInfo['method'], $requestInfo['url']);
        if ($requestInfo['method'] === 'PUT') {
            $postData = json_encode($requestInfo['data']);
        }
        $request->on('response', function (Response $response) use ($message, $channel) {
            $this->requests++;
            $this->_climate->clear();
            $this->_climate->out(sprintf('Requests per second: %.2f', $this->requests / (microtime(true) - $this->time)));
            $channel->ack($message);
        });
        $request->end($postData);
    }
}
