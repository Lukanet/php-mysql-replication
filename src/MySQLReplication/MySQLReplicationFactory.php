<?php
declare(strict_types=1);

namespace MySQLReplication;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\DriverManager;
use MySQLReplication\BinaryDataReader\BinaryDataReaderException;
use MySQLReplication\BinLog\BinLogException;
use MySQLReplication\BinLog\BinLogServerInfo;
use MySQLReplication\BinLog\BinLogSocketConnect;
use MySQLReplication\Cache\ArrayCache;
use MySQLReplication\Config\Config;
use MySQLReplication\Config\ConfigException;
use MySQLReplication\Event\Event;
use MySQLReplication\Event\RowEvent\RowEventFactory;
use MySQLReplication\Exception\MySQLReplicationException;
use MySQLReplication\Gtid\GtidException;
use MySQLReplication\JsonBinaryDecoder\JsonBinaryDecoderException;
use MySQLReplication\Repository\MySQLRepository;
use MySQLReplication\Repository\RepositoryInterface;
use MySQLReplication\Socket\Socket;
use MySQLReplication\Socket\SocketException;
use MySQLReplication\Socket\SocketInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MySQLReplicationFactory
{
    private $connection;
    private $eventDispatcher;
    private $event;
    private $binLogServerConnect;
    /**
     * @var Config
     */
    private $config;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var SocketInterface
     */
    private $socket;

    /**
     * @throws BinLogException
     * @throws ConfigException
     * @throws Exception
     * @throws SocketException
     * @throws GtidException
     */
    public function __construct(
        Config $config,
        ?RepositoryInterface $repository = null,
        ?CacheInterface $cache = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?SocketInterface $socket = null,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $config->validate();

        if (null === $repository) {
            $this->connection = DriverManager::getConnection(
                [
                    'user' => $config->getUser(),
                    'password' => $config->getPassword(),
                    'host' => $config->getHost(),
                    'port' => $config->getPort(),
                    'driver' => 'pdo_mysql',
                    'charset' => $config->getCharset()
                ]
            );
            $repository = new MySQLRepository($this->connection);
        }
        if (null === $cache) {
            $cache = new ArrayCache($config->getTableCacheSize());
        }

        $this->eventDispatcher = $eventDispatcher ?: new EventDispatcher();

        if (null === $socket) {
            $socket = new Socket();
        }

        $this->binLogServerConnect = new BinLogSocketConnect(
            $config,
            $repository,
            $socket
        );

        $this->event = new Event(
            $config,
            $this->binLogServerConnect,
            new RowEventFactory(
                $config,
                $repository,
                $cache
            ),
            $this->eventDispatcher,
            $cache
        );

        $this->socket = $socket;
        $this->connect();
    }

    public function connect()
    {
        $this->event->connect();
        $this->logger->info("[MysqlReplication] Connected.");
    }

    public function getBinLogServerInfo(): BinLogServerInfo
    {
        return $this->binLogServerConnect->getBinLogServerInfo();
    }

    public function registerSubscriber(EventSubscriberInterface $eventSubscribers): void
    {
        $this->eventDispatcher->addSubscriber($eventSubscribers);
    }

    public function unregisterSubscriber(EventSubscriberInterface $eventSubscribers): void
    {
        $this->eventDispatcher->removeSubscriber($eventSubscribers);
    }

    public function getDbConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * @throws SocketException
     * @throws JsonBinaryDecoderException
     * @throws BinaryDataReaderException
     * @throws BinLogException
     * @throws InvalidArgumentException
     * @throws MySQLReplicationException
     */
    public function run(): void
    {
        $retryNum = $this->config->getRetry();
        while (1) {
            if (! $this->socket->isConnected()) {
                try {
                    if ($retryNum) {
                        $retryNum--;
                        $this->connect();
                        $retryNum = $this->config->getRetry();
                    } else {
                        break;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning("[MysqlReplication] Connect error:" . $e->getMessage(), ['exception' => $e]);
                    $this->socket->close();
                    sleep(1);
                    continue;
                }
            }

            try {
                while (1) {
                    $this->consume();
                }
            } catch (SocketException $e) {
                $this->logger->warning("[MysqlReplication] Connection lost: " . $e->getMessage(), ['exception' => $e]);
                $this->socket->close();
            }
        }
    }

    /**
     * @throws MySQLReplicationException
     * @throws InvalidArgumentException
     * @throws BinLogException
     * @throws BinaryDataReaderException
     * @throws JsonBinaryDecoderException
     * @throws SocketException
     */
    public function consume(): void
    {
        $this->event->consume();
    }
}
