<?php
namespace FloSports\ScaleEngine;

use DateInterval;
use DateTime;
use DateTimeZone;
use Exception;
use FloSports\ScaleEngine\Model\ScaleEngineTicket;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Manages the cache of ScaleEngine tickets for a user.
 *
 * This is a cooperative library that helps ensure there is a 1->1 mapping
 * between active tickets and users.  The front-end streaming player needs to
 * make appropriate calls here to get tickets and validate statuses.
 *
 * On initialization of the player, it should call `getTicket` to get a valid
 * ticket for the user.  Periodically, the player should call `checkStatus` to
 * ensure that the ticket is still valid and update if not.  `handleFailure`
 * should be called whenever ScaleEngine rejects a play attempt and this
 * library will determine what the next action should be.  Finally, if a user
 * needs to bump existing sessions (after given a `TYPE_PROMPT_BUMP_EXISTING`
 * response), the `bumpTicket` method should be used to generate a new ticket.
 */
class ScaleEngineTicketManager
{
    /** @type string The message ScaleEngine returns for invalid ips. **/
    const SCALEENGINE_FAILURE_IP = 'Invalid IP Address';

    /** @type string The message ScaleEngine returns for expired tickets. **/
    const SCALEENGINE_FAILURE_EXPIRED = 'Expiration date surpassed';

    /** @type ScaleEngineClient The API client for ScaleEngine. **/
    private $_scaleEngine;

    /** @type CacheItemPoolInterface The cache to map users to tickets. **/
    private $_cache;

    /** @type array Configuration options for the ticket manager. **/
    private $_options;

    /**
     * Create the ticket manager.
     *
     * @param ScaleEngineClient $scaleEngine The ScaleEngine API client.
     * @param CacheItemPoolInterface $cache The cache to use to map users to
     *     tickets.
     * @param array $options {
     *     @var string defaultIp The default ip subnet to use when creating
     *         tickets.  Defaults to `auto/24`.
     *     @var DateInterval expirationPeriod How long before tickets should be
     *         expired in ScaleEngine and in the cache.  Defaults to 1 day.
     *     @var callable passwordGenerator A function that returns a random
     *         password to be used with tickets.  Defaults to
     *         ScaleEngineTicketManager::_generatePassword.
     * }
     */
    public function __construct(ScaleEngineClient $scaleEngine, CacheItemPoolInterface $cache, array $options = [])
    {
        // FIXME: add logger parameter to be used for actions this takes.
        $this->_scaleEngine = $scaleEngine;
        $this->_cache = $cache;

        $defaultOptions = [
            'defaultIp' => 'auto/24',
            'expirationPeriod' => new DateInterval('P1D'),
            'passwordGenerator' => [$this, '_generatePassword'],
        ];
        $this->_options = $options + $defaultOptions;
    }

    /**
     * Gets a ticket for the given user, requesting one from ScaleEngine if
     * one isn't found in the cache.
     *
     * This is the expected method for getting a ticket for a user whether they
     * already have one or are trying to get an initial ticket.
     *
     * Ticket expiration and revocation is not considered by this method.  If a
     * ticket is no longer valid it may still be returned here and given to the
     * user to attempt to use.  In that case, ScaleEngine should reject the
     * request and the below `handleFailure` method should be used to resolve
     * the ticket failure and handle the next action (to either get a new
     * ticket or direct the user to do what they need to).
     *
     * @param string $user The user/owner identifier.  This could be anything,
     *     but there is a cache-enforced 1->1 mapping of this identifier to a
     *     ticket in ScaleEngine.
     * @param array $requestData The data to pass to ScaleEngine's
     *     `RequestTicket` API call if the ticket is not found in cache and
     *     must be created fresh.
     * @return TicketStatus The status of the ticket indicating what actions
     *     the client should take.
     * @throws Exception If the cache or the API throw exceptions, ScaleEngine
     *     does not return a valid ticket, or the ticket fails to be cached, an
     *     Exception will be thrown.  Because we want an enforced 1->1 mapping
     *     of user->ticket, we don't want to return tickets that aren't saved
     *     in the cache.
     */
    public function getTicket($user, array $requestData)
    {
        $cacheEntry = $this->_cache->getItem($user);
        if ($cacheEntry->isHit()) {
            return new TicketStatus(TicketStatus::TYPE_UPDATE_TICKET, $cacheEntry->get(), true);
        }

        return $this->_replaceTicket($cacheEntry, $requestData);
    }

    /**
     * Checks the status of the ticket to see if it is still valid.
     *
     * If the cache no longer has a ticket for the given user, this will return
     * an unknown error.  Nothing should be removing the ticket without
     * replacing it with a newer one.
     *
     * If the cached ticket doesn't match the given ticket, this will return
     * the cached ticket to be used instead.  This can happen when a new ticket
     * was created for another session the user has.
     *
     * If the cached ticket matches the given ticket, then this just returns a
     * simple success response indicating to continue using the ticket.
     *
     * @param string $user The user/owner identifier.  This could be anything,
     *     but there is a cache-enforced 1->1 mapping of this identifier to a
     *     ticket in ScaleEngine.
     * @param string $ticket The ticket the user is currently using.  This will
     *     be checked against the cache to validate whether the ticket should
     *     still be used.
     * @return TicketStatus The status of the ticket indicating what actions
     *     the client should take.
     */
    public function checkStatus($user, $ticket)
    {
        $cacheEntry = $this->_cache->getItem($user);
        $result = null;

        switch ($this->_cacheStatus($cacheEntry, $ticket)) {
        case 'cache-miss':
            $result = new TicketStatus(TicketStatus::TYPE_UNKNOWN_ERROR, null, false, 'missing-ticket');
            break;
        case 'different-ticket':
            $result = new TicketStatus(TicketStatus::TYPE_UPDATE_TICKET, $cacheEntry->get(), true);
            break;
        case 'same-ticket':
            $result = new TicketStatus(TicketStatus::TYPE_SUCCESS, $cacheEntry->get(), true);
            break;
        }

        return $result;
    }

    /**
     * Bumps the current ticket while issuing a new one to be used.
     *
     * If the cache no longer has a ticket for the given user, this will return
     * an unknown error.  Nothing should be removing the ticket without
     * replacing it with a newer one.
     *
     * If the cached ticket doesn't match the given ticket, this will return
     * the cached ticket to be used instead.  This can happen when a new ticket
     * was created for another session the user has.
     *
     * If the cached ticket matches the given ticket, then this just returns a
     * simple success response indicating to continue using the ticket.
     *
     * @param string $user The user/owner identifier.  This could be anything,
     *     but there is a cache-enforced 1->1 mapping of this identifier to a
     *     ticket in ScaleEngine.
     * @param string $ticket The ticket the user is currently using.  This will
     *     be checked against the cache to validate whether the ticket should
     *     still be used.
     * @return TicketStatus The status of the ticket indicating what actions
     *     the client should take.
     */
    public function bumpTicket($user, $ticket)
    {
        $cacheEntry = $this->_cache->getItem($user);
        $result = null;

        switch ($this->_cacheStatus($cacheEntry, $ticket)) {
        case 'cache-miss':
            $result = new TicketStatus(TicketStatus::TYPE_UNKNOWN_ERROR, null, false, 'missing-ticket');
            break;
        case 'different-ticket':
            $result = new TicketStatus(TicketStatus::TYPE_UPDATE_TICKET, $cacheEntry->get(), true);
            break;
        case 'same-ticket':
            $result = $this->_replaceTicket($cacheEntry);
            break;
        }

        return $result;
    }

    /**
     * Handles a failure in playing using a ticket, and attempts to recover.
     *
     * If the cache no longer has a ticket for the given user, this will return
     * an unknown error.  Nothing should be removing the ticket without
     * replacing it with a newer one.
     *
     * If the cached ticket doesn't match the given ticket, this will return
     * the cached ticket to be used instead.  This can happen when a new ticket
     * was created for another session the user has.
     *
     * If the cached ticket matches the given ticket, then the error is
     * inspected.  Expired tickets will be renewed and the new ticket will be
     * returned to be used.  If the play attempt failed because of an invalid
     * ip for the ticket then it depends on whether the user only has the one
     * session or is watching on multiples: if this is their only session, then
     * it will generate a new ticket for them and return it (this can happen if
     * their ip changes during a session, or when they end a session and start
     * a new one); if they have multiple sessions, then this will return a
     * prompt asking them if they want to bump the other session(s) (this can
     * happen if they are trying to watch it concurrently from different ip
     * ranges).  Other error codes result in an unknown error response.
     *
     * @param string $user The user/owner identifier.  This could be anything,
     *     but there is a cache-enforced 1->1 mapping of this identifier to a
     *     ticket in ScaleEngine.
     * @param string $ticket The ticket the user is currently using.  This will
     *     be checked against the cache to validate whether the ticket should
     *     still be used.
     * @param string $errorCode The reason ScaleEngine gave for disallowing the
     *     play attempt.
     * @param boolean $hasSingleSession Whether the user only has a single
     *     session or whether they have multiple active sessions.  If a ticket
     *     failed because of a bad ip, users that only have a single active
     *     session will have their ticket replaced silently rather than
     *     prompting them to bump other sessions.
     * @return TicketStatus The status of the ticket indicating what actions
     *     the client should take.
     */
    public function handleFailure($user, $ticket, $errorCode, $hasSingleSession)
    {
        $cacheEntry = $this->_cache->getItem($user);
        $result = null;

        switch ($this->_cacheStatus($cacheEntry, $ticket)) {
        case 'cache-miss':
            $result = new TicketStatus(TicketStatus::TYPE_UNKNOWN_ERROR, null, false, 'missing-ticket');
            break;
        case 'different-ticket':
            $result = new TicketStatus(TicketStatus::TYPE_UPDATE_TICKET, $cacheEntry->get(), true);
            break;
        case 'same-ticket':
            if ($errorCode === self::SCALEENGINE_FAILURE_EXPIRED) {
                $result = $this->_replaceTicket($cacheEntry);
            } else if ($errorCode === self::SCALEENGINE_FAILURE_IP) {
                if ($hasSingleSession) {
                    $result = $this->_replaceTicket($cacheEntry);
                } else {
                    $result = new TicketStatus(TicketStatus::TYPE_PROMPT_BUMP_EXISTING, $cacheEntry->get(), false);
                }
            } else {
                $result = new TicketStatus(TicketStatus::TYPE_UNKNOWN_ERROR, $cacheEntry->get(), false, 'unknown-error');
            }

            break;
        }

        return $result;
    }

    /**
     * Replaces the cached ticket with a new one requested from ScaleEngine.
     *
     * If the request data is not given, then it will attempt to use the data
     * used to create the ticket in the cache.
     *
     * If an existing ticket is in the cache entry, it will be revoked from
     * ScaleEngine before the new ticket is created.
     *
     * This will save the cache entry with the newly created ticket.
     *
     * @param CacheItemInterface $cacheEntry The cached ticket result (may be a
     *     hit or a miss).
     * @param array $requestData The data to use when requesting a ticket from
     *     ScaleEngine.
     * @return TicketStatus The ticket requested from ScaleEngine that has
     *     already been saved to the cache entry.
     */
    private function _replaceTicket(CacheItemInterface $cacheEntry, array $requestData = null)
    {
        if ($cacheEntry->isHit()) {
            $existingTicket = $cacheEntry->get();
            $this->_revokeTicket($existingTicket);

            if (!$requestData) {
                $requestData = $existingTicket->toArray();
                unset($requestData['expires'], $requestData['pass']);
            }
        }

        $ticket = $this->_requestTicket($requestData);

        $cacheEntry->set($ticket)->expiresAfter($this->_options['expirationPeriod']);
        if (!$this->_cache->save($cacheEntry)) {
            throw new Exception('Failed to cache the ticket for a user.');
        }

        return new TicketStatus(TicketStatus::TYPE_UPDATE_TICKET, $ticket, true);
    }

    /**
     * Request a ticket from ScaleEngine.
     *
     * This will set some fields to default/computed values if they aren't set
     * in the given data.  `expires` will be set to 1 day in the future, `ip`
     * will be set to `auto/24`, and `pass` will be set to a random md5 hash,
     * although these options can be configured via the options passed to the
     * constructor.
     *
     * This does not interact with the cache at all.
     *
     * @param array $requestData The request data to be sent to the
     *     RequestTicket API call.  Some fields will be defaulted as described
     *     above.  Any unexpected fields will be removed.
     * @return ScaleEngineTicket The created ticket.
     */
    private function _requestTicket(array $requestData)
    {
        if (empty($requestData)) {
            throw new Exception('Missing data necessary to create a ticket.');
        }

        $now = (new DateTime())
            ->setTimezone(new DateTimeZone(ScaleEngineClient::SCALEENGINE_TIMEZONE));
        $expires = $now->add($this->_options['expirationPeriod']);
        $defaults = [
            'expires' => $expires->format(ScaleEngineClient::SCALEENGINE_DATE_FORMAT),
            'ip' => $this->_options['defaultIp'],
            'pass' => $this->_options['passwordGenerator'](),
        ];

        $requestData += $defaults;
        $requestData = array_intersect_key(
            $requestData,
            $this->_scaleEngine->getCommand('requestTicket')->getOperation()->getParams()
        );

        return $this->_scaleEngine->requestTicket($requestData);
    }

    /**
     * Revokes the given ticket in ScaleEngine.
     *
     * This does not remove the ticket from the cache.
     *
     * @param ScaleEngineTicket $ticket The ticket to revoke.
     * @return void
     */
    private function _revokeTicket(ScaleEngineTicket $ticket)
    {
        $this->_scaleEngine->revokeTicket($ticket);
    }

    /**
     * Checks the given ticket against the cached ticket to see if it is the
     * same.
     *
     * @param \Psr\Cache\CacheItemInterface $cacheEntry The cache entry for the
     *     existing ticket.
     * @param string $usedTicket The ticket currently being used.
     * @return string The status of the used ticket - one of `cache-miss`,
     *     `same-ticket`, or `different-ticket`.
     */
    private function _cacheStatus(CacheItemInterface $cacheEntry, $usedTicket)
    {
        if (!$cacheEntry->isHit()) {
            return 'cache-miss';
        }

        $cachedTicket = $cacheEntry->get();
        return $cachedTicket['key'] === $usedTicket ? 'same-ticket' : 'different-ticket';
    }

    /**
     * Generates a random password to be used for a ticket.
     *
     * @return string A random password.
     */
    private function _generatePassword()
    {
        return md5(openssl_random_pseudo_bytes(64));
    }
}
