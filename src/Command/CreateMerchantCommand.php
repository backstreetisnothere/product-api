<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Merchant;
use App\Security\ApiKeyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:merchant:create', description: 'Creates a merchant and prints its API key (shown only once).')]
final class CreateMerchantCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ApiKeyService $keys,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Merchant display name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $key = $this->keys->generate();
        $merchant = new Merchant((string) $input->getArgument('name'), $key->prefix, $key->hash);

        $this->em->persist($merchant);
        $this->em->flush();

        $io->success('Merchant created.');
        $io->definitionList(
            ['Merchant ID' => $merchant->getId()->toRfc4122()],
            ['API key' => $key->plain],
        );
        $io->warning('Store the API key now: only its hash is kept and it cannot be shown again.');

        return Command::SUCCESS;
    }
}
