<?php
declare(strict_types=1);

namespace Ledger\Service;

use Cake\ORM\TableRegistry;
use Cake\Datasource\ConnectionManager;
use Cake\Utility\Text;
use Ledger\Model\Table\LedgerEntriesTable;

class LedgerService
{
    protected $Ledger;

    public function __construct()
    {
        $this->Ledger = TableRegistry::getTableLocator()->get('Ledger.LedgerEntries');
    }

    /**
     * Movimento semplice (1 riga)
     */
    public function addEntry(array $data)
    {
        $entry = $this->Ledger->newEntity($data);
        return $this->Ledger->saveOrFail($entry);
    }

    /**
     * Transazione double-entry atomica
     */
    public function addTransfer(array $entries): string
    {
        $connection = ConnectionManager::get('default');
        $transferId = Text::uuid();

        $connection->transactional(function () use ($entries, $transferId) {
            foreach ($entries as $entryData) {
                $entryData['transfer_id'] = $transferId;
                $entry = $this->Ledger->newEntity($entryData);
                $this->Ledger->saveOrFail($entry);
            }
        });

        return $transferId;
    }

    /**
     * Trasferimento Talenti tra utenti
     */
    public function transferTalents(
        int $fromUser,
        int $toUser,
        float $amount,
        array $meta = []
    ): string {
        return $this->addTransfer([
            [
                'user_id'              => $fromUser,
                'counterparty_user_id' => $toUser,
                'unit'                 => LedgerEntriesTable::UNIT_TALENT,
                'amount'               => -abs($amount),
                'reason'               => LedgerEntriesTable::REASON_TALENT_TRANSFER,
                'metadata'             => $meta,
            ],
            [
                'user_id'              => $toUser,
                'counterparty_user_id' => $fromUser,
                'unit'                 => LedgerEntriesTable::UNIT_TALENT,
                'amount'               => abs($amount),
                'reason'               => LedgerEntriesTable::REASON_TALENT_TRANSFER,
                'metadata'             => $meta,
            ],
        ]);
    }

    /**
     * Saldi wallet (liberi + vincolati separati)
     */
    public function getWallet(int $userId): array
    {
        $free = $this->Ledger->getFreeBalance($userId, LedgerEntriesTable::UNIT_TALENT);
        $card = $this->Ledger->getCardBalance($userId);

        return [
            'free_talents'  => $free,
            'card_talents'  => $card,
            'total_talents' => $free + $card,
            'eur'           => $this->Ledger->getBalance($userId, LedgerEntriesTable::UNIT_EUR),
        ];
    }

    /**
     * Acquisto abbonamento: sposta $amount talenti dal conto libero al conto card.
     *
     * Crea due righe bilanciate (double-entry) con lo stesso transfer_id:
     *   - debito conto libero  (amount negativo, reference_type=NULL)
     *   - credito conto card   (amount positivo, reference_type='Card')
     */
    public function reserveForCard(
        int $userId,
        int $cardId,
        float $amount,
        array $meta = []
    ): string {
        return $this->addTransfer([
            [
                'user_id'        => $userId,
                'unit'           => LedgerEntriesTable::UNIT_TALENT,
                'amount'         => -abs($amount),
                'reason'         => LedgerEntriesTable::REASON_CARD_PURCHASED,
                'reference_type' => null,
                'reference_id'   => null,
                'metadata'       => $meta,
            ],
            [
                'user_id'        => $userId,
                'unit'           => LedgerEntriesTable::UNIT_TALENT,
                'amount'         => abs($amount),
                'reason'         => LedgerEntriesTable::REASON_CARD_PURCHASED,
                'reference_type' => LedgerEntriesTable::REF_CARD,
                'reference_id'   => $cardId,
                'metadata'       => $meta,
            ],
        ]);
    }

    /**
     * Consuma talenti dal conto card (viaggio effettuato).
     */
    /**
     * Consuma talenti dal conto card (viaggio effettuato).
     * $amount deve essere il costo del viaggio (sempre positivo).
     */
    public function consumeFromCard(
        int $userId,
        int $cardId,
        float $amount,
        array $meta = []
    ) {
        return $this->addEntry([
            'user_id'        => $userId,
            'unit'           => LedgerEntriesTable::UNIT_TALENT,
            'amount'         => -abs($amount),
            'reason'         => LedgerEntriesTable::REASON_TRIP_CONSUMED,
            'reference_type' => LedgerEntriesTable::REF_CARD,
            'reference_id'   => $cardId,
            'metadata'       => $meta,
        ]);
    }

    /**
     * Azzera il saldo di una card scaduta.
     *
     * Scrive una riga ADJUSTMENT negativa pari al residuo ancora presente
     * sul conto della card. Se il saldo è già <= 0 non scrive nulla e
     * restituisce null.
     */
    public function expireCard(
        int $userId,
        int $cardId,
        array $meta = []
    ) {
        $residuo = $this->Ledger->getCardBalance($userId, $cardId);

        if ($residuo <= 0) {
            return null;
        }

        return $this->addEntry([
            'user_id'        => $userId,
            'unit'           => LedgerEntriesTable::UNIT_TALENT,
            'amount'         => -$residuo,
            'reason'         => LedgerEntriesTable::REASON_ADJUSTMENT,
            'reference_type' => LedgerEntriesTable::REF_CARD,
            'reference_id'   => $cardId,
            'metadata'       => array_merge(['expired' => true], $meta),
        ]);
    }

    /**
     * Movimenti di un utente, ordinati dal più recente.
     *
     * Opzioni supportate:
     *   unit       string|null  filtra per unità ('TALENT' | 'EUR')
     *   reason     string|null  filtra per reason
     *   direction  string|null  filtra per direction: 'IN' (amount >= 0) o 'OUT' (amount < 0)
     *   from       string|null  data minima created_at (Y-m-d)
     *   to         string|null  data massima created_at (Y-m-d)
     *   limit      int          default 50
     *   page       int          default 1
     */
    public function getMovements(int $userId, array $options = []): array
    {
        $unit      = $options['unit']      ?? null;
        $reason    = $options['reason']    ?? null;
        $direction = $options['direction'] ?? null;
        $from      = $options['from']      ?? null;
        $to        = $options['to']        ?? null;
        $limit     = (int)($options['limit'] ?? 50);
        $page      = max(1, (int)($options['page'] ?? 1));

        $query = $this->Ledger
            ->find()
            ->where(['user_id' => $userId])
            ->orderBy(['created_at' => 'DESC', 'id' => 'DESC'])
            ->limit($limit)
            ->page($page);

        if ($unit !== null) {
            $query->where(['unit' => $unit]);
        }
        if ($reason !== null) {
            $query->where(['reason' => $reason]);
        }
        if ($direction === 'IN') {
            $query->where(['amount >=' => 0]);
        } elseif ($direction === 'OUT') {
            $query->where(['amount <' => 0]);
        }
        if ($from !== null) {
            $query->where(['created_at >=' => $from . ' 00:00:00']);
        }
        if ($to !== null) {
            $query->where(['created_at <=' => $to . ' 23:59:59']);
        }

        $entries = $query->all()->toList();
        $total   = $this->Ledger
            ->find()
            ->where($query->clause('where'))
            ->count();

        return [
            'data'  => $entries,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
            'pages' => $limit > 0 ? (int)ceil($total / $limit) : 1,
        ];
    }
}