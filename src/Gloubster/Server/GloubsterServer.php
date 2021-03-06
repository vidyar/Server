<?php

namespace Gloubster\Server;

use Evenement\EventEmitter;
use Gloubster\Configuration;
use Gloubster\Server\Component\ComponentInterface;
use Gloubster\Server\Component\MessageHandlerComponent;
use Gloubster\Server\Component\RedisComponent;
use Gloubster\Server\Component\STOMPComponent;
use Gloubster\Server\Component\WebsocketServerComponent;
use Monolog\Logger;
use React\EventLoop\LoopInterface;

/**
 * @event start
 * @event booted
 * @event stop
 * @event error
 * @event stomp-connected
 * @event redis-connected
 */
class GloubsterServer extends \Pimple implements GloubsterServerInterface
{
    private $components = array();

    public function __construct(LoopInterface $loop, Configuration $conf, Logger $logger)
    {
        declare(ticks = 1);
        pcntl_signal(SIGTERM, array($this, 'signalHandler'));
        pcntl_signal(SIGINT, array($this, 'signalHandler'));

        $this['loop'] = $loop;
        $this['configuration'] = $conf;
        $this['monolog'] = $logger;
        $this['dispatcher'] = new EventEmitter();

        $this->register(new RedisComponent());
        $this->register(new STOMPComponent());
        $this->register(new MessageHandlerComponent());
        $this->register(new WebsocketServerComponent());
    }

    public function signalHandler($signal)
    {
        $this['monolog']->addInfo('Caught Ctrl-C, stopping ...');
        $this->stop();
    }

    /**
     * {@inheritdoc}
     */
    public function register(ComponentInterface $component)
    {
        $component->register($this);
        $this->components[] = $component;

        $this['monolog']->addInfo(sprintf('Registering component %s', get_class($component)));
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this['monolog']->addInfo(sprintf('Starting server with %d components', count($this->components)));

        $this['dispatcher']->emit('start', array($this));

        $this['loop']->run();
    }

    public function stop()
    {
        $this['dispatcher']->emit('stop', array($this));
        $this['loop']->stop();
    }

    public function probeAllSystems()
    {
        if ($this['stomp-client.started'] && $this['redis-client.started']) {
            $this['monolog']->addInfo('All services loaded, server now running');
            $this['dispatcher']->emit('booted', array($this));
        }
    }

    public function logError(\Exception $error)
    {
        $this['monolog']->addError($error->getMessage());
    }

    public function throwError(\Exception $error)
    {
        $this->logError($error);
        throw $error;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(LoopInterface $loop, Configuration $conf, Logger $logger)
    {
        return new GloubsterServer($loop, $conf, $logger);
    }
}
