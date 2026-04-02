<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AlterLedgerEntriesAccountId extends BaseMigration
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
        $table->changeColumn('account_id', 'string', [
                'limit' => 50,
                'null' => true,
            ]);
        $table->addIndex(['account_id']);
        $table->update();
    }
}
