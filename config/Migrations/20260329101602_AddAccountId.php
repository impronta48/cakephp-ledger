<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddAccountId extends BaseMigration
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
            $table->addColumn('account_id', 'integer', [
                'default' => null,
                'null' => true,
            ]);
            $table->update();
    }
}
