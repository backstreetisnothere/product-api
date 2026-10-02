<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Merchant;
use App\Entity\Product;
use App\Entity\Warehouse;
use App\Repository\ProductPriceRepository;
use App\Repository\StockRepository;
use App\Security\ApiKeyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:demo:seed', description: 'Creates a demo merchant with synthetic warehouses, products, prices and stock.')]
final class SeedDemoDataCommand extends Command
{
    private const ADJECTIVES = ['Classic', 'Urban', 'Compact', 'Premium', 'Eco', 'Smart', 'Nordic', 'Vintage', 'Sport', 'Travel'];
    private const NOUNS = ['Backpack', 'Bottle', 'Jacket', 'Lamp', 'Notebook', 'Sneakers', 'Speaker', 'Watch', 'Wallet', 'Mug'];
    private const CITIES = ['Moscow', 'Saint Petersburg', 'Kazan', 'Novosibirsk', 'Yekaterinburg'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ApiKeyService $keys,
        private readonly ProductPriceRepository $prices,
        private readonly StockRepository $stock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('products', 'p', InputOption::VALUE_REQUIRED, 'Number of products to create', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $productCount = max(1, (int) $input->getOption('products'));

        $key = $this->keys->generate();
        $merchant = new Merchant('Demo Merchant '.bin2hex(random_bytes(2)), $key->prefix, $key->hash);
        $this->em->persist($merchant);

        $warehouses = [];
        foreach (\array_slice(self::CITIES, 0, 3) as $index => $city) {
            $warehouse = new Warehouse($merchant, \sprintf('WH-%02d', $index + 1), $city.' warehouse', $city);
            $this->em->persist($warehouse);
            $warehouses[] = $warehouse;
        }

        $products = [];
        for ($i = 1; $i <= $productCount; ++$i) {
            $name = self::ADJECTIVES[array_rand(self::ADJECTIVES)].' '.self::NOUNS[array_rand(self::NOUNS)];
            $product = new Product($merchant, \sprintf('DEMO-%05d', $i), $name, 'Synthetic demo product #'.$i);
            $this->em->persist($product);
            $products[] = $product;
        }

        $this->em->flush();

        $now = new \DateTimeImmutable();
        foreach ($products as $product) {
            $productId = $product->getId()->toRfc4122();
            $this->prices->upsert($productId, 'RUB', random_int(500, 500_000), $now);
            $this->prices->upsert($productId, 'USD', random_int(10, 5_000), $now);

            foreach ($warehouses as $warehouse) {
                $this->stock->setQuantity($productId, $warehouse->getId()->toRfc4122(), random_int(0, 500), $now);
            }
        }

        $io->success(\sprintf('Seeded %d products, %d warehouses.', $productCount, \count($warehouses)));
        $io->definitionList(
            ['Merchant ID' => $merchant->getId()->toRfc4122()],
            ['API key' => $key->plain],
        );

        return Command::SUCCESS;
    }
}
