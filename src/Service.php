<?php

namespace Upswarm;

use Evenement\EventEmitter;
use React\Dns\Resolver\Factory as DnsResolver;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\SocketClient\Connector;
use React\Stream\Stream;
use React\ZMQ\Context;
use Upswarm\Instruction\Identify;
use Upswarm\Message;
use ZMQ;

/**
 * Upswarm service baseclass. Services that will be orchestrated by Upswarm
 * Supervisor should extend to this class.
 */
abstract class Service
{
    /**
     * Unique identifier
     * @var string
     */
    private $id;

    /**
     * Socket to comunicate with the Supervisor
     * @var \React\ZMQ\SocketWrapper
     */
    private $supervisorConnection;

    /**
     * ReactPHP loop.
     * @var LoopInterface
     */
    private $loop;

    /**
     * Service event emitter
     * @var EventEmitter
     */
    private $eventEmitter;

    /**
     * Controls the main 'while' loop that will make sure that the reactPHP
     * loop will keep running.
     * @var integer
     */
    private $exit = null;

    /**
     * Retrieves the ReactPHP event loop
     * @return LoopInterface
     */
    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    /**
     * Retrieves the EventEmitter of the service.
     * @return EventEmitter
     */
    public function getEventEmitter(): EventEmitter
    {
        return $this->eventEmitter;
    }

    /**
     * Retrieves the EventEmitter of the service.
     * @return \React\ZMQ\SocketWrapper
     */
    public function getSupervisorConnection(): \React\ZMQ\SocketWrapper
    {
        return $this->supervisorConnection;
    }

    /**
     * Retrieves the Service id
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Runs the service
     *
     * @param string  $host Supervisor host address.
     * @param integer $port Supervisor port.
     *
     * @return void
     */
    public function run(string $host = '127.0.0.1', int $port = 8300)
    {
        global $_serviceLoop;
        global $_service;

        $this->id            = uniqid();
        $this->eventEmitter  = new EventEmitter;
        $this->loop          = $_serviceLoop = Factory::create();
        $this->messageSender = new MessageSender($this);
        $_service            = $this;

        $this->registerMessageResponseCallback();
        $this->connectWithSupervisor($host, $port);

        while (null === $this->exit) {
            $this->loop->run();
        }

        exit($this->exit);
    }

    /**
     * Break the service loop and exit the service.
     *
     * @param  integer $code Exit code.
     *
     * @return void
     */
    public function exit(int $code = 0)
    {
        $this->exit = $code;

        if ($this->supervisorConnection) {
            $this->supervisorConnection->close();
        }

        $this->loop->nextTick(function () {
            $this->loop->stop();
        });
    }

    /**
     * Stabilish connection with the supervisor.
     *
     * @param string  $host Supervisor host address.
     * @param integer $port Supervisor port.
     *
     * @return void
     */
    private function connectWithSupervisor(string $host, int $port)
    {
        $zmqContext = new Context($this->loop);
        $this->supervisorConnection = $stream = $zmqContext->getSocket(ZMQ::SOCKET_SUB);

        $stream->connect("tcp://127.0.0.1:{$port}");
        $stream->subscribe($this->id);
        $stream->subscribe(static::class);
        $this->sendIdentificationMessage();

        // Register callback for incoming Messages
        $stream->on('messages', function ($data) {
            // list($recipientAddress, $senderAddress, $serializedMessage) = $data;
            $message = @unserialize($data[2]);

            if ($message instanceof Message) {
                $this->reactToIncomingMessage($message);
            }
        });

        // Stops loop (and exit service) if connection ends
        $stream->on('error', function ($e) {
            var_dump($e);
            echo $e->getMessage();
            $this->exit($e->getCode() ?: 3);
        });

        // Executes serve
        $this->loop->addTimer(1, function () {
            $this->serve($this->loop);
        });
    }

    /**
     * Registers the callback that will be triggered whenever a Message::respond
     * is called
     *
     * @return void
     */
    private function registerMessageResponseCallback()
    {
        $this->eventEmitter->on('respond', function (Message $message) {
            $this->sendMessage($message);
        });
    }

    /**
     * Sends identification Message to Supervisor.
     *
     * @return void
     */
    private function sendIdentificationMessage()
    {
        $this->sendMessage(
            new Message(new Identify(static::class, $this->getId()))
        );
    }

    /**
     * Sends message to Supervisor or to another service. The main inter process
     * comunication mechanism of Upswarm.
     *
     * @param  Message $message Message to be sent.
     *
     * @return Message
     */
    public function sendMessage(Message $message)
    {
        return $this->messageSender->sendMessage($message);
    }

    /**
     * React to an incoming message.
     *
     * @param  Message $message Incoming message.
     *
     * @return void
     */
    private function reactToIncomingMessage(Message $message)
    {
        if ($message->recipient) {
            $this->eventEmitter->emit($message->id, [$message]);
            $message->eventEmitter = $this->eventEmitter;
        }

        $this->handleMessage($message, $this->loop);
    }

    /**
     * Handles the messages that are received by this service.
     *
     * @param  Message       $message Incoming message.
     * @param  LoopInterface $loop    ReactPHP loop.
     *
     * @return void
     */
    public function handleMessage(Message $message, LoopInterface $loop)
    {
    }

    /**
     * Provide the given service. This is the initialization point of the
     * service, the initialization point of the service.
     *
     * @param  LoopInterface $loop ReactPHP loop.
     *
     * @return void
     */
    public function serve(LoopInterface $loop)
    {
    }
}
