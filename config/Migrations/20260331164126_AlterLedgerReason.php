<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AlterLedgerReason extends BaseMigration
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
            $table->changeColumn('reason', 'enum', [
                'values' => [ 'TRIP_CONSUMED','CARD_PURCHASED','CARD_EXPIRE','WALLET_RECHARGE','PAYMENT','TALENT_TRANSFER','ADJUSTMENT', 'PAYMENT_DUE'],
                'null' => false,
            ]);
        $table->update();
    }
}
