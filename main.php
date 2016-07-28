<?php

require 'vendor/autoload.php';

$climate = new League\CLImate\CLImate;

$climate->out('Creating event loop...');
$loop = \React\EventLoop\Factory::create();

$climate->out('Creating dns resolver...');
$dnsResolverFactory = new React\Dns\Resolver\Factory();
$dnsResolver = $dnsResolverFactory->createCached('8.8.8.8', $loop);

$climate->out('Creating consumer instance...');
$consumer = new \Proxy\Consumer($loop, $dnsResolver, $climate);
$consumer->connect();
