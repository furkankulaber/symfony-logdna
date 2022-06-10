<?php
namespace furkankulaber\LogDnaBundle\Handler;

use furkankulaber\LogDnaBundle\Formatter\BasicJsonFormatter;
use Symfony\Component\HttpClient\HttpClient;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @link: https://docs.logdna.com/reference#logsingest
 */
class LogDnaHandler extends AbstractProcessingHandler
{
    public const LOGDNA_INGESTION_URL = 'https://logs.logdna.com/logs/ingest';
    public const LOGDNA_BYTE_LIMIT    = 30000;

    private $ingestionKey = '';
    private $hostName     = '';
    private $ipAddress    = '';
    private $macAddress   = '';
    private $tags         = [];
    private HttpClientInterface $client;
    private $lastResponse;
    private $lastBody;

    public function __construct(string $ingestionKey, string $hostName,  string $index = 'monolog', HttpClientInterface $client = null, $level = Logger::DEBUG, bool $bubble = true)
    {
        $this->ingestionKey = $ingestionKey;
        $this->hostName     = $hostName;

        parent::__construct($level, $bubble);

        $this->client = $client ?: HttpClient::create(['timeout' => 1]);
    }

    public function setIpAddress(string $ipAddress): void
    {
        $this->ipAddress = $ipAddress;
    }

    public function setMacAddress(string $macAddress): void
    {
        $this->macAddress = $macAddress;
    }

    public function setTags(array $tags): void
    {
        $this->tags = $tags;
    }

    private function getHttpClient()
    {
        return $this->client;
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new BasicJsonFormatter;
    }

    public function write(array $record)
    {
        $body = $record['formatted'];

        if (mb_strlen($body, '8bit') > static::LOGDNA_BYTE_LIMIT) {
            $decodedBody = json_decode($body, true);

            $body = json_encode([
                'lines' => [
                    [
                        'timestamp' => $decodedBody['lines'][0]['timestamp'] ?? '',
                        'line'      => $decodedBody['lines'][0]['line'] ?? '',
                        'app'       => $decodedBody['lines'][0]['app'] ?? '',
                        'level'     => $decodedBody['lines'][0]['level'] ?? '',
                        'meta'      => [
                            'longException' => mb_substr($body, 0, static::LOGDNA_BYTE_LIMIT, '8bit'),
                        ],
                    ],
                ],
            ]);
        }

        $this->lastBody = $body;

        $this->lastResponse = $this->getHttpClient()->request('POST', static::LOGDNA_INGESTION_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'apikey' => $this->ingestionKey
            ],
            'query' => [
                'hostname' => $this->hostName,
                'mac'      => $this->macAddress,
                'ip'       => $this->ipAddress,
                'now'      => $record['datetime']->getTimestamp(),
                'tags'     => $this->tags,
            ],
            'body' => $body,
        ]);

        return false === $this->bubble;
    }

    public function getLastResponse(): ResponseInterface
    {
        return $this->lastResponse;
    }

    public function getLastBody(): string
    {
        return $this->lastBody;
    }
}
