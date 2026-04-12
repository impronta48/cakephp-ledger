<?php

declare(strict_types=1);

use Migrations\BaseMigration;
use Ledger\Model\Table\LedgerEntriesTable;

class AddCardExpireReasonToLedgerEntries extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('ledger_entries');
        $table->changeColumn('reason', 'enum', [
            'values' => [
                LedgerEntriesTable::REASON_TRIP_CONSUMED,
                LedgerEntriesTable::REASON_CARD_PURCHASED,
                LedgerEntriesTable::REASON_CARD_EXPIRE,
                LedgerEntriesTable::REASON_WALLET_RECHARGE,
                LedgerEntriesTable::REASON_PAYMENT,
                LedgerEntriesTable::REASON_TALENT_TRANSFER,
                LedgerEntriesTable::REASON_ADJUSTMENT,
            ],
            'null' => false,
        ])->update();
    }
}
