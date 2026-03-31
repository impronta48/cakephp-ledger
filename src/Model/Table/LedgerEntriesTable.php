<?php
declare(strict_types=1);

namespace Ledger\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;
use Cake\ORM\RulesChecker;

class LedgerEntriesTable extends Table
{
    // Unità di misura
    public const UNIT_TALENT = 'TALENT';
    public const UNIT_EUR    = 'EUR';

    // Reason
    public const REASON_TRIP_CONSUMED        = 'TRIP_CONSUMED';
    public const REASON_CARD_PURCHASED       = 'CARD_PURCHASED';
    public const REASON_CARD_EXPIRE          = 'CARD_EXPIRE';
    public const REASON_WALLET_RECHARGE      = 'WALLET_RECHARGE';
    public const REASON_PAYMENT_DUE          = 'PAYMENT_DUE';
    public const REASON_PAYMENT              = 'PAYMENT';
    public const REASON_TALENT_TRANSFER      = 'TALENT_TRANSFER';
    public const REASON_ADJUSTMENT           = 'ADJUSTMENT';

    // Reference type
    public const REF_CARD = 'Card';

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ledger_entries');
        $this->setPrimaryKey('id');
        $this->setDisplayField('id');

        $this->addBehavior('Timestamp', [
            'events' => ['Model.beforeSave' => ['created_at' => 'new']]
        ]);

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
        ]);

        $this->belongsTo('CounterpartyUsers', [
            'className' => 'Users',
            'foreignKey' => 'counterparty_user_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->integer('user_id')->requirePresence('user_id')
            ->scalar('unit')->inList('unit', [self::UNIT_TALENT, self::UNIT_EUR])
            ->numeric('amount')->notEmptyString('amount')
            ->scalar('reason')->notEmptyString('reason')
            ->allowEmptyString('transfer_id')
            ->allowEmptyString('reference_type')
            ->allowEmptyString('reference_id')
            ->allowEmptyArray('metadata');
    }

    /**
     * Saldo totale per utente e unità (free + vincolati)
     */
    public function getBalance(int $userId, string $unit): float
    {
        return (float)$this->find()
            ->where([
                'user_id' => $userId,
                'unit' => $unit,
            ])
            ->select(['total' => 'SUM(amount)'])
            ->first()
            ?->get('total') ?? 0.0;
    }

    /**
     * Saldo talenti LIBERI (solo righe senza reference_type).
     */
    public function getFreeBalance(int $userId, string $unit = self::UNIT_TALENT): float
    {
        return (float)$this->find()
            ->where([
                'user_id'          => $userId,
                'unit'             => $unit,
                'reference_type IS' => null,
            ])
            ->select(['total' => 'SUM(amount)'])
            ->first()
            ?->get('total') ?? 0.0;
    }

    /**
     * Storico dei movimenti di una card specifica, dal più recente.
     */
    public function getCardEntries(int $userId, int $cardId)
    {
        return $this->find()
            ->where([
                'user_id'        => $userId,
                'unit'           => self::UNIT_TALENT,
                'reference_type' => self::REF_CARD,
                'reference_id'   => $cardId,
            ])
            ->orderBy(['created_at' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Saldo talenti vincolati a una card specifica.
     * Se $cardId è null restituisce il totale di tutti i conti card dell'utente.
     */
    public function getCardBalance(int $userId, ?int $cardId = null): float
    {
        $conditions = [
            'user_id'        => $userId,
            'unit'           => self::UNIT_TALENT,
            'reference_type' => self::REF_CARD,
        ];
        if ($cardId !== null) {
            $conditions['reference_id'] = $cardId;
        }

        return (float)$this->find()
            ->where($conditions)
            ->select(['total' => 'SUM(amount)'])
            ->first()
            ?->get('total') ?? 0.0;
    }

    /**
     * Movimenti per transfer_id (utile per audit)
     */
    public function getTransferEntries(string $transferId)
    {
        return $this->find()
            ->where(['transfer_id' => $transferId])
            ->orderBy(['created_at' => 'ASC']);
    }
}