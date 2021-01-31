<?php

namespace palPalani\SqsQueueReader\Sqs;

use Aws\Exception\AwsException;
use Illuminate\Queue\Jobs\SqsJob;
use Illuminate\Queue\SqsQueue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use palPalani\SqsQueueReader\Jobs\DispatcherJob;

/**
 * Class CustomSqsQueue
 * @package App\Services
 */
class Queue extends SqsQueue
{
    /**
     * Create a payload string from the given job and data.
     *
     * @param  string  $job
     * @param  mixed   $data
     * @param  string  $queue
     * @return string
     */
    protected function createPayload($job, $data = '', $queue = null)
    {
        if (! $job instanceof DispatcherJob) {
            return parent::createPayload($job, $data, $queue);
        }

        $handlerJob = $this->getClass($queue) . '@handle';

        return $job->isPlain() ? json_encode($job->getPayload()) : json_encode([
            'job' => $handlerJob,
            'data' => $job->getPayload(),
        ]);
    }

    /**
     * @param $queue
     * @return string
     */
    private function getClass($queue = null): string
    {
        if (! $queue) {
            return Config::get('sqs-queue-reader.default-handler');
        }

        $queueId = explode('/', $queue);
        $queueId = array_pop($queueId);

        return (array_key_exists($queueId, Config::get('sqs-queue-reader.handlers')))
            ? Config::get('sqs-queue-reader.handlers')[$queueId]['class']
            : Config::get('sqs-queue-reader.default-handler')['class'];
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string  $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);

        $queueId = explode('/', $queue);
        $queueId = array_pop($queueId);

        $count = (array_key_exists($queueId, Config::get('sqs-queue-reader.handlers')))
            ? Config::get('sqs-queue-reader.handlers')[$queueId]['count']
            : Config::get('sqs-queue-reader.default-handler')['count'];

        try {
            $response = $this->sqs->receiveMessage([
                'QueueUrl' => $queue,
                'AttributeNames' => ['ApproximateReceiveCount'],
                'MaxNumberOfMessages' => $count,
                'MessageAttributeNames' => ['All'],
            ]);

            if (isset($response['Messages']) && count($response['Messages']) > 0) {
                $class = (array_key_exists($queueId, $this->container['config']->get('sqs-queue-reader.handlers')))
                    ? $this->container['config']->get('sqs-queue-reader.handlers')[$queueId]['class']
                    : $this->container['config']->get('sqs-queue-reader.default-handler')['class'];

                if ($count === 1) {
                    $response = $this->modifySinglePayload($response['Messages'][0], $class);
                } else {
                    $response = $this->modifyMultiplePayload($response['Messages'], $class);
                }

                return new SqsJob($this->container, $this->sqs, $response, $this->connectionName, $queue);
            }
        } catch (AwsException $e) {
            $msg = 'Line: '. $e->getLine() .', '. $e->getFile() . ', '. $e->getMessage();

            throw new \RuntimeException("Aws SQS error: " . $msg);
        }
    }

    /**
     * @param string|array $payload
     * @param string $class
     * @return array
     */
    private function modifySinglePayload($payload, $class)
    {
        if (! is_array($payload)) {
            $payload = json_decode($payload, true);
        }

        $body = json_decode($payload['Body'], true);

        $body = [
            'uuid' => (string) Str::uuid(),
            'job' => $class . '@handle',
            'data' => isset($body['data']) ? $body['data'] : $body,
        ];

        $payload['Body'] = json_encode($body);

        return $payload;
    }

    /**
     * @param string|array $payload
     * @param string $class
     * @return array
     */
    private function modifyMultiplePayload($payload, $class)
    {
        if (! is_array($payload)) {
            $payload = json_decode($payload, true);
        }

        $body = [];
        $attributes = [];

        foreach ($payload as $k => $item) {
            $body[$k] = [
                'messages' => json_decode($item['Body'], true),
                'attributes' => $item['Attributes'],
                'batchIds' => [
                    'Id' => $item['MessageId'],
                    'ReceiptHandle' => $item['ReceiptHandle'],
                ],
            ];
            $attributes = $item['Attributes'];
            $messageId = $item['MessageId'];
            $receiptHandle = $item['ReceiptHandle'];
        }

        $body = [
            'uuid' => (string) Str::uuid(),
            'job' => $class . '@handle',
            'data' => $body,
        ];

        return [
            'MessageId' => $messageId,
            'ReceiptHandle' => $receiptHandle,
            'Body' => json_encode($body),
            'Attributes' => $attributes,
        ];
    }

    /**
     * @param string $payload
     * @param null $queue
     * @param array $options
     * @return mixed|null
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $payload = json_decode($payload, true);

        if (isset($payload['data']) && isset($payload['job'])) {
            $payload = $payload['data'];
        }

        return parent::pushRaw(json_encode($payload), $queue, $options);
    }
}
