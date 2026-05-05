<?php

namespace Nawasara\Whm\Exceptions;

/**
 * Email account tidak terdaftar di WHM — biasanya berarti mapping di
 * Nawasara stale (mailbox di-delete dari cPanel tapi link masih ada).
 */
class MailboxNotFoundException extends WebmailSessionException
{
}
