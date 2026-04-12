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
                try {
                    $this->Ledger->saveOrFail($entry);
                } catch (\Exception $e) {
                    throw new \RuntimeException("Failed to save ledger entry: " . $e->getMessage(), 0, $e);
                }                
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
        array $meta = [],
        ?\DateTimeInterface $date = null,
        string $description = ''
    ): string {
        return $this->addTransfer([
            [
                'user_id'              => $fromUser,
                'counterparty_user_id' => $toUser,
                'unit'                 => LedgerEntriesTable::UNIT_TALENT,
                'amount'               => -abs($amount),
                'reason'               => LedgerEntriesTable::REASON_TALENT_TRANSFER,
                'metadata'             => $meta,
                'created'              => $date,
                'description'          => $description,
            ],
            [
                'user_id'              => $toUser,
                'counterparty_user_id' => $fromUser,
                'unit'                 => LedgerEntriesTable::UNIT_TALENT,
                'amount'               => abs($amount),
                'reason'               => LedgerEntriesTable::REASON_TALENT_TRANSFER,
                'metadata'             => $meta,
                'created'              => $date,
                'description'          => $description,
            ],
        ]);
    }

    /**
     * Saldi wallet:
     *   free_talents  – talenti liberi nel conto utente
     *   eur_balance   – saldo EUR netto del conto utente (negativo = debito)
     *   eur_debt      – debito in euro (valore positivo, 0 se nessun debito)
     */
    public function getWallet(int $userId): array
    {
        $free       = $this->Ledger->getFreeBalance($userId, LedgerEntriesTable::UNIT_TALENT);
        $eurBalance = $this->Ledger->getAccountBalance($userId, LedgerEntriesTable::UNIT_EUR);

        return [
            'free_talents' => $free,
            'eur_balance'  => $eurBalance,
            'eur_debt'     => $eurBalance < 0 ? round(abs($eurBalance), 2) : 0.0,
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
        array $meta = [],
        ?\DateTimeInterface $date = null,
        string $description = ''
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
                'created'        => $date,
                'description'    => $description,
            ],
            [
                'user_id'        => $userId,
                'unit'           => LedgerEntriesTable::UNIT_TALENT,
                'amount'         => abs($amount),
                'reason'         => LedgerEntriesTable::REASON_CARD_PURCHASED,
                'reference_type' => LedgerEntriesTable::REF_CARD,
                'reference_id'   => $cardId,
                'metadata'       => $meta,
                'created'        => $date,
                'description'    => $description,
            ],
        ]);
    }

    /**
     * Consuma talenti dal conto card (viaggio effettuato).
     * $amount deve essere il costo del viaggio (sempre positivo).
     */
    public function consumeFromCard(
        int $userId,
        int $cardId,
        float $amount,
        array $meta = [],
            ?\DateTimeInterface $date = null,
            string $description = ''
    ) {
        return $this->addEntry([
            'user_id'        => $userId,
            'unit'           => LedgerEntriesTable::UNIT_TALENT,
            'amount'         => -abs($amount),
            'reason'         => LedgerEntriesTable::REASON_TRIP_CONSUMED,
            'reference_type' => LedgerEntriesTable::REF_CARD,
            'reference_id'   => $cardId,
            'metadata'       => $meta,
            'created'        => $date,
            'description'    => $description,
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
        array $meta = [],
        ?\DateTimeInterface $date = null,
        string $description = ''
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
            'created'        => $date,
            'description'    => $description,

        ]);
    }

    /**
     * Movimenti di un utente, ordinati dal più recente.
     *
     * Opzioni supportate:
     *   unit       string|null  filtra per unità ('TALENT' | 'EUR')
     *   reason     string|null  filtra per reason
     *   direction  string|null  filtra per direction: 'IN' (amount >= 0) o 'OUT' (amount < 0)
     *   from       string|null  data minima created (Y-m-d)
     *   to         string|null  data massima created (Y-m-d)
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
            ->where([
                'user_id' => $userId,
                'account_id NOT LIKE' => 'system:%',
            ])
            ->orderBy(['created' => 'DESC', 'id' => 'DESC'])
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
            $query->where(['created >=' => $from . ' 00:00:00']);
        }
        if ($to !== null) {
            $query->where(['created <=' => $to . ' 23:59:59']);
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

    /**
     * Restituisce tutti gli utenti con saldo EUR negativo (debitori),
     * arricchiti con i dati anagrafici dell'utente.
     *
     * @param int|null $userId  Filtra su un singolo utente (opzionale, per admin).
     * @return array
     */
    public function getNegativeWallets(?int $userId = null): array
    {
        $rows = $this->Ledger->getNegativeUsersEurBalance($userId);

        if (empty($rows)) {
            return [];
        }

        $userIds = array_column($rows, 'user_id');

        $Users = TableRegistry::getTableLocator()->get('Users');
        $users = $Users->find()
            ->where(['Users.id IN' => $userIds])
            ->contain(['Persone'])
            ->all()
            ->indexBy('id')
            ->toArray();

        $result = [];
        foreach ($rows as $row) {
            $uid        = (int)$row->user_id;
            $eurBalance = (float)$row->eur_balance;
            $user       = $users[$uid] ?? null;
            $persona    = $user?->persona ?? null;

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
}
