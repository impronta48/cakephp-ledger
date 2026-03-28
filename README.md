# Ledger Plugin

CakePHP plugin for ledger management (TALENT and EUR accounts).

## Features

- `ledger_entries` with transaction history
- double-entry transfers with `transfer_id`
- free ledger accounts (`reference_type IS NULL`) and card-linked accounts (`reference_type = 'Card'`)
- balance and usage operations through `LedgerService`

## Main methods (LedgerService)

- `addEntry(array $data)`
- `addTransfer(array $entries)`
- `transferTalents(int $fromUser, int $toUser, float $amount, array $meta = [])`
- `getWallet(int $userId)`
- `reserveForCard(int $userId, int $cardId, float $amount, array $meta = [])`
- `consumeFromCard(int $userId, int $cardId, float $amount, array $meta = [])`
- `expireCard(int $userId, int $cardId, array $meta = [])`

## Migrations

- `CreateLedger` creates the `ledger_entries` table
- `MigrateWalletMovementsToLedger` imports data from `contrasporto_walletmovements`
- `DropDirectionFromLedgerEntries` removes `direction` and normalizes `amount`

## Tests

Run `composer test`.

## Credits

Developed by Massimo Infunti (@impronta48).