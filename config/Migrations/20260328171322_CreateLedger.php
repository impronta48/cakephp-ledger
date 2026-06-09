<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateLedger extends BaseMigration
{
    /**
     * Change Method.
     *
     * More information on this method is available here:
     * https://book.cakephp.org/migrations/4/en/migrations.html#the-change-method
     *
     * @return void
     */
    public function change(): void
    {
        $table = $this->table('ledger_entries');

        $table
            ->addColumn('user_id', 'integer', [
                'null' => false,
            ])
            ->addColumn('counterparty_user_id', 'integer', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('unit', 'enum', [
                'values' => ['TALENT', 'EUR'],
                'null' => false,
            ])
            ->addColumn('amount', 'decimal', [
                'precision' => 10,
                'scale' => 4,
                'null' => false,
                'default' => '0.0000',
            ])
            ->addColumn('direction', 'enum', [
                'values' => ['IN', 'OUT'],
                'null' => true,
                'default' => null,
                'comment' => 'opzionale, derivabile da amount',
            ])
            ->addColumn('reason', 'enum', [
                'values' => [
                    'TRIP_CONSUMED',
                    'CARD_PURCHASED',
                    'WALLET_RECHARGE',
                    'PAYMENT',
                    'TALENT_TRANSFER',
                    'ADJUSTMENT',
                    'PAYMENT_DUE',
                ],
                'null' => false,
            ])
            ->addColumn('transfer_id', 'uuid', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('reference_type', 'string', [
                'limit' => 50,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('reference_id', 'integer', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('metadata', 'json', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addColumn('description', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
            ])
            ->addIndex(['user_id'])
            ->addIndex(['counterparty_user_id'])
            ->addIndex(['transfer_id'])
            ->addIndex(['reference_type', 'reference_id'])
            ->create();
    }
}
