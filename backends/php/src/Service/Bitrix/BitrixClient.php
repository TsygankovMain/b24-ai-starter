<?php

namespace App\Service\Bitrix;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Log\LoggerInterface;

class BitrixClient
{
    private HttpClientInterface $client;
    private string $webhookUrl;
    private LoggerInterface $logger;

    public function __construct(
        HttpClientInterface $client,
        #[Autowire(env: 'BITRIX_WEBHOOK_URL')] string $webhookUrl,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->webhookUrl = $webhookUrl;
        $this->logger = $logger;
    }

    /**
     * Fetch items from Smart Process (entityTypeId: 1164) with pagination.
     *
     * @param array $filter
     * @param int $limit Max records to fetch (default 1500)
     * @return array
     */
    public function fetchSmartProcessItems(array $filter = [], int $limit = 1500): array
    {
        $items = [];
        $start = 0;
        $batchSize = 50;

        while (count($items) < $limit) {
            $response = $this->call('crm.item.list', [
                'entityTypeId' => 1164,
                'filter' => $filter,
                'start' => $start,
                'limit' => $batchSize,
            ]);

            if (empty($response['result']['items'])) {
                break;
            }

            $fetchedItems = $response['result']['items'];
            $items = array_merge($items, $fetchedItems);

            // If we received fewer items than requested, it means we reached the end
            if (count($fetchedItems) < $batchSize) {
                break;
            }

            $start += count($fetchedItems);
            
            // Safety break to avoid infinite loops if something goes wrong
            if ($start >= $limit) {
                break;
            }
        }

        // Trim to limit if we over-fetched
        return array_slice($items, 0, $limit);
    }

    /**
     * Generic method to call Bitrix24 API
     */
    public function call(string $method, array $params = []): array
    {
        try {
            $response = $this->client->request('POST', $this->webhookUrl . $method, [
                'json' => $params,
            ]);

            $data = $response->toArray();

            if (isset($data['error'])) {
                throw new \RuntimeException('Bitrix24 API Error: ' . ($data['error_description'] ?? $data['error']));
            }

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Bitrix24 API Request Failed', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
