<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Enums;

enum ViolationReason: string
{
    /** Sequence numbers are not contiguous: events were deleted or inserted out of band. */
    case SequenceGap = 'sequence_gap';

    /** The stored previous_hash does not equal the predecessor's event_hash. */
    case PreviousHashMismatch = 'previous_hash_mismatch';

    /** Recomputing the event hash from the stored columns gives a different value. */
    case EventHashMismatch = 'event_hash_mismatch';

    /** The HMAC signature does not verify with the referenced key. */
    case SignatureInvalid = 'signature_invalid';

    /** The event references a signing key that is not configured. */
    case SignatureKeyUnknown = 'signature_key_unknown';

    /** The event has no signature although signing is configured. */
    case SignatureMissing = 'signature_missing';

    /** The chain head row disagrees with the last event (tail deleted or head rewritten). */
    case HeadMismatch = 'head_mismatch';

    /** An external anchor points at a different hash for the same sequence. */
    case AnchorMismatch = 'anchor_mismatch';

    /** An external anchor references a sequence beyond the last event: the tail was removed. */
    case AnchorAhead = 'anchor_ahead';

    /** The event uses a hash version this verifier does not know. */
    case UnsupportedHashVersion = 'unsupported_hash_version';

    /** Verification could not start because the predecessor of the requested range is missing. */
    case MissingPredecessor = 'missing_predecessor';
}
