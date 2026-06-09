<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddNewLedgerReasons extends BaseMigration
{
    public function change(): void
    {
        $this->table('ledger_entries')
            ->changeColumn('reason', 'enum', [
                'values' => [
                    // ── Legacy values – kept for backward compatibility ────────
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
                    // ── Canonical EUR values ──────────────────────────────────
                    'CARD_CHARGE',           // EUR: addebito acquisto abbonamento
                    'KM_REFUND',             // EUR: rimborso km previsionale a inizio mese
                    'KM_REFUND_REVERSAL',    // EUR: storno rimborso km per viaggi non effettuati
                    'SPONSOR_COVERAGE',      // EUR: sponsor copre il costo dell'utente sponsorizzato
                    // ── Riservato per futuri premi/talenti ────────────────────
                    'TALENT_EARNED',
                ],
                'null' => false,
            ])
            ->update();
    }
}
