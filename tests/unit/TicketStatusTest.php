<?php
namespace FloSports\ScaleEngine;

use FloSports\ScaleEngine\Model\ScaleEngineTicket;
use PHPUnit_Framework_TestCase;

/**
 * @coversDefaultClass \FloSports\ScaleEngine\TicketStatus
 */
class TicketStatusTest extends PHPUnit_Framework_TestCase
{
    /**
     * Verify that a ticket status can be created and viewed as an array.
     *
     * @test
     * @covers ::__construct
     * @covers ::toArray
     */
    public function basic()
    {
        $ticket = new ScaleEngineTicket();

        $status = new TicketStatus(TicketStatus::TYPE_SUCCESS, $ticket, true, null);

        $data = $status->toArray();
        $this->assertSame(
            [
                'type' => TicketStatus::TYPE_SUCCESS,
                'ticket' => $ticket,
                'playable' => true,
                'error' => null
            ],
            $status->toArray()
        );
    }
}
