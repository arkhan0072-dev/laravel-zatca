<?php

namespace Arkhan\Zatca\Exceptions;

use RuntimeException;

/** ZATCA could not take the invoice right now; it stays pending and can be sent again. */
class SubmissionException extends RuntimeException {}
