<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Rimuove la colonna `direction` da ledger_entries incorporandone il
 * significato nel segno di `amount`:
 *   direction = 'OUT'  →  amount = -ABS(amount)
 *   direction = 'IN'   →  amount = +ABS(amount)   (invariato)
 */
class DropDirectionFromLedgerEntries extends BaseMigration
{
    public function up(): void
    {
        // 1. Porta i valori OUT a negativo
        $this->execute("
            UPDATE ledger_entries
            SET amount = -ABS(amount)
            WHERE direction = 'OUT'
        ");

        // 2. Rimuove la colonna
        $table = $this->table('ledger_entries');
        $table->removeColumn('direction')->update();
    }

    public function down(): void
    {
        // 1. Ri-aggiunge la colonna
        $table = $this->table('ledger_entries');
        $table->addColumn('direction', 'enum', [
            'values'  => ['IN', 'OUT'],
            'null'    => true,
            'default' => null,
        ])->update();

        // 2. Ricostruisce direction dal segno di amount
        $this->execute("
            UPDATE ledger_entries
            SET direction = CASE WHEN amount >= 0 THEN 'IN' ELSE 'OUT' END
        ");

        // 3. Riporta amount a valore assoluto per le righe OUT
        $this->execute("
            UPDATE ledger_entries
            SET amount = ABS(amount)
            WHERE direction = 'OUT'
        ");
    }
}
