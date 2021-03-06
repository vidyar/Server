<?php

namespace Gloubster\Tests\Server\Component;

use Gloubster\Server\Component\STOMPComponent;
use Gloubster\Tests\GloubsterTest;
use React\EventLoop\Factory as LoopFactory;

/**
 * @covers Gloubster\Server\Component\STOMPComponent
 */
class STOMPComponentTest extends GloubsterTest
{
    /** @test */
    public function itShouldRegister()
    {
        $server = $this->getServer();
        $server['loop'] = LoopFactory::create();

        $phpunit = $this;

        $server['dispatcher']->on('stomp-connected', function ($server, $client) use ($phpunit) {
            $server->stop();

            $phpunit->assertInstanceOf('Gloubster\\Server\\GloubsterServerInterface', $server);
            $phpunit->assertInstanceOf('React\\Stomp\\Client', $client);
        });

        $server->run();
    }

    public function testEvents()
    {
        $server = $this->getServer();

        $component = new STOMPComponent();
        $component->register($server);

        $server['dispatcher']->emit('redis-connected', array($server, $this->getPredisAsyncClient(), $this->getPredisAsyncConnection()));
        $server['dispatcher']->emit('stomp-connected', array($server, $server['stomp-client']));
        $server['dispatcher']->emit('booted', array($server));
    }
}
