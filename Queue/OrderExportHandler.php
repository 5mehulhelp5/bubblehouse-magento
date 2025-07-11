<?php

declare(strict_types=1);

namespace BubbleHouse\Integration\Queue;

use BubbleHouse\Integration\Model\EportData\Order\OrderExtractor;
use BubbleHouse\Integration\Model\QueueLogFactory;
use BubbleHouse\Integration\Model\ResourceModel\QueueLog as QueueLogResource;
use BubbleHouse\Integration\Model\Services\Connector\BubbleHouseRequest;
use Exception;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class OrderExportHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly BubbleHouseRequest $bubbleHouseRequest,
        private readonly OrderExtractor $orderExtractor,
        private readonly QueueLogResource $resource,
        private readonly QueueLogFactory $queueLogFactory,
        private readonly SerializerInterface $serializer
    ) {
    }

    public function process(int $orderId): void
    {
        try {
            $order = $this->orderRepository->get($orderId);

            // Simulate sending order data to an external API
            $this->logger->info("Exporting order ID: " . $order->getIncrementId());
            $extractedData = $this->orderExtractor->extract($order, (bool)$order->getData('is_deleted'));
            $data = $this->serializer->serialize($extractedData);
            $queueLog = $this->queueLogFactory->create();
            $queueLog->setData(
                [
                    'message_type' => 'order',
                    'message_body' => $data,
                    'status' => 0
                ]
            );
            $this->resource->save($queueLog);

            $response = $this->bubbleHouseRequest->exportData(
                BubbleHouseRequest::ORDER_EXPORT_TYPE,
                $extractedData,
                $order->getStoreId()
            );

            if (!$response) {
                throw new LocalizedException(__('Failed to export order: ' . $orderId));
            }

            $queueLog->setStatus(1);
            $this->resource->save($queueLog);

        } catch (Exception $e) {
            $this->logger->error("Order Export Failed: " . $e->getMessage());
            throw new LocalizedException(__('Bubblehouse export failed'));
        }
    }
}
