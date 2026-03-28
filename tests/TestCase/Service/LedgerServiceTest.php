<?php
declare(strict_types=1);

namespace Ledger\Test\TestCase\Service;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Ledger\Model\Table\LedgerEntriesTable;
use Ledger\Service\LedgerService;

class LedgerServiceTest extends TestCase
{
    protected $LedgerService;
    protected $ledgerTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledgerTable = $this->getMockBuilder(LedgerEntriesTable::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFreeBalance', 'getCardBalance', 'getBalance'])
            ->getMock();

        $locator = TableRegistry::getTableLocator();
        $locator->set('Ledger.LedgerEntries', $this->ledgerTable);

        $this->LedgerService = new LedgerService();
    }

    protected function tearDown(): void
    {
        unset($this->LedgerService, $this->ledgerTable);
        parent::tearDown();
    }

    public function testGetWallet(): void
    {
        $this->ledgerTable->expects($this->once())
            ->method('getFreeBalance')
            ->with(5, LedgerEntriesTable::UNIT_TALENT)
            ->willReturn(120.5);

        $this->ledgerTable->expects($this->once())
            ->method('getCardBalance')
            ->with(5)
            ->willReturn(30.0);

        $this->ledgerTable->expects($this->once())
            ->method('getBalance')
            ->with(5, LedgerEntriesTable::UNIT_EUR)
            ->willReturn(45.75);

        $wallet = $this->LedgerService->getWallet(5);

        $this->assertSame(120.5, $wallet['free_talents']);
        $this->assertSame(30.0, $wallet['card_talents']);
        $this->assertSame(150.5, $wallet['total_talents']);
        $this->assertSame(45.75, $wallet['eur']);
    }

    public function testReserveForCardAndConsumeFromCardConstants(): void
    {
        $this->assertSame('Card', LedgerEntriesTable::REF_CARD);
        $this->assertSame('TALENT', LedgerEntriesTable::UNIT_TALENT);
        $this->assertSame('TRIP_CONSUMED', LedgerEntriesTable::REASON_TRIP_CONSUMED);
    }
}
