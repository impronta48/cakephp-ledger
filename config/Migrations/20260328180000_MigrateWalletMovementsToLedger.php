<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Migra tutti i record di contrasporto_walletmovements in ledger_entries.
 *
 * Mapping campi
 * ─────────────────────────────────────────────────────────────────────────────
 * wallet_movements.owner_id              → ledger_entries.user_id
 * wallet_movements.counterpart_id        → ledger_entries.counterparty_user_id
 * ABS(amount)                            → ledger_entries.amount
 * sign(amount)                           → ledger_entries.direction (>=0 IN, <0 OUT)
 * date + time                            → ledger_entries.created_at
 * card_id                                → reference_type='Card', reference_id=card_id
 * counterpart_movement_id                → pairs condividono lo stesso transfer_id (UUID)
 * description + source_id                → ledger_entries.metadata (JSON)
 *
 * Mapping reason
 * ─────────────────────────────────────────────────────────────────────────────
 * counterpart_movement_id IS NOT NULL           → TALENT_TRANSFER
 * card_id IS NOT NULL AND amount < 0            → TRIP_CONSUMED
 * card_id IS NOT NULL AND amount >= 0           → CARD_PURCHASED
 * amount > 0  (no card, no transfer)            → WALLET_RECHARGE
 * amount < 0  (no card, no transfer)            → TRIP_CONSUMED
 * default                                       → ADJUSTMENT
 */
class MigrateWalletMovementsToLedger extends BaseMigration
{
    public function up(): void
    {
        // ── 1. INSERT bulk ───────────────────────────────────────────────────
        $this->execute("
            INSERT INTO ledger_entries
                (user_id, counterparty_user_id, unit, amount, direction, reason,
                 transfer_id, reference_type, reference_id, metadata, created_at)
            SELECT
                wm.owner_id,
                wm.counterpart_id,
                'TALENT',
                ABS(COALESCE(wm.amount, 0)),
                CASE WHEN COALESCE(wm.amount, 0) >= 0 THEN 'IN' ELSE 'OUT' END,
                CASE
                    WHEN wm.counterpart_movement_id IS NOT NULL
                        THEN 'TALENT_TRANSFER'
                    WHEN wm.card_id IS NOT NULL AND wm.amount < 0
                        THEN 'TRIP_CONSUMED'
                    WHEN wm.card_id IS NOT NULL AND wm.amount >= 0
                        THEN 'CARD_PURCHASED'
                    WHEN COALESCE(wm.amount, 0) > 0
                        THEN 'WALLET_RECHARGE'
                    WHEN COALESCE(wm.amount, 0) < 0
                        THEN 'TRIP_CONSUMED'
                    ELSE 'ADJUSTMENT'
                END,
                NULL,
                CASE WHEN wm.card_id IS NOT NULL THEN 'Card' ELSE NULL END,
                wm.card_id,
                JSON_OBJECT(
                    'source_id',                       wm.id,
                    'source_counterpart_movement_id',  wm.counterpart_movement_id,
                    'description',                     wm.description
                ),
                CASE
                    WHEN wm.date IS NOT NULL AND wm.time IS NOT NULL
                        THEN CAST(CONCAT(wm.date, ' ', wm.time) AS DATETIME)
                    WHEN wm.date IS NOT NULL
                        THEN CAST(CONCAT(wm.date, ' 00:00:00') AS DATETIME)
                    ELSE NOW()
                END
            FROM contrasporto_walletmovements wm
        ");

        // ── 2. Assegna transfer_id UUID alle coppie collegate ────────────────
        //
        // Due righe sono una coppia quando:
        //   le1.source_counterpart_movement_id = le2.source_id
        //
        // Recuperiamo tutte le coppie in PHP, generiamo un UUID per coppia e
        // aggiorniamo entrambe le righe.
        $stmt = $this->query("
            SELECT le1.id AS id1, le2.id AS id2
            FROM ledger_entries le1
            JOIN ledger_entries le2
                ON CAST(
                       JSON_UNQUOTE(JSON_EXTRACT(le1.metadata, '$.source_counterpart_movement_id'))
                       AS UNSIGNED
                   ) = CAST(
                           JSON_UNQUOTE(JSON_EXTRACT(le2.metadata, '$.source_id'))
                           AS UNSIGNED
                       )
            WHERE JSON_EXTRACT(le1.metadata, '$.source_counterpart_movement_id') IS NOT NULL
              AND le1.id < le2.id
        ");

        $pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pairs as $pair) {
            $uuid = $this->uuid4();
            $id1  = (int)$pair['id1'];
            $id2  = (int)$pair['id2'];

            $this->execute(
                "UPDATE ledger_entries SET transfer_id = '{$uuid}' WHERE id IN ({$id1}, {$id2})"
            );
        }
    }

    public function down(): void
    {
        // Rimuove solo le righe importate da questa migration
        $this->execute("
            DELETE FROM ledger_entries
            WHERE JSON_EXTRACT(metadata, '$.source_id') IS NOT NULL
        ");
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function uuid4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
