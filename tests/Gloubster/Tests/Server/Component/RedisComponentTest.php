<?php

namespace Gloubster\Tests\Server\Component;

use Gloubster\Server\Component\RedisComponent;
use Gloubster\Tests\GloubsterTest;
use React\EventLoop\Factory as LoopFactory;

/**
 * @covers Gloubster\Server\Component\RedisComponent
 */
class RedisComponentTest extends GloubsterTest
{
    /** @test */
    public function itShouldRegister()
    {
        $server = $this->getServer();

        $server['loop'] = LoopFactory::create();
        $phpunit = $this;

        $server['dispatcher']->on('redis-connected', function ($server, $client, $conn) use ($phpunit) {
            $server->stop();

            $phpunit->assertInstanceOf('Gloubster\\Server\\GloubsterServerInterface', $server);
            $phpunit->assertInstanceOf('Predis\\Async\\Client', $client);
            $phpunit->assertInstanceOf('Predis\\Async\\Connection\\ConnectionInterface', $conn);
        });

        $server->run();
    }

    public function testEvents()
    {
        $server = $this->getServer();

        $component = new RedisComponent();
        $component->register($server);

        $server['dispatcher']->emit('redis-connected', array($server, $this->getPredisAsyncClient(), $this->getPredisAsyncConnection()));
        $server['dispatcher']->emit('stomp-connected', array($server, $server['stomp-client']));
        $server['dispatcher']->emit('booted', array($server));
    }
}
