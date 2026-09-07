<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use RuntimeException;
use Throwable;

/**
 * A JSON-RPC call to the XRPL node failed — transport error, non-2xx
 * status, malformed body, or an embedded RPC error. Distinct from a normal
 * empty/not-found result (see XrplClient) so a caller can tell "the call
 * failed" apart from "there's genuinely nothing there."
 */
final class XrplRpcException extends RuntimeException
{
    /**
     * @param string|null $error rippled's own error code when the node
     *     returned one (e.g. 'lgrIdxsInvalid'), null for transport-level
     *     failures. Exposed so a caller can react to a *specific*
     *     recoverable condition without matching on message text —
     *     SyncService uses it to recognise a cursor left behind by a
     *     testnet reset.
     */
    public function __construct(
        string $message,
        public readonly ?string $error = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
