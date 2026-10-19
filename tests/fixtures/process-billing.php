<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\ConnectionInterface;
use Maxx\Ai\Billing\BillingEvent;
use Maxx\Ai\Billing\BillingProcessor;
use Maxx\Ai\Billing\BillingVerifier;
use Maxx\Ai\Billing\InvoiceHandler;
use Maxx\Ai\GovernanceClient;
use Maxx\Ai\Testing\FakeTransport;

[$script, $database, $publicKeyFile, $eventFile] = $argv;
$capsule = new Manager();
$capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'busy_timeout' => 10000]);
$connection = $capsule->getConnection();
$handler = new class($connection) implements InvoiceHandler {
    public function __construct(private ConnectionInterface $database) {}

    public function handle(BillingEvent $event): string
    {
        usleep(150000);
        $reference = 'invoice-'.$event->eventId;
        $this->database->table('test_invoice_lines')->insert(['event_id' => $event->eventId, 'reference' => $reference]);

        return $reference;
    }
};
$processor = new BillingProcessor(
    $connection,
    new BillingVerifier(['current' => file_get_contents($publicKeyFile)], 'customer-1', 'hrms-local'),
    $handler,
    new GovernanceClient((new FakeTransport())->enqueue(['acknowledged' => true])),
);
echo $processor->process(file_get_contents($eventFile));
