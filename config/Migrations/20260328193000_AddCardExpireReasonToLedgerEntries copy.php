<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddCardExpireReasonToLedgerEntries extends BaseMigration
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
                    'PAYMENT',
                    'TALENT_TRANSFER',
                    'ADJUSTMENT',
                ],
                'null' => false,
            ])
            ->update();
    }
}
