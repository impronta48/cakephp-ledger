<?php
declare(strict_types=1);

namespace Ledger\Service;

use Cake\Database\Connection;
use Cake\Datasource\FactoryLocator;
use Cake\Datasource\ConnectionManager;
use Cake\Utility\Text;
use Ledger\Model\Table\LedgerEntriesTable;

class LedgerService
{
    /** @var LedgerEntriesTable */
    protected LedgerEntriesTable $Ledger;

    public function __construct()
    {
        /** @var LedgerEntriesTable $table */
        $table = FactoryLocator::get('Table')->get('Ledger.LedgerEntries');
        $this->Ledger = $table;
    }

    // ── Primitives ─────────────────────────────────────────────────────────

    public function addEntry(array $data)
    {
        $entry = $this->Ledger->newEntity($data);
        return $this->Ledger->saveOrFail($entry);
    }

    /**
     * Transazione double-entry atomica. Restituisce il transfer_id.
     */
    public function addTransfer(array $entries): string
    {
        /** @var Connection $connection */
        $connection = ConnectionManager::get('default');
        $transferId = Text::uuid();

        $connection->transactional(function () use ($entries, $transferId) {
            foreach ($entries as $entryData) {
                $entryData['transfer_id'] = $transferId;
                $customCreated = $entryData['created'] ?? null;
                unset($entryData['created']); // TimestampBehavior sovrascrive sempre created; usiamo updateAll dopo
                $entry = $this->Ledger->newEntity($entryData);
                try {
                    $this->Ledger->saveOrFail($entry);
                    if ($customCreated !== null) {
                        $this->Ledger->updateAll(['created' => $customCreated], ['id' => $entry->id]);
                    }
                } catch (\Exception $e) {
                    throw new \RuntimeException('Failed to save ledger entry: ' . $e->getMessage(), 0, $e);
                }
            }
        });

        return $transferId;
    }

    // ── Card ───────────────────────────────────────────────────────────────

    /**
     * Registra l'acquisto di un abbonamento (double-entry EUR).
     *
     * Se sponsorPersonaId è null → utente normale:
     *   user:{ownerId}         -cost  CARD_CHARGE
     *   system:receivables     +cost  CARD_CHARGE
     *
     * Se sponsorPersonaId è impostato → utente sponsorizzato:
     *   sponsor:{sponsorId}    -cost  SPONSOR_COVERAGE
     *   system:receivables     +cost  SPONSOR_COVERAGE
     *
     * @param int      $ownerId          persona_id del proprietario
     * @param int      $cardId
     * @param float    $cost             importo EUR
     * @param string   $cardTypeSlug
     * @param int|null $payinId
     * @param int|null $sponsorPersonaId persona_id dello sponsor (null = nessuno)
     * @param array    $meta
     */
    public function recordCardPurchase(
        int $ownerId,
        int $cardId,
        float $cost,
        string $cardTypeSlug,
        ?int $payinId = null,
        ?int $sponsorPersonaId = null,
        array $meta = [],
        ?string $created = null
    ): string {
        $meta        = array_merge(['card_type' => $cardTypeSlug, 'origin' => 'ledger_service'], $meta);
        $isSponsored = $sponsorPersonaId !== null;
        $accountId   = $isSponsored ? 'sponsor:' . $sponsorPersonaId : 'user:' . $ownerId;
        $reason      = $isSponsored
            ? LedgerEntriesTable::REASON_SPONSOR_COVERAGE
            : LedgerEntriesTable::REASON_CARD_CHARGE;

        $ikeySuffix = $isSponsored ? "sponsor:{$sponsorPersonaId}" : "user:{$ownerId}";

        $entry1 = [
            'account_id'      => $accountId,
            'user_id'         => $ownerId,
            'unit'            => LedgerEntriesTable::UNIT_EUR,
            'amount'          => -$cost,
            'reason'          => $reason,
            'reference_type'  => $payinId !== null ? LedgerEntriesTable::REF_PAYIN : LedgerEntriesTable::REF_CARD,
            'reference_id'    => $payinId ?? $cardId,
            'description'     => "Addebito card #{$cardId} ({$cardTypeSlug})" . ($isSponsored ? " – sponsor #{$sponsorPersonaId}" : ''),
            'metadata'        => $meta,
            'idempotency_key' => "{$ikeySuffix}:card:{$cardId}:charge",
        ];
        $entry2 = [
            'account_id'      => 'system:receivables',
            'user_id'         => $ownerId,
            'unit'            => LedgerEntriesTable::UNIT_EUR,
            'amount'          => $cost,
            'reason'          => $reason,
            'reference_type'  => $payinId !== null ? LedgerEntriesTable::REF_PAYIN : LedgerEntriesTable::REF_CARD,
            'reference_id'    => $payinId ?? $cardId,
            'description'     => "Credito card #{$cardId} ({$cardTypeSlug})" . ($isSponsored ? " – sponsor #{$sponsorPersonaId}" : ''),
            'metadata'        => $meta,
            'idempotency_key' => "system:receivables:card:{$cardId}:charge",
        ];
        if ($created !== null) {
            $entry1['created'] = $created;
            $entry2['created'] = $created;
        }

        return $this->addTransfer([$entry1, $entry2]);
    }

    /**
     * Rettifica EUR di un abbonamento già registrato (dopo edit della card).
     * Scrive delta-entry bilanciate solo se il delta è significativo.
     *
     * @param int      $ownerId
     * @param int      $cardId
     * @param float    $deltaCost        differenza EUR (nuovo - vecchio), può essere negativa
     * @param int|null $payinId
     * @param int|null $sponsorPersonaId
     * @param array    $meta
     */
    public function adjustCardPurchase(
        int $ownerId,
        int $cardId,
        float $deltaCost,
        ?int $payinId = null,
        ?int $sponsorPersonaId = null,
        array $meta = [],
        ?string $created = null
    ): ?string {
        if (abs($deltaCost) <= 0.001) {
            return null;
        }

        $ts          = (string)microtime(true);
        $meta        = array_merge(['origin' => 'ledger_service_adjust', 'delta' => $deltaCost], $meta);
        $isSponsored = $sponsorPersonaId !== null;
        $accountId   = $isSponsored ? 'sponsor:' . $sponsorPersonaId : 'user:' . $ownerId;
        $ikeySuffix  = $isSponsored ? "sponsor:{$sponsorPersonaId}" : "user:{$ownerId}";
        $refType     = $payinId !== null ? LedgerEntriesTable::REF_PAYIN : LedgerEntriesTable::REF_CARD;
        $refId       = $payinId ?? $cardId;

        $entry1 = [
            'account_id'      => $accountId,
            'user_id'         => $ownerId,
            'unit'            => LedgerEntriesTable::UNIT_EUR,
            'amount'          => -$deltaCost,
            'reason'          => LedgerEntriesTable::REASON_ADJUSTMENT,
            'reference_type'  => $refType,
            'reference_id'    => $refId,
            'description'     => "Rettifica importo card #{$cardId}",
            'metadata'        => $meta,
            'idempotency_key' => "adj:{$ikeySuffix}:card:{$cardId}:{$ts}",
        ];
        $entry2 = [
            'account_id'      => 'system:receivables',
            'user_id'         => $ownerId,
            'unit'            => LedgerEntriesTable::UNIT_EUR,
            'amount'          => $deltaCost,
            'reason'          => LedgerEntriesTable::REASON_ADJUSTMENT,
            'reference_type'  => $refType,
            'reference_id'    => $refId,
            'description'     => "Rettifica importo card #{$cardId}",
            'metadata'        => $meta,
            'idempotency_key' => "adj:system:receivables:card:{$cardId}:{$ts}",
        ];
        if ($created !== null) {
            $entry1['created'] = $created;
            $entry2['created'] = $created;
        }

        return $this->addTransfer([$entry1, $entry2]);
    }

    // ── KM Refund (EUR) ────────────────────────────────────────────────────

    /**
     * Sincronizza il movimento KM_REFUND per una card nel ledger.
     *
     * Il socio PAGA i km a conTrasporto:
     *   user:{userId}    -delta  KM_REFUND  (costo al socio)
     *   system:km_pool   +delta  KM_REFUND  (incasso km)
     *
     * Se il movimento non esiste viene creato con chiave idempotente stabile.
     * Se esiste ma l'importo è cambiato viene aggiunta una delta-entry.
     *
     * @param int         $userId        persona_id del proprietario
     * @param int         $cardId
     * @param float       $targetAmount  importo KM_REFUND desiderato (positivo)
     * @param string|null $created       data del movimento 'YYYY-MM-DD', default oggi
     */
    public function syncKmRefund(
        int $userId,
        int $cardId,
        float $targetAmount,
        ?string $created = null
    ): ?string {
        if ($targetAmount <= 0.001) {
            return null;
        }

        $month = $created ? substr($created, 0, 7) : date('Y-m');

        $existingResult = $this->Ledger->find()
            ->where([
                'user_id'        => $userId,
                'unit'           => LedgerEntriesTable::UNIT_EUR,
                'reason'         => LedgerEntriesTable::REASON_KM_REFUND,
                'reference_type' => LedgerEntriesTable::REF_CARD,
                'reference_id'   => $cardId,
                'account_id'     => 'user:' . $userId,
            ])
            ->select(['total' => 'SUM(amount)'])
            ->first();
        $existing = abs((float)($existingResult?->get('total') ?? 0.0));

        $delta = round($targetAmount - $existing, 2);
        if (abs($delta) <= 0.001) {
            return null;
        }

        $isNew  = $existing < 0.001;
        $tsSfx  = $isNew ? '' : ':adj:' . microtime(true);
        $entry1 = [
            'account_id'      => 'user:' . $userId,
            'user_id'         => $userId,
            'unit'            => LedgerEntriesTable::UNIT_EUR,
            'amount'          => -$delta,
            'reason'          => LedgerEntriesTable::REASON_KM_REFUND,
            'reference_type'  => LedgerEntriesTable::REF_CARD,
            'reference_id'    => $cardId,
            'description'     => $isNew
                ? "Costo km abbonamento #{$cardId}"
                : "Rettifica costo km abbonamento #{$cardId}",
            'metadata'        => ['origin' => 'ledger_service_sync_km'],
            'idempotency_key' => "km_refund:{$userId}:card:{$cardId}:{$month}{$tsSfx}",
        ];
        $entry2 = [
            'account_id'      => 'system:km_pool',
            'user_id'         => $userId,
            'unit'            => LedgerEntriesTable::UNIT_EUR,
            'amount'          => $delta,
            'reason'          => LedgerEntriesTable::REASON_KM_REFUND,
            'reference_type'  => LedgerEntriesTable::REF_CARD,
            'reference_id'    => $cardId,
            'description'     => $isNew
                ? "Pool km abbonamento #{$cardId}"
                : "Rettifica pool km abbonamento #{$cardId}",
            'metadata'        => ['origin' => 'ledger_service_sync_km'],
            'idempotency_key' => "system:km_pool:card:{$cardId}:{$userId}:{$month}{$tsSfx}",
        ];
        if ($created !== null) {
            $entry1['created'] = $created;
            $entry2['created'] = $created;
        }

        return $this->addTransfer([$entry1, $entry2]);
    }

    /**
     * Accredita il rimborso km previsionale a inizio mese per N viaggi (EUR).
     *
     * user:{userId}    +amount  KM_REFUND   (la piattaforma accredita)
     * system:km_pool   -amount  KM_REFUND   (pool rimborsi)
     *
     * Idempotente per (userId, cardId, month): una sola emissione per mese.
     *
     * @param int      $userId         persona_id dell'utente
     * @param int      $nTrips         viaggi previsti
     * @param float    $refundPerTrip  rimborso EUR per viaggio
     * @param string   $month          formato 'YYYY-MM'
     * @param int|null $cardId         card di riferimento
     */
    public function recordKmRefundForecast(
        int $userId,
        int $nTrips,
        float $refundPerTrip,
        string $month,
        ?int $cardId = null
    ): string {
        $amount     = round($nTrips * $refundPerTrip, 2);
        $cardSuffix = $cardId ? ":card:{$cardId}" : '';
        $meta       = [
            'month'           => $month,
            'n_trips'         => $nTrips,
            'refund_per_trip' => $refundPerTrip,
        ];

        return $this->addTransfer([
            [
                'account_id'      => 'user:' . $userId,
                'user_id'         => $userId,
                'unit'            => LedgerEntriesTable::UNIT_EUR,
                'amount'          => $amount,
                'reason'          => LedgerEntriesTable::REASON_KM_REFUND,
                'reference_type'  => $cardId ? LedgerEntriesTable::REF_CARD : null,
                'reference_id'    => $cardId,
                'description'     => "Rimborso km previsionale {$nTrips} viaggi – {$month}",
                'metadata'        => $meta,
                'idempotency_key' => "km_refund:{$userId}{$cardSuffix}:{$month}",
            ],
            [
                'account_id'      => 'system:km_pool',
                'user_id'         => $userId,
                'unit'            => LedgerEntriesTable::UNIT_EUR,
                'amount'          => -$amount,
                'reason'          => LedgerEntriesTable::REASON_KM_REFUND,
                'reference_type'  => $cardId ? LedgerEntriesTable::REF_CARD : null,
                'reference_id'    => $cardId,
                'description'     => "Pool rimborso km {$nTrips} viaggi – {$month}",
                'metadata'        => $meta,
                'idempotency_key' => "system:km_pool{$cardSuffix}:{$userId}:{$month}",
            ],
        ]);
    }

    /**
     * Storna il rimborso km per Y viaggi non effettuati a fine mese (EUR).
     *
     * @param int      $userId
     * @param int      $nUnusedTrips   viaggi non effettuati
     * @param float    $refundPerTrip  rimborso EUR per viaggio
     * @param string   $month          formato 'YYYY-MM'
     * @param int|null $cardId
     */
    public function reverseKmRefundForecast(
        int $userId,
        int $nUnusedTrips,
        float $refundPerTrip,
        string $month,
        ?int $cardId = null
    ): ?string {
        if ($nUnusedTrips <= 0) {
            return null;
        }

        $amount     = round($nUnusedTrips * $refundPerTrip, 2);
        $cardSuffix = $cardId ? ":card:{$cardId}" : '';
        $ts         = (string)microtime(true);
        $meta       = [
            'month'           => $month,
            'n_unused_trips'  => $nUnusedTrips,
            'refund_per_trip' => $refundPerTrip,
        ];

        return $this->addTransfer([
            [
                'account_id'      => 'user:' . $userId,
                'user_id'         => $userId,
                'unit'            => LedgerEntriesTable::UNIT_EUR,
                'amount'          => -$amount,
                'reason'          => LedgerEntriesTable::REASON_KM_REFUND_REVERSAL,
                'reference_type'  => $cardId ? LedgerEntriesTable::REF_CARD : null,
                'reference_id'    => $cardId,
                'description'     => "Storno rimborso km {$nUnusedTrips} viaggi non effettuati – {$month}",
                'metadata'        => $meta,
                'idempotency_key' => "km_refund_rev:{$userId}{$cardSuffix}:{$month}:{$ts}",
            ],
            [
                'account_id'      => 'system:km_pool',
                'user_id'         => $userId,
                'unit'            => LedgerEntriesTable::UNIT_EUR,
                'amount'          => $amount,
                'reason'          => LedgerEntriesTable::REASON_KM_REFUND_REVERSAL,
                'reference_type'  => $cardId ? LedgerEntriesTable::REF_CARD : null,
                'reference_id'    => $cardId,
                'description'     => "Recupero pool km {$nUnusedTrips} viaggi – {$month}",
                'metadata'        => $meta,
                'idempotency_key' => "system:km_pool{$cardSuffix}:{$userId}:{$month}:rev:{$ts}",
            ],
        ]);
    }

    // ── Sponsor ────────────────────────────────────────────────────────────

    /**
     * Deposita budget EUR sul conto dello sponsor (operazione admin).
     *
     * sponsor:{id}        +amount  ADJUSTMENT
     * system:receivables  -amount  ADJUSTMENT
     *
     * @param int    $sponsorPersonaId  persona_id del socio sponsor
     * @param float  $amount            importo positivo
     * @param array  $meta
     * @param string $description
     */
    public function depositSponsorBudget(
        int $sponsorPersonaId,
        float $amount,
        array $meta = [],
        string $description = ''
    ): string {
        $ts   = (string)microtime(true);
        $meta = array_merge(['origin' => 'admin_deposit'], $meta);

        return $this->addTransfer([
            [
                'account_id'      => 'sponsor:' . $sponsorPersonaId,
                'user_id'         => $sponsorPersonaId,
                'unit'            => LedgerEntriesTable::UNIT_EUR,
                'amount'          => abs($amount),
                'reason'          => LedgerEntriesTable::REASON_ADJUSTMENT,
                'description'     => $description ?: "Deposito budget sponsor #{$sponsorPersonaId}",
                'metadata'        => $meta,
                'idempotency_key' => "sponsor:{$sponsorPersonaId}:deposit:{$ts}",
            ],
            [
                'account_id'      => 'system:receivables',
                'user_id'         => $sponsorPersonaId,
                'unit'            => LedgerEntriesTable::UNIT_EUR,
                'amount'          => -abs($amount),
                'reason'          => LedgerEntriesTable::REASON_ADJUSTMENT,
                'description'     => $description ?: "Deposito budget sponsor #{$sponsorPersonaId}",
                'metadata'        => $meta,
                'idempotency_key' => "system:receivables:sponsor:{$sponsorPersonaId}:deposit:{$ts}",
            ],
        ]);
    }

    /**
     * Budget EUR residuo di uno sponsor.
     * Positivo = ha ancora budget; negativo = ha sforato.
     */
    public function getSponsorBudget(int $sponsorPersonaId): float
    {
        return $this->Ledger->getSponsorBudget($sponsorPersonaId);
    }

    // ── Wallet & history ───────────────────────────────────────────────────

    /**
     * Saldo EUR dell'utente:
     *   eur_balance – saldo (negativo = debito verso la piattaforma)
     *   eur_debt    – importo del debito (positivo, 0 se nessun debito)
     */
    public function getWallet(int $userId): array
    {
        $balance = $this->Ledger->getAccountBalance($userId);
        return [
            'eur_balance' => $balance,
            'eur_debt'    => $balance < 0 ? round(abs($balance), 2) : 0.0,
        ];
    }

    /**
     * Utenti con saldo EUR negativo (debitori), arricchiti con anagrafica.
     */
    public function getNegativeWallets(?int $userId = null): array
    {
        $rows = $this->Ledger->getNegativeUsersEurBalance($userId);
        if (empty($rows)) {
            return [];
        }

        $userIds = array_column($rows, 'user_id');
        $Users   = FactoryLocator::get('Table')->get('Users');
        $users   = $Users->find()
            ->where(['Users.id IN' => $userIds])
            ->contain(['Persone'])
            ->all()
            ->indexBy('id')
            ->toArray();

        $result = [];
        foreach ($rows as $row) {
            $uid        = (int)$row['user_id'];
            $eurBalance = (float)$row['eur_balance'];
            $persona    = $users[$uid]?->persona ?? null;

            $result[] = [
                'user_id'     => $uid,
                'user'        => $persona ? [
                    'id'        => $uid,
                    'Nome'      => $persona->Nome      ?? null,
                    'Cognome'   => $persona->Cognome   ?? null,
                    'EMail'     => $persona->EMail     ?? null,
                    'Cellulare' => $persona->Cellulare ?? null,
                ] : null,
                'eur_balance' => $eurBalance,
                'eur_debt'    => round(abs($eurBalance), 2),
            ];
        }

        return $result;
    }

    /**
     * Movimenti EUR di un utente, paginati.
     *
     * Opzioni: reason, direction (IN|OUT), from (Y-m-d), to (Y-m-d), limit, page
     */
    public function getMovements(int $userId, array $options = []): array
    {
        $reason    = $options['reason']    ?? null;
        $direction = $options['direction'] ?? null;
        $from      = $options['from']      ?? null;
        $to        = $options['to']        ?? null;
        $limit     = (int)($options['limit'] ?? 50);
        $page      = max(1, (int)($options['page'] ?? 1));

        $query = $this->Ledger
            ->find()
            ->where([
                'user_id'             => $userId,
                'unit'                => LedgerEntriesTable::UNIT_EUR,
                'account_id NOT LIKE' => 'system:%',
            ])
            ->orderBy(['created' => 'DESC', 'id' => 'DESC'])
            ->limit($limit)
            ->page($page);

        if ($reason !== null) {
            $query->where(['reason' => $reason]);
        }
        if ($direction === 'IN') {
            $query->where(['amount >=' => 0]);
        } elseif ($direction === 'OUT') {
            $query->where(['amount <' => 0]);
        }
        if ($from !== null) {
            $query->where(['created >=' => $from . ' 00:00:00']);
        }
        if ($to !== null) {
            $query->where(['created <=' => $to . ' 23:59:59']);
        }

        $entries = $query->all()->toList();
        $total   = $this->Ledger->find()->where($query->clause('where'))->count();

        return [
            'data'  => $entries,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
            'pages' => $limit > 0 ? (int)ceil($total / $limit) : 1,
        ];
    }

    /**
     * Elimina una singola riga del ledger per ID.
     */
    public function deleteEntry(int $id): bool
    {
        $entry = $this->Ledger->find()->where(['id' => $id])->first();
        if (!$entry) {
            return false;
        }
        return (bool)$this->Ledger->delete($entry);
    }
}
