<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use RuntimeException;

/**
 * A JSON-RPC call to the XRPL node failed — transport error, non-2xx
 * status, malformed body, or an embedded RPC error. Distinct from a normal
 * empty/not-found result (see XrplClient) so a caller can tell "the call
 * failed" apart from "there's genuinely nothing there."
 */
final class XrplRpcException extends RuntimeException
{
}
