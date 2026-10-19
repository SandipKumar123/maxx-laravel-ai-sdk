<?php

declare(strict_types=1);

namespace Maxx\Ai\Laravel;

use Illuminate\Console\Command;
use Maxx\Ai\Billing\BillingProcessor;
use Maxx\Ai\Exceptions\SdkException;
use Maxx\Ai\GovernanceClient;

final class PullBillingCommand extends Command
{
    protected $signature = 'maxx-ai:billing-pull {--limit=100} {--max-pages=10}';

    protected $description = 'Verify and process bounded pages of unacknowledged Maxx billing events';

    public function handle(GovernanceClient $client, BillingProcessor $processor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        $pages = filter_var($this->option('max-pages'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false || $pages === false) {
            $this->error('Use --limit=1..500 and --max-pages=1..100.');

            return self::INVALID;
        }
        $cursor = null;
        $processed = 0;
        $seen = [];
        for ($page = 0; $page < $pages; $page++) {
            $data = $client->billingEvents($cursor, $limit)->data;
            if (!isset($data['events']) || !is_array($data['events']) || !array_is_list($data['events'])
                || count($data['events']) > $limit || !array_key_exists('next_cursor', $data)
                || ($data['next_cursor'] !== null && !is_string($data['next_cursor']))) {
                throw new SdkException('Invalid billing page response.');
            }
            foreach ($data['events'] as $event) {
                if (!is_array($event) || !is_string($event['event_id'] ?? null) || !is_string($event['signed_event'] ?? null)) {
                    throw new SdkException('Invalid billing event response.');
                }
                $processor->process($event['signed_event'], $event['event_id']);
                $processed++;
            }
            $cursor = $data['next_cursor'];
            if ($cursor === null) {
                $this->info("Processed {$processed} billing deliveries.");

                return self::SUCCESS;
            }
            if (isset($seen[$cursor])) {
                throw new SdkException('Repeated billing cursor; polling stopped.');
            }
            $seen[$cursor] = true;
        }
        $this->warn("Processed {$processed} deliveries; page bound reached. Run again to drain remaining events.");

        return self::SUCCESS;
    }
}
