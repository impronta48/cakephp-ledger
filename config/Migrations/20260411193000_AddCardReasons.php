<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddCardReasons extends BaseMigration
{
    public function change(): void
    {
        $this->table('ledger_entries')
            ->changeColumn('reason', 'enum', [
                'values' => [
                    'TRIP_CONSUMED',
                    'CARD_PURCHASED',
                    'CARD_EXPIRE',
                    'WALLET_RECHARGE',
                    'PAYMENT_DUE',
                    'PAYMENT',
                    'TALENT_TRANSFER',
                    'ADJUSTMENT',
                    'REFUND',
                    'WELCOME_BONUS',
                ],
                'null' => false,
            ])
            ->update();
    }
}
