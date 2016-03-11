<?php
namespace FloSports\ScaleEngine;

use FloSports\ScaleEngine\Model\ScaleEngineTicket;

/**
 * Represents the status of a ticket for use by an end user.
 *
 * This status tells the client what actions to take based on the current state
 * of a ticket.
 */
class TicketStatus
{
    /** @type string The client should prompt to bump existing sessions. **/
    const TYPE_PROMPT_BUMP_EXISTING = 'bump-existing';

    /** @type string The client should continue playing without change. **/
    const TYPE_SUCCESS = 'success';

    /** @type string The client should stop playing and display an error. **/
    const TYPE_UNKNOWN_ERROR = 'unknown-error';

    /** @type string The client should resume playing using the new ticket. **/
    const TYPE_UPDATE_TICKET = 'update-ticket';

    /** @type string The error code. **/
    private $_error;

    /** @type boolean Whether the ticket should be playable. **/
    private $_playable;

    /** @type ScaleEngineTicket The ticket being returned to the client. **/
    private $_ticket;

    /** @type string The status of the ticket based on types above. **/
    private $_type;

    /**
     * Create a TicketStatus entity for returning to the client.
     *
     * @param string $type The type of response this is.
     * @param ScaleEngineTicket $ticket The ticket to return.
     * @param boolean $playable Whether the returned ticket should be playable.
     * @param string $error The error code if there is one.
     */
    public function __construct($type, ScaleEngineTicket $ticket = null, $playable = false, $error = null)
    {
        $this->_type = $type;
        $this->_ticket = $ticket;
        $this->_playable = $playable;
        $this->_error = $error;
    }

    /**
     * Convert the ticket status into an array for inspection or serialization.
     *
     * @return array The ticket status serialized to an array.
     */
    public function toArray()
    {
        return [
            'type' => $this->_type,
            'ticket' => $this->_ticket,
            'playable' => $this->_playable,
            'error' => $this->_error,
        ];
    }
}
