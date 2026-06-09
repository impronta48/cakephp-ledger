<?php
declare(strict_types=1);

namespace Ledger\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class LedgerEntriesTable extends Table
{
    // ── Units ──────────────────────────────────────────────────────────────
    public const UNIT_EUR    = 'EUR';
    public const UNIT_TALENT = 'TALENT'; // Riservato per futuri premi/punti

    // ── Reasons (EUR) ──────────────────────────────────────────────────────

    /** Addebito acquisto abbonamento */
    public const REASON_CARD_CHARGE = 'CARD_CHARGE';

    /** Pagamento incassato */
    public const REASON_PAYMENT = 'PAYMENT';

    /** Rimborso km previsionale (accredito a inizio mese) */
    public const REASON_KM_REFUND = 'KM_REFUND';

    /** Storno rimborso km per viaggi non effettuati */
    public const REASON_KM_REFUND_REVERSAL = 'KM_REFUND_REVERSAL';

    /** Sponsor copre il costo dell'utente sponsorizzato */
    public const REASON_SPONSOR_COVERAGE = 'SPONSOR_COVERAGE';

    /** Rettifica manuale */
    public const REASON_ADJUSTMENT = 'ADJUSTMENT';

    /** Rimborso */
    public const REASON_REFUND = 'REFUND';

    // ── Reference types ────────────────────────────────────────────────────
    public const REF_CARD    = 'Card';
    public const REF_PAYIN   = 'Payin';
    public const REF_REQUEST = 'Request';

    // ── Account ID convention ──────────────────────────────────────────────
    // user:{id}             Conto EUR dell'utente (negativo = debito)
    // sponsor:{id}          Budget EUR dello sponsor (persona_id)
    // system:receivables    Crediti EUR da incassare
    // system:km_pool        Pool rimborsi km

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ledger_entries');
        $this->setPrimaryKey('id');
        $this->setDisplayField('id');

        $this->addBehavior('Timestamp', [
            'events' => ['Model.beforeSave' => ['created' => 'new']],
        ]);

        $this->belongsTo('Users', ['foreignKey' => 'user_id']);
        $this->belongsTo('CounterpartyUsers', [
            'className'  => 'Users',
            'foreignKey' => 'counterparty_user_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->integer('user_id')->requirePresence('user_id')
            ->scalar('unit')->inList('unit', [self::UNIT_EUR, self::UNIT_TALENT])
            ->numeric('amount')->notEmptyString('amount')
            ->scalar('reason')->notEmptyString('reason')
            ->allowEmptyString('transfer_id')
            ->allowEmptyString('reference_type')
            ->allowEmptyString('reference_id')
            ->allowEmptyArray('metadata');
    }

    // ── Balance queries ────────────────────────────────────────────────────

    /**
     * Saldo EUR del conto personale di un utente (account_id = 'user:{userId}').
     * Negativo = debito verso la piattaforma.
     */
    public function getAccountBalance(int $userId): float
    {
        return (float)$this->find()
            ->where([
                'user_id'    => $userId,
                'unit'       => self::UNIT_EUR,
                'account_id' => 'user:' . $userId,
            ])
            ->select(['total' => 'SUM(amount)'])
            ->first()
            ?->get('total') ?? 0.0;
    }

    /**
     * Budget EUR residuo di uno sponsor.
     * Positivo = ha ancora budget; negativo = ha sforato.
     */
    public function getSponsorBudget(int $sponsorPersonaId): float
    {
        return (float)$this->find()
            ->where([
                'account_id' => 'sponsor:' . $sponsorPersonaId,
                'unit'       => self::UNIT_EUR,
            ])
            ->select(['total' => 'SUM(amount)'])
            ->first()
            ?->get('total') ?? 0.0;
    }

    /**
     * Movimenti per transfer_id (audit).
     */
    public function getTransferEntries(string $transferId)
    {
        return $this->find()
            ->where(['transfer_id' => $transferId])
            ->orderBy(['created' => 'ASC']);
    }

    /**
     * Utenti con saldo EUR negativo sul proprio conto (account_id LIKE 'user:%').
     */
    public function getNegativeUsersEurBalance(?int $userId = null): array
    {
        $conditions = ['unit' => self::UNIT_EUR, 'account_id LIKE' => 'user:%'];
        if ($userId !== null) {
            $conditions['user_id'] = $userId;
        }

        return $this->find()
            ->where($conditions)
            ->select(['user_id', 'eur_balance' => 'SUM(amount)'])
            ->groupBy('user_id')
            ->having(['SUM(amount) <' => 0])
            ->enableHydration(false)
            ->all()
            ->toArray();
    }

    /**
     * Sponsor con budget EUR esaurito (overspent).
     */
    public function getOverspentSponsors(): array
    {
        return $this->find()
            ->where(['unit' => self::UNIT_EUR, 'account_id LIKE' => 'sponsor:%'])
            ->select(['account_id', 'balance' => 'SUM(amount)'])
            ->groupBy('account_id')
            ->having(['SUM(amount) <' => 0])
            ->enableHydration(false)
            ->all()
            ->toArray();
    }
}
