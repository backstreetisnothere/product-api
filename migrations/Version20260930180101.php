<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930180101 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: merchants, warehouses, products, prices, stock levels.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE merchants (
              id UUID NOT NULL,
              name VARCHAR(120) NOT NULL,
              api_key_prefix VARCHAR(16) NOT NULL,
              api_key_hash VARCHAR(64) NOT NULL,
              active BOOLEAN NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_merchants_api_key_prefix ON merchants (api_key_prefix)');
        $this->addSql(<<<'SQL'
            CREATE TABLE product_prices (
              id UUID NOT NULL,
              currency CHAR(3) NOT NULL,
              amount INT NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              product_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_product_prices_product_currency ON product_prices (product_id, currency)
        SQL);
        $this->addSql('CREATE INDEX IDX_86B72CFD4584665A ON product_prices (product_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE products (
              id UUID NOT NULL,
              sku VARCHAR(64) NOT NULL,
              name VARCHAR(255) NOT NULL,
              description TEXT DEFAULT NULL,
              active BOOLEAN NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              merchant_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_products_merchant_created ON products (merchant_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_products_merchant_sku ON products (merchant_id, sku)');
        $this->addSql('CREATE INDEX IDX_B3BA5A5A6796D554 ON products (merchant_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE stock_levels (
              id UUID NOT NULL,
              quantity INT NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              product_id UUID NOT NULL,
              warehouse_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_stock_levels_warehouse ON stock_levels (warehouse_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_stock_levels_product_warehouse ON stock_levels (product_id, warehouse_id)
        SQL);
        $this->addSql('CREATE INDEX IDX_550CB68F4584665A ON stock_levels (product_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE warehouses (
              id UUID NOT NULL,
              code VARCHAR(32) NOT NULL,
              name VARCHAR(120) NOT NULL,
              city VARCHAR(120) DEFAULT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              merchant_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_warehouses_merchant_code ON warehouses (merchant_id, code)');
        $this->addSql('CREATE INDEX IDX_AFE9C2B76796D554 ON warehouses (merchant_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              product_prices
            ADD
              CONSTRAINT FK_86B72CFD4584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              products
            ADD
              CONSTRAINT FK_B3BA5A5A6796D554 FOREIGN KEY (merchant_id) REFERENCES merchants (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              stock_levels
            ADD
              CONSTRAINT FK_550CB68F4584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              stock_levels
            ADD
              CONSTRAINT FK_550CB68F5080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              warehouses
            ADD
              CONSTRAINT FK_AFE9C2B76796D554 FOREIGN KEY (merchant_id) REFERENCES merchants (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);

        // Invariants enforced by the database itself, the last line of defence against overselling
        // and corrupt prices even if application code is bypassed. Keep in sync with StockRepository::MAX_QUANTITY.
        $this->addSql('ALTER TABLE stock_levels ADD CONSTRAINT chk_stock_levels_quantity CHECK (quantity >= 0 AND quantity <= 1000000000)');
        $this->addSql('ALTER TABLE product_prices ADD CONSTRAINT chk_product_prices_amount CHECK (amount >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_prices DROP CONSTRAINT FK_86B72CFD4584665A');
        $this->addSql('ALTER TABLE products DROP CONSTRAINT FK_B3BA5A5A6796D554');
        $this->addSql('ALTER TABLE stock_levels DROP CONSTRAINT FK_550CB68F4584665A');
        $this->addSql('ALTER TABLE stock_levels DROP CONSTRAINT FK_550CB68F5080ECDE');
        $this->addSql('ALTER TABLE warehouses DROP CONSTRAINT FK_AFE9C2B76796D554');
        $this->addSql('DROP TABLE merchants');
        $this->addSql('DROP TABLE product_prices');
        $this->addSql('DROP TABLE products');
        $this->addSql('DROP TABLE stock_levels');
        $this->addSql('DROP TABLE warehouses');
    }
}
