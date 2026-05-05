<?php

namespace Nawasara\Whm\Exceptions;

/**
 * Mailbox atau parent cPanel account sedang suspended — block session
 * launch sampai admin unsuspend, biar user dapat error message yang
 * actionable bukan "login berhasil tapi inbox kosong".
 */
class MailboxSuspendedException extends WebmailSessionException
{
}
