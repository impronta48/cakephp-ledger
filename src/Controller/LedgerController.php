<?php

declare(strict_types=1);

namespace Ledger\Controller;

use Ledger\Controller\AppController;
use Ledger\Service\LedgerService;
use Ledger\Model\Table\LedgerEntriesTable;
use Cake\I18n\FrozenTime;

use function Cake\Core\toInt;

/**
 * Ledger Controller
 *
 */
class LedgerController extends AppController
{

    protected LedgerService $ledgerService;
    
    public function initialize(): void
    {
        parent::initialize();
        //Allow index and movements without authentication, wallet requires authentication
        $this->Authentication->addUnauthenticatedActions(['index']);
        $this->ledgerService = new LedgerService();
    }

    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $this->set([
            'success' => true,
            'message' => 'Ledger API is up and running'
        ]);
        $this->viewBuilder()->setOption('serialize', ['success', 'message']);
    }

    public function movements($user_id = null)
    {
        $currentUser = $this->Authentication->getIdentity()->get('id');
        //Se l'utente è admin può vedere i movimenti di chiunque, altrimenti solo i suoi
        if ($this->Authentication->getIdentity()->get('group_id') == 1){
            $userId = $user_id ?? $currentUser;
        } else {
            $userId = $currentUser;
        }
        $userId = toInt($userId);
        
        $page      = $this->request->getQuery('page', 1);
        $limit     = $this->request->getQuery('limit', 50);
        $unit      = $this->request->getQuery('unit', null);
        $direction = $this->request->getQuery('direction', null);
        $from      = $this->request->getQuery('from', null);
        $to        = $this->request->getQuery('to', null);

        $rows = $this->ledgerService->getMovements($userId, [
            'page'      => $page,
            'limit'     => $limit,
            'unit'      => $unit,
            'direction' => $direction,
            'from'      => $from,
            'to'        => $to,
        ]);

        $this->set([
            'success' => true,
            'movements' => $rows
        ]);
        $this->viewBuilder()->setOption('serialize', ['success', 'movements']);
    }

    public function wallet($user_id = null)
    {
        $currentUser = $this->Authentication->getIdentity()->get('id');
                if ($this->Authentication->getIdentity()->get('group_id') == 1){
            $userId = $user_id ?? $currentUser;
        } else {
            $userId = $currentUser;
        }
        $userId = toInt($userId);
        
        $wallet = $this->ledgerService->getWallet($userId);

        $this->set([
            'success' => true,
            'wallet' => $wallet
        ]);
        $this->viewBuilder()->setOption('serialize', ['success', 'wallet']);
    }

    /**
     * Restituisce tutti gli utenti con saldo EUR negativo (debitori).
     * Solo gli admin possono accedere.
     *
     * Query params:
     *   user_id (int, optional) – filtra su un singolo utente
     */
    public function negativeWallets()
    {
        if ($this->Authentication->getIdentity()->get('group_id') != 1) {
            $this->response = $this->response->withStatus(403);
            $this->set(['success' => false, 'message' => 'Forbidden']);
            $this->viewBuilder()->setOption('serialize', ['success', 'message']);
            return;
        }

        $userId = $this->request->getQuery('user_id', null);
        if ($userId !== null) {
            $userId = toInt($userId);
        }

        $data = $this->ledgerService->getNegativeWallets($userId);

        $this->set([
            'success' => true,
            'data'    => $data,
        ]);
        $this->viewBuilder()->setOption('serialize', ['success', 'data']);
    }

    /**
     * Create a double-entry talent transfer between two users (admin only).
     *
     * POST /ledger/ledger/transfer.json
     * Body JSON:
     *   from_user_id  int     – sender user ID
     *   to_user_id    int     – recipient user ID
     *   amount        float   – positive amount to transfer
     *   unit          string  – 'TALENT' (default) or 'EUR'
     *   reason        string  – optional, defaults to 'TALENT_TRANSFER'
     *   description   string  – optional description stored in metadata
     */
    public function transfer()
    {
        $this->request->allowMethod(['post']);

        if ($this->Authentication->getIdentity()->get('group_id') != 1) {
            $this->response = $this->response->withStatus(403);
            $this->set(['success' => false, 'message' => 'Forbidden']);
            $this->viewBuilder()->setOption('serialize', ['success', 'message']);
            return;
        }

        $data        = $this->request->getData();
        $userId  = toInt($data['from_user_id'] ?? 0);
        $toUserId    = toInt($data['to_user_id'] ?? 0);
        $amount      = (float)($data['amount'] ?? 0);
        $description = $data['description'] ?? '';
        $date        = $data['date'] ?? null;
        $createdAt   = $date !== null ? new FrozenTime($date) : FrozenTime::now();

        if ($userId <= 0 || $toUserId <= 0 || $amount <= 0) {
            $this->response = $this->response->withStatus(400);
            $this->set(['success' => false, 'message' => 'from_user_id, to_user_id and amount are required and must be positive']);
            $this->viewBuilder()->setOption('serialize', ['success', 'message']);
            return;
        }

        $createdAt   = $date !== null ? new FrozenTime($date) : FrozenTime::now();        

        $tsKey     = $createdAt->format('Y-m-d\TH:i:s');
        $entryData = [
                    // 1. Debito sul pool talenti di sistema (emissione)
                    [
                        'created'         => $createdAt,
                        'account_id'      => 'user:' . $userId,
                        'user_id'         => $userId,
                        'counterparty_user_id' => $toUserId,
                        'unit'            => LedgerEntriesTable::UNIT_TALENT,
                        'amount'          => -$amount,
                        'reason'          => LedgerEntriesTable::REASON_TALENT_TRANSFER,
                        'reference_type'  => null,
                        'reference_id'    => null,
                        'description'     => sprintf('Trasferimento %s',$description),
                        'metadata'        => ['origin' => 'wallet_transfer'],
                        'idempotency_key' => "user:{$userId}:wallet:{$userId}:to:{$toUserId}:date:{$tsKey}",
                    ],
                    // 2. Accredito talenti sul conto dell'utente
                    [
                        'created'         => $createdAt,
                        'account_id'      => 'user:' . $toUserId,
                        'user_id'         => $toUserId,
                        'counterparty_user_id' => $userId,
                        'unit'            => LedgerEntriesTable::UNIT_TALENT,
                        'amount'          => $amount,
                        'reason'          => LedgerEntriesTable::REASON_TALENT_TRANSFER,
                        'reference_type'  => null,
                        'reference_id'    => null,
                        'description'     => sprintf('Trasferimento %s',$description),
                        'metadata'        => ['origin' => 'wallet_transfer'],
                        'idempotency_key' => "user:{$toUserId}:wallet:{$toUserId}:from:{$userId}:date:{$tsKey}",
                    ],
                ];

        try {
            $transferId = $this->ledgerService->addTransfer($entryData);
            $this->set(['success' => true, 'transfer_id' => $transferId]);
            $this->viewBuilder()->setOption('serialize', ['success', 'transfer_id']);
        } catch (\Exception $e) {
            $this->set(['success' => false, 'message' => $e->getMessage()]);
            $this->viewBuilder()->setOption('serialize', ['success', 'message']);
        }
    }

    /**
     * Credit a user's wallet with a single talent (or EUR) entry (admin only).
     *
     * POST /ledger/ledger/credit.json
     * Body JSON:
     *   user_id     int    – recipient user ID
     *   amount      float  – positive amount to credit
     *   reason      string – 'WALLET_RECHARGE' (default) or 'ADJUSTMENT'
     *   unit        string – 'TALENT' (default) or 'EUR'
     *   description string – optional, stored in metadata
     *   date        string – optional ISO date (Y-m-d or Y-m-d H:i:s)
     */
    public function credit()
    {
        $this->request->allowMethod(['post']);

        if ($this->Authentication->getIdentity()->get('group_id') != 1) {
            $this->response = $this->response->withStatus(403);
            $this->set(['success' => false, 'message' => 'Forbidden']);
            $this->viewBuilder()->setOption('serialize', ['success', 'message']);
            return;
        }

        $data        = $this->request->getData();
        $userId      = toInt($data['user_id'] ?? 0);
        $amount      = (float)($data['amount'] ?? 0);
        $description = $data['description'] ?? '';
        $date        = $data['date'] ?? null;
        $createdAt   = $date !== null ? new FrozenTime($date) : FrozenTime::now();

        if ($userId <= 0 || $amount <= 0) {
            $this->response = $this->response->withStatus(400);
            $this->set(['success' => false, 'message' => 'user_id and amount are required and must be positive']);
            $this->viewBuilder()->setOption('serialize', ['success', 'message']);
            return;
        }

        $tsKey     = $createdAt->format('Y-m-d\TH:i:s');
        $entryData = [
                    // 1. Debito sul pool talenti di sistema (emissione)
                    [
                        'created'         => $createdAt,
                        'account_id'      => 'system:talent_pool',
                        'user_id'         => $userId,
                        'unit'            => LedgerEntriesTable::UNIT_TALENT,
                        'amount'          => -$amount,
                        'reason'          => LedgerEntriesTable::REASON_WALLET_RECHARGE,
                        'reference_type'  => null,
                        'reference_id'    => null,
                        'description'     => sprintf('Ricarica wallet: %s', $description),
                        'metadata'        => ['origin' => 'wallet_credit'],
                        'idempotency_key' => "system:talent_pool:wallet:{$userId}:date:{$tsKey}",
                    ],
                    // 2. Accredito talenti sul conto dell'utente
                    [
                        'created'         => $createdAt,
                        'account_id'      => 'user:' . $userId,
                        'user_id'         => $userId,
                        'unit'            => LedgerEntriesTable::UNIT_TALENT,
                        'amount'          => $amount,
                        'reason'          => LedgerEntriesTable::REASON_WALLET_RECHARGE,
                        'reference_type'  => null,
                        'reference_id'    => null,
                        'description'     => sprintf('Ricarica wallet: %s', $description),
                        'metadata'        => ['origin' => 'wallet_credit'],
                        'idempotency_key' => "user:{$userId}:wallet:{$userId}:date:{$tsKey}",
                    ],
                ];

        try {
            $transferId = $this->ledgerService->addTransfer($entryData);
            $this->set(['success' => true, 'transfer_id' => $transferId]);
            $this->viewBuilder()->setOption('serialize', ['success', 'transfer_id']);
        } catch (\Exception $e) {
            $this->set(['success' => false, 'message' => $e->getMessage()]);
            $this->viewBuilder()->setOption('serialize', ['success', 'message']);
        }
    }

}
