<?php

namespace App\Services;

use App\Models\Capsule;
use Aws\Sns\SnsClient;
use Illuminate\Support\Str;

/**
 * Publishes application events to the SNS topic timecapsule-dev-events (lab 7).
 *
 * The web request only publishes and answers at once; the slow "email" is sent by the
 * Lambda function timecapsule-dev-mailer from the SQS queue subscribed to the topic.
 * No access keys: the AWS SDK takes credentials from the EC2 instance role.
 * Event format: docs/events.md.
 */
class EventPublisher
{
    public function __construct(private ?SnsClient $client = null) {}

    public function capsuleSealed(Capsule $capsule, string $recipient): string
    {
        return $this->publish('capsule.sealed', [
            'capsule_id' => $capsule->id,
            'user_id' => $capsule->user_id,
            'title' => $capsule->title,
            'recipient' => $recipient,
            'open_at' => $capsule->open_at->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** Returns the event id. */
    private function publish(string $type, array $data): string
    {
        $eventId = (string) Str::uuid();
        $this->client()->publish([
            'TopicArn' => config('timecapsule.events_topic_arn'),
            'Message' => json_encode(['type' => $type, 'event_id' => $eventId,
                'occurred_at' => now()->format('Y-m-d\TH:i:s\Z')] + $data, JSON_UNESCAPED_UNICODE),
            'MessageAttributes' => [
                'event_type' => ['DataType' => 'String', 'StringValue' => $type],
            ],
        ]);

        return $eventId;
    }

    private function client(): SnsClient
    {
        return $this->client ??= new SnsClient([
            'version' => 'latest',
            'region' => config('timecapsule.aws_region'),
        ]);
    }
}
