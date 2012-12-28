<?php

namespace Gloubster\Server;

use Gloubster\Configuration;
use Gloubster\Exchange;
use Gloubster\Message\Job\JobInterface;
use Gloubster\Server\SessionHandler;
use Gloubster\RabbitMQ\Configuration as RabbitMQConf;
use Gloubster\Server\Component\ComponentInterface;
use Gloubster\Exception\RuntimeException;
use Gloubster\Message\Factory as MessageFactory;
use Monolog\Logger;
use Predis\Async\Client as PredisClient;
use Predis\Async\Connection\ConnectionInterface as PredisConnection;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Wamp\WampServer;
use Ratchet\Session\SessionProvider;
use React\Curry\Util as Curry;
use React\Stomp\Client;
use React\EventLoop\LoopInterface;
use React\Socket\Server as Reactor;
use React\Stomp\Factory as StompFactory;

class GloubsterServer extends \Pimple implements GloubsterServerInterface
{
    private $components = array();
    private $redisStarted = false;
    private $stompStarted = false;

    public function __construct(WebsocketApplication $websocket, Client $client, LoopInterface $loop, Configuration $conf, Logger $logger)
    {
        $this['loop'] = $loop;
        $this['configuration'] = $conf;
        $this['monolog'] = $logger;
        $this['websocket-application'] = $websocket;
        $this['stomp-client'] = $client;

        $server = $this;
        $redisErrorHandler = function(PredisClient $client, \Exception $e, PredisConnection $conn) use ($server) {
            call_user_func(array($server, 'logError'), $e);
        };

        $redisOptions = array(
            'on_error'  => $redisErrorHandler,
            'eventloop' => $this['loop'],
        );

        $this['redis'] = new PredisClient(sprintf('tcp://%s:%s', $conf['redis-server']['host'], $conf['redis-server']['port']), $redisOptions);
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

        // Setup websocket server
        $socket = new Reactor($this['loop']);
        $socket->listen($this['configuration']['websocket-server']['port'], $this['configuration']['websocket-server']['address']);

        $server = new IoServer(new WsServer(
                       new SessionProvider(
                           new WampServer($this['websocket-application']),
                           SessionHandler::factory($this['configuration'])
                       )
                   ), $socket, $this['loop']);

        $this['monolog']->addInfo(sprintf('Websocket Server listening on %s:%d', $this['configuration']['websocket-server']['address'], $this['configuration']['websocket-server']['port']));

        $this['stomp-client']
            ->connect()
            ->then(
                Curry::bind(array($this, 'activateStompServices')),
                Curry::bind(array($this, 'throwError'))
            );
        $this['stomp-client']->on('error', array($this, 'logError'));
        $this['monolog']->addInfo('Connecting to STOMP Gateway...');

        $this['redis']->connect(array($this, 'activateRedisServices'));
        $this['monolog']->addInfo('Connecting to Redis server...');

        $this['loop']->run();
    }

    public function activateRedisServices(PredisClient $client, PredisConnection $conn)
    {
        $this['monolog']->addInfo('Connected to Redis Server !');

        foreach ($this->components as $component) {
            $component->registerRedis($this, $client, $conn);
        }

        $this->redisStarted = true;
        $this->probeAllSystems();
    }

    public function activateStompServices(Client $stomp)
    {
        $this['monolog']->addInfo('Connected to STOMP Gateway !');

        foreach ($this->components as $component) {
            $component->registerSTOMP($this, $stomp);
        }

        $this->stompStarted = true;
        $this->probeAllSystems();
    }

    private function probeAllSystems()
    {
        if ($this->stompStarted && $this->redisStarted) {
            $this['monolog']->addInfo('All services loaded, server now running');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function incomingMessage($message)
    {
        $data = null;

        try {
            $data = MessageFactory::fromJson($message);
        } catch (RuntimeException $e) {
            $this['monolog']->addError(sprintf('Trying to sumbit a non-job message, got error %s with message %s', $e->getMessage(), $message));
            return;
        }

        if (!$data instanceof JobInterface) {
            $this['monolog']->addError(sprintf('Trying to sumbit a non-job message : %s', $message));
            return;
        }

        if (!$this['stomp-client']->isConnected()) {
            $this['monolog']->addError(sprintf('STOMP server not yet connected'));
            return;
        }

        $this['stomp-client']->send(sprintf('/exchange/%s', RabbitMQConf::EXCHANGE_DISPATCHER), $data->toJson());
    }

    /**
     * {@inheritdoc}
     */
    public function incomingError(\Exception $error)
    {
        $this->logError($error);
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
        $factory = new StompFactory($loop);
        $client = $factory->createClient(array(
            'host'     => $conf['server']['host'],
            'port'     => $conf['server']['stomp-gateway']['port'],
            'user'     => $conf['server']['user'],
            'passcode' => $conf['server']['password'],
            'vhost'    => $conf['server']['vhost'],
        ));

        $websocketApp = new WebsocketApplication($logger);

        return new GloubsterServer($websocketApp, $client, $loop, $conf, $logger);
    }
}
