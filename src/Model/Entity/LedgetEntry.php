<?php
declare(strict_types=1);

namespace Ledger\Model\Entity;

use Cake\ORM\Entity;

class LedgerEntry extends Entity
{
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];
}