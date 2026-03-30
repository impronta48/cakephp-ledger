<?php

declare(strict_types=1);

namespace Ledger\Controller;

use Ledger\Controller\AppController;
use Ledger\Service\LedgerService;

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

}
