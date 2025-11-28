<?php

namespace App\Command;

use App\Service\Bitrix\BitrixClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:bitrix:test-connection',
    description: 'Tests the connection to Bitrix24 API',
)]
class TestBitrixConnectionCommand extends Command
{
    private BitrixClient $bitrixClient;

    public function __construct(BitrixClient $bitrixClient)
    {
        parent::__construct();
        $this->bitrixClient = $bitrixClient;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $io->title('Testing Bitrix24 Connection...');
            
            // Try to fetch 1 item just to check connection
            $items = $this->bitrixClient->fetchSmartProcessItems([], 1);

            $io->success(sprintf('Connection successful! Fetched %d item(s).', count($items)));
            
            if (count($items) > 0) {
                $io->note('First item ID: ' . $items[0]['id']);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Connection failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
