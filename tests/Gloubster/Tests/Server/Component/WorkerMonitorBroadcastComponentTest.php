<?php

namespace Gloubster\Tests\Server\Component;

use Gloubster\Server\Component\WorkerMonitorBroadcastComponent;
use Gloubster\Tests\GloubsterTest;

class WorkerMonitorBroadcastComponentTest extends GloubsterTest
{
    /** @test */
    public function itShouldRegister()
    {
        $server = $this->getServer();
        $server['configuration'] = $this->getTestConfiguration();
        $server->register(new WorkerMonitorBroadcastComponent());

        $server['dispatcher']->emit('stomp-connected', array($server, $server['stomp-client']));
    }

    public function testEvents()
    {
        $server = $this->getServer();

        $component = new WorkerMonitorBroadcastComponent();
        $component->register($server);

        $server['dispatcher']->emit('redis-connected', array($server, $this->getPredisAsyncClient(), $this->getPredisAsyncConnection()));
        $server['dispatcher']->emit('stomp-connected', array($server, $server['stomp-client']));
        $server['dispatcher']->emit('boot-connected', array($server));
    }
}
