<?php
declare(strict_types=1);

namespace Tablo;

final class SessionResumption
{
    public static function resume(SessionAdmission $admission, SessionSnapshot $snapshot): ?\Phalcon\Http\Response
    {
        // Native strict mode rereads the original ID. Deleted IDs become anonymous successors.
        $_SESSION = [];
        session_id($snapshot->id);
        // A late request may read the old ID, but must never replace a newer browser cookie
        // or overwrite the response's cache policy. These options are request-local.
        if (!session_start(['use_cookies' => false, 'cache_limiter' => ''])) { throw new \RuntimeException('Cannot reopen session.'); }
        if (!$snapshot->matches()) { return $admission->refuse(); }
        $admission->revisit();
        return $snapshot->matches() ? null : $admission->refuse();
    }
}
