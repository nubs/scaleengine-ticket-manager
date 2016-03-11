<?php
namespace FloSports\ScaleEngine;

use DateInterval;
use Exception;
use FloSports\ScaleEngine\Model\ScaleEngineTicket;
use PHPUnit_Framework_TestCase;

/**
 * @coversDefaultClass \FloSports\ScaleEngine\ScaleEngineTicketManager
 */
class ScaleEngineTicketManagerTest extends PHPUnit_Framework_TestCase
{
    /**
     * Verify that a basic, uncached call to getTicket requests and returns a
     * ticket from ScaleEngine.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::getTicket
     * @covers ::_replaceTicket
     * @covers ::_requestTicket
     * @covers ::_generatePassword
     */
    public function getTicketUncachedRequestTicketSuccess()
    {
        $ticket = new ScaleEngineTicket();
        $requestData = ['foo' => 'bar'];
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientForRequestTicket($ticket, $requestData);
        $mockCacheItem = $this->_getMockCacheItemMissesAndWillBeSet($ticket, new DateInterval('P1D'));
        $mockCache = $this->_getMockCacheThatReturnsItemThatWillBeSaved($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketStatus = $ticketManager->getTicket($userId, $requestData);

        $this->assertSame(
            [
                'type' => TicketStatus::TYPE_UPDATE_TICKET,
                'ticket' => $ticket,
                'playable' => true,
                'error' => null,
            ],
            $ticketStatus->toArray()
        );
    }

    /**
     * Verify that a basic, cached call to getTicket returns the cached ticket.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::getTicket
     */
    public function getTicketCached()
    {
        $ticket = new ScaleEngineTicket();
        $requestData = ['foo' => 'bar'];
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientDoesNothing();
        $mockCacheItem = $this->_getMockCacheItemHits($ticket);
        $mockCache = $this->_getMockCacheThatReturnsItem($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketStatus = $ticketManager->getTicket($userId, $requestData);

        $this->assertSame(
            [
                'type' => TicketStatus::TYPE_UPDATE_TICKET,
                'ticket' => $ticket,
                'playable' => true,
                'error' => null,
            ],
            $ticketStatus->toArray()
        );
    }

    /**
     * Verify that a failing call to save to cache fails the entire request.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::getTicket
     * @covers ::_replaceTicket
     * @covers ::_requestTicket
     * @covers ::_generatePassword
     * @expectedException Exception
     * @expectedExceptionMessage Failed to cache the ticket for a user.
     */
    public function getTicketUncachedRequestTicketFailsToSave()
    {
        $ticket = new ScaleEngineTicket();
        $requestData = ['foo' => 'bar'];
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientForRequestTicket($ticket, $requestData);
        $mockCacheItem = $this->_getMockCacheItemMissesAndWillBeSet($ticket, new DateInterval('P1D'));
        $mockCache = $this->_getMockCacheThatReturnsItemThatWillBeSaved($userId, $mockCacheItem, false);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketManager->getTicket($userId, $requestData);
    }

    /**
     * Verify that a request to get a ticket without request data fails.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::getTicket
     * @covers ::_replaceTicket
     * @covers ::_requestTicket
     * @expectedException Exception
     * @expectedExceptionMessage Missing data necessary to create a ticket.
     */
    public function getTicketUncachedNoRequestData()
    {
        $requestData = [];
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientDoesNothing();
        $mockCacheItem = $this->_getMockCacheItemMisses();
        $mockCache = $this->_getMockCacheThatReturnsItem($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketManager->getTicket($userId, $requestData);
    }

    /**
     * Verify that a failed request to ScaleEngine throws an exception.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::getTicket
     * @covers ::_replaceTicket
     * @covers ::_requestTicket
     * @covers ::_generatePassword
     * @expectedException Exception
     * @expectedExceptionMessage It failed
     */
    public function getTicketUncachedFails()
    {
        $requestData = ['foo' => 'bar'];
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientFails($requestData);
        $mockCacheItem = $this->_getMockCacheItemMisses();
        $mockCache = $this->_getMockCacheThatReturnsItem($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketManager->getTicket($userId, $requestData);
    }

    /**
     * If checkStatus fails to find a ticket, it should return an error.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::checkStatus
     * @covers ::_cacheStatus
     */
    public function checkStatusCacheMiss()
    {
        $ticket = 'ticketkey';
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientDoesNothing();
        $mockCacheItem = $this->_getMockCacheItemMisses();
        $mockCache = $this->_getMockCacheThatReturnsItem($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketStatus = $ticketManager->checkStatus($userId, $ticket);

        $this->assertSame(
            [
                'type' => TicketStatus::TYPE_UNKNOWN_ERROR,
                'ticket' => null,
                'playable' => false,
                'error' => 'missing-ticket',
            ],
            $ticketStatus->toArray()
        );
    }

    /**
     * If checkStatus finds a different ticket, it should update the ticket for
     * the user.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::checkStatus
     * @covers ::_cacheStatus
     */
    public function checkStatusDifferentTicket()
    {
        $ticket = 'ticketkey';
        $cacheTicket = new ScaleEngineTicket(['key' => 'differentkey']);
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientDoesNothing();
        $mockCacheItem = $this->_getMockCacheItemHits($cacheTicket);
        $mockCache = $this->_getMockCacheThatReturnsItem($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketStatus = $ticketManager->checkStatus($userId, $ticket);

        $this->assertSame(
            [
                'type' => TicketStatus::TYPE_UPDATE_TICKET,
                'ticket' => $cacheTicket,
                'playable' => true,
                'error' => null,
            ],
            $ticketStatus->toArray()
        );
    }

    /**
     * If checkStatus sees the same ticket in cache, it should return a simple
     * success.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::checkStatus
     * @covers ::_cacheStatus
     */
    public function checkStatusSameTicket()
    {
        $ticket = 'ticketkey';
        $cacheTicket = new ScaleEngineTicket(['key' => 'ticketkey']);
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientDoesNothing();
        $mockCacheItem = $this->_getMockCacheItemHits($cacheTicket);
        $mockCache = $this->_getMockCacheThatReturnsItem($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketStatus = $ticketManager->checkStatus($userId, $ticket);

        $this->assertSame(
            [
                'type' => TicketStatus::TYPE_SUCCESS,
                'ticket' => $cacheTicket,
                'playable' => true,
                'error' => null,
            ],
            $ticketStatus->toArray()
        );
    }

    /**
     * If bumpTicket fails to find a ticket, it should return an error.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::bumpTicket
     * @covers ::_cacheStatus
     */
    public function bumpTicketCacheMiss()
    {
        $ticket = 'ticketkey';
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientDoesNothing();
        $mockCacheItem = $this->_getMockCacheItemMisses();
        $mockCache = $this->_getMockCacheThatReturnsItem($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketStatus = $ticketManager->bumpTicket($userId, $ticket);

        $this->assertSame(
            [
                'type' => TicketStatus::TYPE_UNKNOWN_ERROR,
                'ticket' => null,
                'playable' => false,
                'error' => 'missing-ticket',
            ],
            $ticketStatus->toArray()
        );
    }

    /**
     * If bumpTicket finds a different ticket, it should update the user to use
     * that ticket since their old ticket has already been replaced.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::bumpTicket
     * @covers ::_cacheStatus
     */
    public function bumpTicketDifferentTicket()
    {
        $ticket = 'ticketkey';
        $cacheTicket = new ScaleEngineTicket(['key' => 'differentkey']);
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientDoesNothing();
        $mockCacheItem = $this->_getMockCacheItemHits($cacheTicket);
        $mockCache = $this->_getMockCacheThatReturnsItem($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketStatus = $ticketManager->bumpTicket($userId, $ticket);

        $this->assertSame(
            [
                'type' => TicketStatus::TYPE_UPDATE_TICKET,
                'ticket' => $cacheTicket,
                'playable' => true,
                'error' => null,
            ],
            $ticketStatus->toArray()
        );
    }

    /**
     * If bumpTicket is called with the existing ticket, it should proceed to
     * bump that ticket (revoke it) and replace it with a new one.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::bumpTicket
     * @covers ::_cacheStatus
     * @covers ::_replaceTicket
     * @covers ::_revokeTicket
     * @covers ::_requestTicket
     * @covers ::_generatePassword
     */
    public function bumpTicketSameTicket()
    {
        $ticket = 'ticketkey';
        $cacheTicket = new ScaleEngineTicket(['key' => 'ticketkey']);
        $newTicket = new ScaleEngineTicket(['key' => 'differentkey']);
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientForReplaceTicket($cacheTicket, $newTicket);
        $mockCacheItem = $this->_getMockCacheItemHitsAndWillBeSet($cacheTicket, $newTicket, new DateInterval('P1D'));
        $mockCache = $this->_getMockCacheThatReturnsItemThatWillBeSaved($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketStatus = $ticketManager->bumpTicket($userId, $ticket);

        $this->assertSame(
            [
                'type' => TicketStatus::TYPE_UPDATE_TICKET,
                'ticket' => $newTicket,
                'playable' => true,
                'error' => null,
            ],
            $ticketStatus->toArray()
        );
    }

    /**
     * If handleFailure fails to find a ticket, it should return an error.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::handleFailure
     * @covers ::_cacheStatus
     */
    public function handleFailureCacheMiss()
    {
        $ticket = 'ticketkey';
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientDoesNothing();
        $mockCacheItem = $this->_getMockCacheItemMisses();
        $mockCache = $this->_getMockCacheThatReturnsItem($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketStatus = $ticketManager->handleFailure($userId, $ticket, 'unknown-error', true);

        $this->assertSame(
            [
                'type' => TicketStatus::TYPE_UNKNOWN_ERROR,
                'ticket' => null,
                'playable' => false,
                'error' => 'missing-ticket',
            ],
            $ticketStatus->toArray()
        );
    }

    /**
     * If handleFailure finds a different ticket, it should update the user to use
     * that ticket since their old ticket has already been replaced.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::handleFailure
     * @covers ::_cacheStatus
     */
    public function handleFailureDifferentTicket()
    {
        $ticket = 'ticketkey';
        $cacheTicket = new ScaleEngineTicket(['key' => 'differentkey']);
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientDoesNothing();
        $mockCacheItem = $this->_getMockCacheItemHits($cacheTicket);
        $mockCache = $this->_getMockCacheThatReturnsItem($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketStatus = $ticketManager->handleFailure($userId, $ticket, 'unknown-error', true);

        $this->assertSame(
            [
                'type' => TicketStatus::TYPE_UPDATE_TICKET,
                'ticket' => $cacheTicket,
                'playable' => true,
                'error' => null,
            ],
            $ticketStatus->toArray()
        );
    }

    /**
     * If handleFailure is called with the cached ticket and it is expired, it
     * should bump that ticket (revoke it) and replace it with a new one.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::handleFailure
     * @covers ::_cacheStatus
     * @covers ::_replaceTicket
     * @covers ::_revokeTicket
     * @covers ::_requestTicket
     * @covers ::_generatePassword
     */
    public function handleFailureSameTicketExpired()
    {
        $ticket = 'ticketkey';
        $cacheTicket = new ScaleEngineTicket(['key' => 'ticketkey']);
        $newTicket = new ScaleEngineTicket(['key' => 'differentkey']);
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientForReplaceTicket($cacheTicket, $newTicket);
        $mockCacheItem = $this->_getMockCacheItemHitsAndWillBeSet($cacheTicket, $newTicket, new DateInterval('P1D'));
        $mockCache = $this->_getMockCacheThatReturnsItemThatWillBeSaved($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketStatus = $ticketManager->handleFailure(
            $userId,
            $ticket,
            ScaleEngineTicketManager::SCALEENGINE_FAILURE_EXPIRED,
            true
        );

        $this->assertSame(
            [
                'type' => TicketStatus::TYPE_UPDATE_TICKET,
                'ticket' => $newTicket,
                'playable' => true,
                'error' => null,
            ],
            $ticketStatus->toArray()
        );
    }

    /**
     * If handleFailure is called with the cached ticket and an invalid ip
     * error and a user with a single session, it should bump that ticket
     * (revoke it) and replace it * with a new one.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::handleFailure
     * @covers ::_cacheStatus
     * @covers ::_replaceTicket
     * @covers ::_revokeTicket
     * @covers ::_requestTicket
     * @covers ::_generatePassword
     */
    public function handleFailureSameTicketBadIpSingleSession()
    {
        $ticket = 'ticketkey';
        $cacheTicket = new ScaleEngineTicket(['key' => 'ticketkey']);
        $newTicket = new ScaleEngineTicket(['key' => 'differentkey']);
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientForReplaceTicket($cacheTicket, $newTicket);
        $mockCacheItem = $this->_getMockCacheItemHitsAndWillBeSet($cacheTicket, $newTicket, new DateInterval('P1D'));
        $mockCache = $this->_getMockCacheThatReturnsItemThatWillBeSaved($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketStatus = $ticketManager->handleFailure(
            $userId,
            $ticket,
            ScaleEngineTicketManager::SCALEENGINE_FAILURE_IP,
            true
        );

        $this->assertSame(
            [
                'type' => TicketStatus::TYPE_UPDATE_TICKET,
                'ticket' => $newTicket,
                'playable' => true,
                'error' => null,
            ],
            $ticketStatus->toArray()
        );
    }

    /**
     * If handleFailure is called with the cached ticket and an invalid ip
     * error and a user with multiple active sessions, it should return the ask
     * bump prompt before doing anything further.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::handleFailure
     * @covers ::_cacheStatus
     */
    public function handleFailureSameTicketBadIpMultipleSessions()
    {
        $ticket = 'ticketkey';
        $cacheTicket = new ScaleEngineTicket(['key' => 'ticketkey']);
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientDoesNothing();
        $mockCacheItem = $this->_getMockCacheItemHits($cacheTicket);
        $mockCache = $this->_getMockCacheThatReturnsItem($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketStatus = $ticketManager->handleFailure(
            $userId,
            $ticket,
            ScaleEngineTicketManager::SCALEENGINE_FAILURE_IP,
            false
        );

        $this->assertSame(
            [
                'type' => TicketStatus::TYPE_PROMPT_BUMP_EXISTING,
                'ticket' => $cacheTicket,
                'playable' => false,
                'error' => null,
            ],
            $ticketStatus->toArray()
        );
    }

    /**
     * If handleFailure is called with the cached ticket and an unhandled error
     * type, it should return an error.
     *
     * @test
     * @uses \FloSports\ScaleEngine\TicketStatus
     * @covers ::__construct
     * @covers ::handleFailure
     * @covers ::_cacheStatus
     */
    public function handleFailureSameTicketUnknownError()
    {
        $ticket = 'ticketkey';
        $cacheTicket = new ScaleEngineTicket(['key' => 'ticketkey']);
        $userId = 'userid';

        $mockScaleEngine = $this->_getMockScaleEngineClientDoesNothing();
        $mockCacheItem = $this->_getMockCacheItemHits($cacheTicket);
        $mockCache = $this->_getMockCacheThatReturnsItem($userId, $mockCacheItem);

        $ticketManager = new ScaleEngineTicketManager($mockScaleEngine, $mockCache);

        $ticketStatus = $ticketManager->handleFailure(
            $userId,
            $ticket,
            'some other error',
            false
        );

        $this->assertSame(
            [
                'type' => TicketStatus::TYPE_UNKNOWN_ERROR,
                'ticket' => $cacheTicket,
                'playable' => false,
                'error' => 'unknown-error',
            ],
            $ticketStatus->toArray()
        );
    }

    private function _getMockScaleEngineClientDoesNothing()
    {
        $mockScaleEngine = $this->getMockBuilder('\FloSports\ScaleEngine\ScaleEngineClient')
            ->setMethods([])
            ->disableOriginalConstructor()
            ->getMock();

        return $mockScaleEngine;
    }

    private function _getMockScaleEngineClientForReplaceTicket($oldTicket, $newTicket)
    {
        $requestData = $oldTicket->toArray();

        $mockScaleEngine = $this->getMockBuilder('\FloSports\ScaleEngine\ScaleEngineClient')
            ->setMethods(['getCommand', 'getOperation', 'getParams', 'revokeTicket', 'requestTicket'])
            ->disableOriginalConstructor()
            ->getMock();

        $mockScaleEngine
            ->expects($this->once())
            ->method('getCommand')
            ->with($this->equalTo('requestTicket'))
            ->will($this->returnSelf());
        $mockScaleEngine
            ->expects($this->once())
            ->method('getOperation')
            ->will($this->returnSelf());
        $mockScaleEngine
            ->expects($this->once())
            ->method('getParams')
            ->will($this->returnValue($requestData));
        $mockScaleEngine
            ->expects($this->once())
            ->method('requestTicket')
            ->with($this->equalTo($requestData))
            ->will($this->returnValue($newTicket));
        $mockScaleEngine
            ->expects($this->once())
            ->method('revokeTicket')
            ->with($this->equalTo($oldTicket))
            ->will($this->returnValue(null));

        return $mockScaleEngine;
    }

    private function _getMockScaleEngineClientForRequestTicket($ticket, $requestData)
    {
        $mockScaleEngine = $this->getMockBuilder('\FloSports\ScaleEngine\ScaleEngineClient')
            ->setMethods(['getCommand', 'getOperation', 'getParams', 'requestTicket'])
            ->disableOriginalConstructor()
            ->getMock();
        $mockScaleEngine
            ->expects($this->once())
            ->method('getCommand')
            ->with($this->equalTo('requestTicket'))
            ->will($this->returnSelf());
        $mockScaleEngine
            ->expects($this->once())
            ->method('getOperation')
            ->will($this->returnSelf());
        $mockScaleEngine
            ->expects($this->once())
            ->method('getParams')
            ->will($this->returnValue($requestData));
        $mockScaleEngine
            ->expects($this->once())
            ->method('requestTicket')
            ->with($this->equalTo($requestData))
            ->will($this->returnValue($ticket));

        return $mockScaleEngine;
    }

    private function _getMockScaleEngineClientFails($requestData)
    {
        $mockScaleEngine = $this->getMockBuilder('\FloSports\ScaleEngine\ScaleEngineClient')
            ->setMethods(['getCommand', 'getOperation', 'getParams', 'requestTicket'])
            ->disableOriginalConstructor()
            ->getMock();
        $mockScaleEngine
            ->expects($this->once())
            ->method('getCommand')
            ->with($this->equalTo('requestTicket'))
            ->will($this->returnSelf());
        $mockScaleEngine
            ->expects($this->once())
            ->method('getOperation')
            ->will($this->returnSelf());
        $mockScaleEngine
            ->expects($this->once())
            ->method('getParams')
            ->will($this->returnValue($requestData));
        $mockScaleEngine
            ->expects($this->once())
            ->method('requestTicket')
            ->with($this->equalTo($requestData))
            ->will($this->throwException(new Exception('It failed')));

        return $mockScaleEngine;
    }

    private function _getMockCacheItemMissesAndWillBeSet($dataToSet, $expirationPeriod)
    {
        $mockItem = $this->getMockBuilder('\Psr\Cache\CacheItemInterface')
            ->setMethods(['isHit', 'set', 'expiresAfter'])
            ->getMockForAbstractClass();

        $mockItem
            ->expects($this->any())
            ->method('isHit')
            ->will($this->returnValue(false));
        $mockItem
            ->expects($this->once())
            ->method('set')
            ->with($this->equalTo($dataToSet))
            ->will($this->returnSelf());
        $mockItem
            ->expects($this->once())
            ->method('expiresAfter')
            ->with($this->equalTo($expirationPeriod))
            ->will($this->returnSelf());

        return $mockItem;
    }

    private function _getMockCacheItemMisses()
    {
        $mockItem = $this->getMockBuilder('\Psr\Cache\CacheItemInterface')
            ->setMethods(['isHit'])
            ->getMockForAbstractClass();

        $mockItem
            ->expects($this->any())
            ->method('isHit')
            ->will($this->returnValue(false));

        return $mockItem;
    }

    private function _getMockCacheItemHitsAndWillBeSet($data, $dataToSet, $expirationPeriod)
    {
        $mockItem = $this->getMockBuilder('\Psr\Cache\CacheItemInterface')
            ->setMethods(['isHit', 'get', 'set', 'expiresAfter'])
            ->getMockForAbstractClass();

        $mockItem
            ->expects($this->any())
            ->method('isHit')
            ->will($this->returnValue(true));
        $mockItem
            ->expects($this->any())
            ->method('get')
            ->will($this->returnValue($data));
        $mockItem
            ->expects($this->once())
            ->method('set')
            ->with($this->equalTo($dataToSet))
            ->will($this->returnSelf());
        $mockItem
            ->expects($this->once())
            ->method('expiresAfter')
            ->with($this->equalTo($expirationPeriod))
            ->will($this->returnSelf());

        return $mockItem;
    }

    private function _getMockCacheItemHits($data)
    {
        $mockItem = $this->getMockBuilder('\Psr\Cache\CacheItemInterface')
            ->setMethods(['isHit', 'get'])
            ->getMockForAbstractClass();

        $mockItem
            ->expects($this->any())
            ->method('isHit')
            ->will($this->returnValue(true));
        $mockItem
            ->expects($this->any())
            ->method('get')
            ->will($this->returnValue($data));

        return $mockItem;
    }

    private function _getMockCacheThatReturnsItem($cacheKey, $cacheItem)
    {
        $mockCache = $this->getMockBuilder('\Psr\Cache\CacheItemPoolInterface')
            ->setMethods(['getItem'])
            ->getMockForAbstractClass();

        $mockCache
            ->expects($this->once())
            ->method('getItem')
            ->with($this->equalTo($cacheKey))
            ->will($this->returnValue($cacheItem));

        return $mockCache;
    }

    private function _getMockCacheThatReturnsItemThatWillBeSaved($cacheKey, $cacheItem, $saveResult = true)
    {
        $mockCache = $this->getMockBuilder('\Psr\Cache\CacheItemPoolInterface')
            ->setMethods(['getItem', 'save'])
            ->getMockForAbstractClass();

        $mockCache
            ->expects($this->once())
            ->method('getItem')
            ->with($this->equalTo($cacheKey))
            ->will($this->returnValue($cacheItem));
        $mockCache
            ->expects($this->once())
            ->method('save')
            ->with($this->equalTo($cacheItem))
            ->will($this->returnValue($saveResult));

        return $mockCache;
    }
}
